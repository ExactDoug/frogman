<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DpmaLicenseStatus extends AbstractTool {
	public function name() { return 'fm_dpma_license_status'; }
	public function description() { return 'DPMA license summary — count of paid vs free licenses, current usage, expiration. Sangoma phones require DPMA licensing.'; }

	public function validate($params) { return true; }

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$res = $astman->Command('digium_phones license status');
		$raw = trim($res['data'] ?? '');

		$parsed = [];
		$statusLine = null;
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			if ($line === '' || stripos($line, 'Privilege:') === 0) continue;
			if (preg_match('/^([A-Za-z][A-Za-z0-9 _\-]+?)\s*:\s*(.*)$/', $line, $m)) {
				$key = strtolower(preg_replace('/\s+/', '_', trim($m[1])));
				$parsed[$key] = trim($m[2]);
			} else {
				// DPMA license status is often a single OK/error line — capture it
				if ($statusLine === null) $statusLine = $line;
			}
		}
		$valid = ($statusLine !== null && stripos($statusLine, 'valid') !== false && stripos($statusLine, 'ok') !== false)
			|| (isset($parsed['status']) && stripos($parsed['status'], 'valid') !== false);

		// Cross-reference EPM module flags + per-brand phone counts
		$endpoint = \FreePBX::Endpoint();
		$epmLicensed = null;
		$brandCounts = null;
		try { $epmLicensed = $endpoint->getLicensed(); } catch (\Throwable $e) {}
		try { $brandCounts = $endpoint->endpointCheckLicense(); } catch (\Throwable $e) {}

		return [
			'valid' => $valid,
			'status_line' => $statusLine,
			'parsed' => $parsed,
			'brand_counts' => $brandCounts,
			'epm_licensed' => $epmLicensed,
			'raw' => $raw,
		];
	}
}
