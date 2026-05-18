<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditOrphanDids extends AbstractTool {

	public function name() {
		return 'fm_audit_orphan_dids';
	}

	public function description() {
		return 'Audit inbound routes for orphaned destinations: routes with no destination set, routes whose destination string cannot be parsed, and the "Any DID / Any CID" catchall (informational — flagged so admins can verify its destination is intentional). Read-only.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Inbound-route configuration is operational rather than secret-bearing,
	// but the catchall finding is a toll-fraud surface marker. Bumped to
	// admin alongside the rest of the audit family for consistency.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$dids = $this->freepbx->Core->getAllDIDs();
		$findings = [];
		$counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'info' => 0];

		if (!empty($dids)) {
			foreach ($dids as $did) {
				$ext = (string)($did['extension'] ?? '');
				$cid = (string)($did['cidnum'] ?? '');
				$dest = (string)($did['destination'] ?? '');
				$desc = (string)($did['description'] ?? '');

				$weakness = $this->classifyRoute($ext, $cid, $dest);
				if ($weakness === null) continue;

				$findings[] = [
					'did' => $ext !== '' ? $ext : '(any)',
					'cid' => $cid !== '' ? $cid : '(any)',
					'description' => $desc,
					'destination' => $dest !== '' ? $dest : '(empty)',
					'issue' => $weakness['issue'],
					'severity' => $weakness['severity'],
					'recommendation' => $weakness['recommendation'],
				];
				$counts[$weakness['severity']]++;
			}
		}

		$order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'info' => 3];
		usort($findings, function ($a, $b) use ($order) {
			$sevDiff = $order[$a['severity']] - $order[$b['severity']];
			if ($sevDiff !== 0) return $sevDiff;
			return strnatcmp($a['did'], $b['did']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	private function classifyRoute($did, $cid, $dest) {
		if ($dest === '') {
			return [
				'issue' => 'Inbound route has no destination set — calls fall through to default Asterisk handling',
				'severity' => 'critical',
				'recommendation' => 'Set an explicit destination or delete the route via Inbound Routes.',
			];
		}
		if ($did === '' && $cid === '') {
			return [
				'issue' => 'Catchall route (Any DID / Any CID) — verify the destination is intentional',
				'severity' => 'info',
				'recommendation' => 'Confirm this destination is safe for arbitrary incoming traffic.',
			];
		}
		// Parsing aid: AbstractTool::describeDestination returns ['type' => 'unknown', ...]
		// for destinations it can't classify against a known target type.
		$described = $this->describeDestination($dest);
		if (is_array($described) && ($described['type'] ?? '') === 'unknown') {
			return [
				'issue' => 'Destination string does not match any known target type',
				'severity' => 'medium',
				'recommendation' => 'Verify the destination resolves to a real target.',
			];
		}
		return null;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'No orphaned inbound routes found.';
		}
		$parts = [];
		foreach (['critical', 'high', 'medium', 'info'] as $sev) {
			if ($counts[$sev] > 0) $parts[] = "{$counts[$sev]} {$sev}";
		}
		return count($findings) . ' finding(s): ' . implode(', ', $parts) . '.';
	}
}
