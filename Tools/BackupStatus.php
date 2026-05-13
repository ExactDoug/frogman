<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class BackupStatus extends AbstractTool {
	// Grace window after a scheduled tick before declaring a run "missed".
	const MISSED_GRACE_SECONDS = 3600;

	// Status values from the Backup module's runningBackupstatus kvstore entries.
	// FINISHED = successful run. Anything else with a recent timestamp = in-flight or failed.
	const STATUS_FINISHED = 'FINISHED';

	public function name() { return 'fm_backup_status'; }

	public function description() {
		return 'Show backup job health: last successful run, in-flight runs, next scheduled run, and missed-run detection. Optional params: job_id, job_name (substring match).';
	}

	public function validate($params) { return true; }

	public function requiredPermission() { return null; }

	// Output includes filesystem paths to backup artifacts (e.g. /var/spool/asterisk/...),
	// transaction UUIDs that index the kvstore, and per-run timing metadata — operational
	// internals that read-tier callers shouldn't see.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$jobIdFilter = $params['job_id'] ?? null;
		$jobNameFilter = isset($params['job_name']) ? strtolower(trim((string)$params['job_name'])) : null;

		$index = $this->freepbx->Backup->listBackups() ?: [];
		$runHistory = $this->collectRunHistory();

		$jobs = [];
		$summary = ['total' => 0, 'scheduled' => 0, 'missed' => 0, 'in_flight' => 0];
		$now = time();

		foreach ($index as $row) {
			$id = $row['id'] ?? null;
			if (!$id) continue;
			if ($jobIdFilter && $id !== $jobIdFilter) continue;

			$cfg = $this->freepbx->Backup->getBackup($id) ?: [];
			$name = $cfg['backup_name'] ?? $row['name'] ?? 'unnamed';

			if ($jobNameFilter && strpos(strtolower($name), $jobNameFilter) === false) continue;

			$cron = (string)($cfg['backup_schedule'] ?? '');
			$scheduleEnabled = $this->boolish($cfg['schedule_enabled'] ?? false);
			$entries = $runHistory[$id] ?? [];

			$lastSuccess = null;
			$inFlight = null;
			$lastRun = null;
			foreach ($entries as $e) {
				if ($lastRun === null || ($e['timestamp'] ?? 0) > ($lastRun['timestamp'] ?? 0)) {
					$lastRun = $e;
				}
				if (($e['status'] ?? '') === self::STATUS_FINISHED) {
					if ($lastSuccess === null || $e['timestamp'] > $lastSuccess['timestamp']) {
						$lastSuccess = $e;
					}
				} else {
					// Non-FINISHED entries with a fresh mtime indicate in-flight (or stalled).
					if ($inFlight === null || $e['timestamp'] > $inFlight['timestamp']) {
						$inFlight = $e;
					}
				}
			}

			$nextRun = null;
			$prevRun = null;
			$missed = false;
			$missedReason = null;
			if ($cron !== '') {
				try {
					$cx = \Cron\CronExpression::factory($cron);
					$nextRun = $cx->getNextRunDate('now')->format('c');
					$prevTs = $cx->getPreviousRunDate('now')->getTimestamp();
					$prevRun = date('c', $prevTs);
					if ($scheduleEnabled
						&& ($now - $prevTs) > self::MISSED_GRACE_SECONDS
						&& ($lastSuccess === null || $lastSuccess['timestamp'] < $prevTs)) {
						$missed = true;
						$missedReason = $lastSuccess
							? 'Last successful run predates the most recent scheduled tick.'
							: 'No successful run has been recorded.';
					}
				} catch (\Throwable $e) {
					$missedReason = 'Cron expression failed to parse: ' . $e->getMessage();
				}
			}

			$jobs[] = [
				'id' => $id,
				'name' => $name,
				'schedule' => $cron,
				'schedule_enabled' => $scheduleEnabled,
				'last_run' => $this->shapeRun($lastRun),
				'last_successful_run' => $this->shapeRun($lastSuccess),
				'in_flight' => $this->shapeRun($inFlight),
				'next_scheduled_run' => $nextRun,
				'previous_scheduled_run' => $prevRun,
				'missed' => $missed,
				'missed_reason' => $missedReason,
			];

			$summary['total']++;
			if ($scheduleEnabled) $summary['scheduled']++;
			if ($missed) $summary['missed']++;
			if ($inFlight) $summary['in_flight']++;
		}

		return ['summary' => $summary, 'jobs' => $jobs];
	}

	/**
	 * Read runningBackupstatus rows from the Backup BMO and group by job UUID.
	 * Each row carries {buid, status, backupstatus, backupfile}; we add a timestamp
	 * (artifact mtime if the file exists, else 0) so callers can pick the most recent.
	 */
	private function collectRunHistory() {
		$history = [];
		$runs = [];
		try {
			$runs = $this->freepbx->Backup->getAll('runningBackupstatus') ?: [];
		} catch (\Throwable $e) {
			return $history;
		}
		foreach ($runs as $txId => $entry) {
			if (!is_array($entry)) continue;
			$buid = $entry['buid'] ?? null;
			if (!$buid) continue;
			$file = $entry['backupfile'] ?? '';
			$resolved = $this->resolveArtifactPath($file);
			$ts = 0;
			$size = null;
			if ($resolved) {
				$file = $resolved;
				$ts = filemtime($resolved) ?: 0;
				$size = filesize($resolved) ?: null;
			}
			$history[$buid][] = [
				'transaction_id' => is_string($txId) ? $txId : '',
				'status' => $entry['status'] ?? '',
				'backupstatus' => $entry['backupstatus'] ?? null,
				'file' => $file,
				'file_size' => $size,
				'timestamp' => $ts,
			];
		}
		return $history;
	}

	/**
	 * Backup paths recorded in runningBackupstatus sometimes point to a per-job
	 * subdirectory that the writer didn't actually use (artifact ends up flat in
	 * /var/spool/asterisk/backup/). Mirror the fallback the Backup module's
	 * getLocalFiles() does: try the recorded path, then the parent dir.
	 */
	private function resolveArtifactPath($path) {
		if (!$path) return null;
		if (file_exists($path)) return $path;
		$flat = dirname(dirname($path)) . '/' . basename($path);
		if ($flat !== $path && file_exists($flat)) return $flat;
		return null;
	}

	private function shapeRun($run) {
		if (!$run) return null;
		return [
			'status' => $run['status'],
			'timestamp' => $run['timestamp'] ? date('c', $run['timestamp']) : null,
			'file' => $run['file'],
			'file_size' => $run['file_size'],
			'transaction_id' => $run['transaction_id'],
		];
	}

	private function boolish($v) {
		if (is_bool($v)) return $v;
		if (is_numeric($v)) return ((int)$v) === 1;
		$s = strtolower(trim((string)$v));
		return $s === 'true' || $s === 'yes' || $s === 'on' || $s === '1';
	}
}
