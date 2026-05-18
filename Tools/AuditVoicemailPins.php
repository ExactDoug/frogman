<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditVoicemailPins extends AbstractTool {

	public function name() {
		return 'fm_audit_voicemail_pins';
	}

	public function description() {
		return 'Audit mailbox PINs for weak/default values: empty PIN, PIN equals mailbox number, common defaults (0000/1234/etc), repeating digits, sequential ascending/descending, or shorter than 4 digits. Read-only. Returns findings grouped by severity.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Surfaces actual PIN-weakness findings (mailbox numbers + names + issue
	// classification). Treat as sensitive — same posture as fm_audit_search.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$vms = $this->freepbx->Voicemail->getVoicemail();
		$findings = [];
		$counts = ['critical' => 0, 'high' => 0, 'medium' => 0];

		$skipContexts = ['general', 'zonemessages'];

		if (!empty($vms) && is_array($vms)) {
			foreach ($vms as $vmcontext => $boxes) {
				if (in_array($vmcontext, $skipContexts)) continue;
				if (!is_array($boxes)) continue;

				foreach ($boxes as $ext => $box) {
					if (!is_array($box) || !isset($box['name'])) continue;

					$pin = isset($box['pwd']) ? (string)$box['pwd'] : '';
					$weakness = $this->classifyPin($pin, (string)$ext);
					if ($weakness === null) continue;

					$findings[] = [
						'mailbox' => (string)$ext,
						'name' => $box['name'] ?? '',
						'context' => $vmcontext,
						'issue' => $weakness['issue'],
						'severity' => $weakness['severity'],
						'recommendation' => 'Set a non-default PIN of 6+ digits via the Voicemail module.',
					];
					$counts[$weakness['severity']]++;
				}
			}
		}

		// Sort findings: critical → high → medium, then by mailbox number
		$order = ['critical' => 0, 'high' => 1, 'medium' => 2];
		usort($findings, function ($a, $b) use ($order) {
			$sevDiff = $order[$a['severity']] - $order[$b['severity']];
			if ($sevDiff !== 0) return $sevDiff;
			return strnatcmp($a['mailbox'], $b['mailbox']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	/**
	 * Classify a PIN. Returns null for "looks fine," or an array with
	 * issue/severity for weak PINs. Order of checks is severity-descending
	 * so the first match wins.
	 */
	private function classifyPin($pin, $ext) {
		if ($pin === '') {
			return ['issue' => 'PIN is empty', 'severity' => 'critical'];
		}
		if ($pin === $ext) {
			return ['issue' => 'PIN equals mailbox number', 'severity' => 'critical'];
		}
		if (strlen($pin) < 4) {
			return ['issue' => 'PIN is shorter than 4 digits', 'severity' => 'medium'];
		}
		if (preg_match('/^(\d)\1+$/', $pin)) {
			return ['issue' => 'PIN is all the same digit', 'severity' => 'high'];
		}
		if ($this->isSequential($pin)) {
			return ['issue' => 'PIN is sequential digits', 'severity' => 'high'];
		}
		$commonDefaults = ['1234', '4321', '0123', '12345', '123456', '111111', '000000', '654321'];
		if (in_array($pin, $commonDefaults, true)) {
			return ['issue' => 'PIN is a common default', 'severity' => 'high'];
		}
		// Repeating pairs like 1212, 2020, 1010
		if (strlen($pin) >= 4 && strlen($pin) % 2 === 0 && preg_match('/^(\d{2})\1+$/', $pin)) {
			return ['issue' => 'PIN is a repeating 2-digit pattern', 'severity' => 'medium'];
		}
		return null;
	}

	private function isSequential($pin) {
		if (strlen($pin) < 3) return false;
		if (!ctype_digit($pin)) return false;
		$asc = true;
		$desc = true;
		for ($i = 1; $i < strlen($pin); $i++) {
			$diff = (int)$pin[$i] - (int)$pin[$i - 1];
			if ($diff !== 1) $asc = false;
			if ($diff !== -1) $desc = false;
		}
		return $asc || $desc;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'No weak voicemail PINs found.';
		}
		$parts = [];
		foreach (['critical', 'high', 'medium'] as $sev) {
			if ($counts[$sev] > 0) {
				$parts[] = "{$counts[$sev]} {$sev}";
			}
		}
		return count($findings) . ' weak PIN(s) found: ' . implode(', ', $parts) . '.';
	}
}
