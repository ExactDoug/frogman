<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AuditVoicemailPins.php';
require_once __DIR__ . '/AuditExtensionSecrets.php';
require_once __DIR__ . '/AuditOrphanDids.php';
require_once __DIR__ . '/AuditOutboundInternational.php';

class AuditPosture extends AbstractTool {

	public function name() {
		return 'fm_audit_posture';
	}

	public function description() {
		return 'Run every fm_audit_* compliance tool and return a consolidated posture report — total findings + severity rollup across all audits, with per-audit summary and drill-down chat phrase. Read-only. The single-call front door to the audit family.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Same sensitivity as the underlying audits — aggregate report surfaces
	// counts and per-audit summaries that include operational context.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		// Hardcoded list — small, explicit, and obviously the set we want
		// rolled up. When fm_audit_* grows, add the new tool here.
		$audits = [
			['tool' => 'fm_audit_voicemail_pins', 'class' => AuditVoicemailPins::class, 'drilldown' => 'audit voicemail pins'],
			['tool' => 'fm_audit_extension_secrets', 'class' => AuditExtensionSecrets::class, 'drilldown' => 'audit extension secrets'],
			['tool' => 'fm_audit_orphan_dids', 'class' => AuditOrphanDids::class, 'drilldown' => 'audit orphan dids'],
			['tool' => 'fm_audit_outbound_international', 'class' => AuditOutboundInternational::class, 'drilldown' => 'audit international'],
		];

		$results = [];
		$totalFindings = 0;
		$severityRollup = ['critical' => 0, 'high' => 0, 'medium' => 0, 'info' => 0];
		$failed = [];

		foreach ($audits as $audit) {
			try {
				$instance = new $audit['class']($this->freepbx, $this->frogman);
				$out = $instance->execute([], $context);
				$count = (int)($out['count'] ?? 0);
				$sevs = $out['severity_counts'] ?? [];
				foreach ($severityRollup as $sev => $_) {
					if (isset($sevs[$sev])) {
						$severityRollup[$sev] += (int)$sevs[$sev];
					}
				}
				$totalFindings += $count;
				$results[] = [
					'tool' => $audit['tool'],
					'count' => $count,
					'severity_counts' => $sevs,
					'summary' => $out['summary'] ?? '',
					'drilldown_phrase' => $audit['drilldown'],
				];
			} catch (\Throwable $e) {
				$failed[] = ['tool' => $audit['tool'], 'error' => $e->getMessage()];
			}
		}

		return [
			'total_findings' => $totalFindings,
			'severity_counts' => $severityRollup,
			'audits' => $results,
			'failed_audits' => $failed,
			'summary' => $this->summary($totalFindings, $severityRollup, count($results), count($failed)),
		];
	}

	private function summary($total, $sevs, $ran, $failed) {
		if ($total === 0 && $failed === 0) {
			return "Posture clean: {$ran} audit(s) ran, no findings.";
		}
		$sevParts = [];
		foreach (['critical', 'high', 'medium', 'info'] as $sev) {
			if ($sevs[$sev] > 0) $sevParts[] = "{$sevs[$sev]} {$sev}";
		}
		$msg = "{$total} total finding(s) across {$ran} audit(s)";
		if (!empty($sevParts)) {
			$msg .= ': ' . implode(', ', $sevParts);
		}
		if ($failed > 0) {
			$msg .= " ({$failed} audit(s) errored)";
		}
		return $msg . '.';
	}
}
