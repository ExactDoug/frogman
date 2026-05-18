<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditExtensionSecrets extends AbstractTool {

	public function name() {
		return 'fm_audit_extension_secrets';
	}

	public function description() {
		return 'Audit SIP/PJSIP extension secrets for weakness: empty, matches the extension number, common defaults (password/secret/letmein/etc), shorter than 8 characters, all the same character, or low-entropy numeric-only patterns. Read-only. Returns findings grouped by severity. Does not expose secret values.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Surfaces extension numbers with weak SIP secrets — same sensitivity tier
	// as the voicemail PIN audit. Admin-only.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$devices = $this->freepbx->Core->getAllDevicesByType('');
		$findings = [];
		$counts = ['critical' => 0, 'high' => 0, 'medium' => 0];

		foreach ($devices as $dev) {
			$ext = (string)$dev['id'];
			$tech = $dev['tech'] ?? '';
			if (!in_array($tech, ['pjsip', 'sip'], true)) continue;

			$full = $this->freepbx->Core->getDevice($ext);
			$secret = isset($full['secret']) ? (string)$full['secret'] : '';

			$weakness = $this->classifySecret($secret, $ext);
			if ($weakness === null) continue;

			$findings[] = [
				'extension' => $ext,
				'name' => $dev['description'] ?? '',
				'tech' => $tech,
				'issue' => $weakness['issue'],
				'severity' => $weakness['severity'],
				'recommendation' => 'Set a high-entropy secret (16+ mixed-case alphanumeric) via the Core module.',
			];
			$counts[$weakness['severity']]++;
		}

		$order = ['critical' => 0, 'high' => 1, 'medium' => 2];
		usort($findings, function ($a, $b) use ($order) {
			$sevDiff = $order[$a['severity']] - $order[$b['severity']];
			if ($sevDiff !== 0) return $sevDiff;
			return strnatcmp($a['extension'], $b['extension']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	private function classifySecret($secret, $ext) {
		if ($secret === '') {
			return ['issue' => 'Secret is empty', 'severity' => 'critical'];
		}
		if ($secret === $ext) {
			return ['issue' => 'Secret equals extension number', 'severity' => 'critical'];
		}
		$common = [
			'secret', 'password', 'passw0rd', 'pass', '12345678', 'letmein',
			'changeme', 'default', 'admin', 'sangoma', 'freepbx', 'asterisk',
			'1234567890', 'qwerty123', 'welcome1',
		];
		if (in_array(strtolower($secret), $common, true)) {
			return ['issue' => 'Secret is a common default', 'severity' => 'high'];
		}
		if (strlen($secret) < 8) {
			return ['issue' => 'Secret is shorter than 8 characters', 'severity' => 'high'];
		}
		if (preg_match('/^(.)\1+$/', $secret)) {
			return ['issue' => 'Secret is all the same character', 'severity' => 'medium'];
		}
		// Numeric-only secrets under 12 digits are guessable in reasonable time.
		if (ctype_digit($secret) && strlen($secret) < 12) {
			return ['issue' => 'Secret is short numeric-only (low entropy)', 'severity' => 'medium'];
		}
		return null;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'No weak extension secrets found.';
		}
		$parts = [];
		foreach (['critical', 'high', 'medium'] as $sev) {
			if ($counts[$sev] > 0) $parts[] = "{$counts[$sev]} {$sev}";
		}
		return count($findings) . ' weak secret(s) found: ' . implode(', ', $parts) . '.';
	}
}
