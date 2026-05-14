<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SipTrace extends AbstractTool {
	public function name() { return 'fm_sip_trace'; }
	public function description() { return 'Capture a time-bounded SIP trace via AMI. Params: action (required: "start", "stop", "status"), duration (seconds, default 10, max 30). Start begins capture, stop ends and returns captured data. Auto-stops after duration.'; }

	public function permissionLevel() { return self::PERM_ADMIN; }

	public function validate($params) {
		if (empty($params['action'])) return 'Parameter "action" is required (start, stop, or status)';
		if (!in_array($params['action'], ['start', 'stop', 'status'])) return 'Parameter "action" must be start, stop, or status';
		if (isset($params['duration'])) {
			$d = (int)$params['duration'];
			if ($d < 1 || $d > 30) return 'Parameter "duration" must be between 1 and 30 seconds';
		}
		return true;
	}

	/**
	 * Is a trace currently active? Reads the meta file and compares its expiry to now.
	 * Stale-meta defense: any expiry more than 60s in the future is treated as not running
	 * (the duration cap is 30s, so anything beyond a minute is corrupt or an old install).
	 */
	private function isTraceRunning($traceFile) {
		$metaPath = $traceFile . '.meta';
		if (!file_exists($metaPath)) return false;
		$meta = @json_decode((string)@file_get_contents($metaPath), true);
		if (!is_array($meta) || empty($meta['expiry'])) return false;
		$expiry = (int)$meta['expiry'];
		$now = time();
		if ($expiry - $now > 60) return false;
		return $now < $expiry;
	}

	/**
	 * Stop logic factored out so `start force:true` can run it before launching a new trace.
	 * Mirrors the stop action body but skips the AMI logger toggle when called from start —
	 * the new start will re-enable the logger.
	 */
	private function stopTraceProcess($traceFile, $astman, $disableLogger = true) {
		if ($disableLogger && $astman && $astman->connected()) {
			$astman->Command('pjsip set logger off');
		}
		$pgidFile = $traceFile . '.pgid';
		if (file_exists($pgidFile)) {
			$pgid = trim((string)@file_get_contents($pgidFile));
			if ($pgid !== '' && ctype_digit($pgid)) {
				exec('kill -TERM -' . (int)$pgid . ' 2>/dev/null');
			}
			@unlink($pgidFile);
		}
		exec('pkill -f "frogman_sip_trace" 2>/dev/null');
		@unlink($traceFile . '.meta');
		@unlink($traceFile);
	}

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$action = $params['action'];
		$traceFile = '/tmp/frogman_sip_trace.log';

		if ($action === 'start') {
			$duration = isset($params['duration']) ? min((int)$params['duration'], 30) : 10;

			// Refuse if a trace is already running. Two concurrent traces would capture
			// IDENTICAL data anyway (both tail the same Asterisk log file with the same
			// filter), so there's no legitimate parallel-trace use case — only "I forgot
			// I was already tracing" accidents and "two admins overlapping" coordination
			// gaps. Both want the same answer: tell the caller what's running and let
			// them choose.
			if ($this->isTraceRunning($traceFile)) {
				$force = !empty($params['force']) && $params['force'] === true;
				$meta = @json_decode((string)@file_get_contents($traceFile . '.meta'), true);
				$remaining = is_array($meta) && !empty($meta['expiry']) ? max(0, (int)$meta['expiry'] - time()) : 0;
				if (!$force) {
					return [
						'status' => 'refused',
						'reason' => 'trace_already_running',
						'expires_in_seconds' => $remaining,
						'message' => "A SIP trace is already running, expires in {$remaining}s. Wait for it to finish, run `stop sip trace` to take over, or pass `force: true` to replace it.",
					];
				}
				// force=true: stop the running trace before starting fresh.
				$this->stopTraceProcess($traceFile, $astman, false);
			}

			// Clear any previous trace
			@file_put_contents($traceFile, '');

			// Enable PJSIP logger via AMI — output goes to Asterisk full log
			$astman->Command('pjsip set logger on');

			// Schedule auto-stop: write a marker file with expiry timestamp
			$expiry = time() + $duration;
			@file_put_contents($traceFile . '.meta', json_encode([
				'started' => time(),
				'duration' => $duration,
				'expiry' => $expiry,
			]));

			// Capture from Asterisk log. Critical: must `tail -F` so we follow lines appended
			// AFTER capture starts; a plain grep over the file finishes in milliseconds and
			// captures only historical content (which is what was making this tool look broken —
			// "0 data" on a call placed during the window). -n 0 starts at current EOF so we
			// only see lines from the trace window.
			$logFile = '/var/log/asterisk/full';
			$pattern = 'PJSIP|SIP/2\\.0|sip:|CSeq|Via:|From:|To:|Call-ID|INVITE|BYE|REGISTER|ACK|CANCEL|OPTIONS';
			$pgidFile = $traceFile . '.pgid';
			@unlink($pgidFile);

			// setsid creates a new process group with the shell as leader. We record that
			// pgid so stop can kill the whole group (tail + grep + timeout) — pkill -f
			// only matches the sh wrapper because the redirect path doesn't appear in
			// tail's or grep's argv, leaving them as orphans otherwise.
			// --line-buffered is critical: without it, grep block-buffers (~4KB) when stdout
			// is a file, so a short trace flushes nothing before timeout kills it.
			$inner = sprintf(
				'tail -n 0 -F %s | grep -a --line-buffered -E %s > %s 2>/dev/null',
				escapeshellarg($logFile),
				escapeshellarg($pattern),
				escapeshellarg($traceFile)
			);
			$cmd = sprintf(
				'setsid sh -c %s >/dev/null 2>&1 & echo $! > %s',
				escapeshellarg(sprintf('exec timeout %d sh -c %s', $duration + 2, escapeshellarg($inner))),
				escapeshellarg($pgidFile)
			);
			exec($cmd);

			return [
				'status' => 'started',
				'duration' => $duration,
				'auto_stop_at' => date('Y-m-d H:i:s', $expiry),
				'note' => "Trace running for {$duration}s. Use action=stop to retrieve results, or wait for auto-stop.",
			];
		}

		if ($action === 'stop') {
			// Disable PJSIP logger
			$astman->Command('pjsip set logger off');

			// Kill the capture process group (setsid leader recorded at start). The negative
			// PID in `kill -- -<pgid>` signals every process in the group, so tail and grep
			// die alongside the wrapper instead of being orphaned.
			$pgidFile = $traceFile . '.pgid';
			if (file_exists($pgidFile)) {
				$pgid = trim((string)@file_get_contents($pgidFile));
				if ($pgid !== '' && ctype_digit($pgid)) {
					exec('kill -TERM -' . (int)$pgid . ' 2>/dev/null');
				}
				@unlink($pgidFile);
			}
			// Belt-and-suspenders: clean up anything lingering that targets our trace file.
			exec('pkill -f "frogman_sip_trace" 2>/dev/null');

			// Read captured data
			$traceData = '';
			if (file_exists($traceFile)) {
				$traceData = @file_get_contents($traceFile);
				// Cap output at 50KB to avoid flooding chat
				if (strlen($traceData) > 50000) {
					$traceData = substr($traceData, 0, 50000) . "\n\n... [TRUNCATED — trace exceeded 50KB] ...";
				}
			}

			$meta = null;
			if (file_exists($traceFile . '.meta')) {
				$meta = json_decode(@file_get_contents($traceFile . '.meta'), true);
				@unlink($traceFile . '.meta');
			}

			// Parse SIP messages from trace
			$messages = $this->parseSipMessages($traceData);

			// Cleanup
			@unlink($traceFile);

			return [
				'status' => 'stopped',
				'meta' => $meta,
				'message_count' => count($messages),
				'messages' => $messages,
				'raw_size_bytes' => strlen($traceData),
			];
		}

		if ($action === 'status') {
			$meta = null;
			$running = false;
			if (file_exists($traceFile . '.meta')) {
				$meta = json_decode(@file_get_contents($traceFile . '.meta'), true);
				$running = $meta && time() < $meta['expiry'];
			}

			$currentSize = file_exists($traceFile) ? filesize($traceFile) : 0;

			return [
				'running' => $running,
				'meta' => $meta,
				'capture_size_bytes' => $currentSize,
			];
		}
	}

	private function parseSipMessages($data) {
		if (empty($data)) return [];

		$messages = [];
		$lines = explode("\n", $data);
		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line)) continue;

			// Look for SIP request/response lines
			if (preg_match('/(INVITE|BYE|REGISTER|ACK|CANCEL|OPTIONS|NOTIFY|SUBSCRIBE|REFER|INFO|UPDATE|PRACK)\s+sip:/i', $line, $m)) {
				$messages[] = ['type' => 'request', 'method' => strtoupper($m[1]), 'line' => $line];
			} elseif (preg_match('/SIP\/2\.0\s+(\d{3})\s+(.+)/i', $line, $m)) {
				$messages[] = ['type' => 'response', 'code' => $m[1], 'reason' => trim($m[2]), 'line' => $line];
			}
		}

		// Cap parsed messages at 200
		if (count($messages) > 200) {
			$messages = array_slice($messages, 0, 200);
			$messages[] = ['type' => 'notice', 'line' => '... truncated at 200 messages'];
		}

		return $messages;
	}
}
