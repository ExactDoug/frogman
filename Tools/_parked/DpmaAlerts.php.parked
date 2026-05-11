<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DpmaAlerts extends AbstractTool {
	public function name() { return 'fm_dpma_alerts'; }
	public function description() { return 'List active DPMA alerts — phone-side issues DPMA has flagged (provisioning errors, license problems, firmware mismatches, etc.). Optional filter: ext.'; }

	public function validate($params) { return true; }

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$res = $astman->Command('digium_phones show alerts');
		$raw = trim($res['data'] ?? '');

		$lines = [];
		$total = null;
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			if ($line === '' || stripos($line, 'Privilege:') === 0) continue;
			// DPMA wraps output with "--- Alerts ---" header and "---- N Alerts found ----" footer
			if (preg_match('/^----\s*(\d+)\s+Alerts?\s+found/i', $line, $m)) {
				$total = (int)$m[1];
				continue;
			}
			if (strpos($line, '---') === 0) continue;
			$lines[] = $line;
		}

		$filter = $params['ext'] ?? null;
		if ($filter !== null) {
			$lines = array_values(array_filter($lines, function($l) use ($filter) {
				return preg_match('/\b' . preg_quote((string)$filter, '/') . '\b/', $l);
			}));
		}

		return [
			'count' => $total !== null ? $total : count($lines),
			'filter' => $filter,
			'alerts' => $lines,
		];
	}
}
