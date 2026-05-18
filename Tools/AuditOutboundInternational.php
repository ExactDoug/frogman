<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditOutboundInternational extends AbstractTool {

	public function name() {
		return 'fm_audit_outbound_international';
	}

	public function description() {
		return 'Audit outbound routes for dial patterns that allow international calling. Flags routes whose dial-plan prefix or match pattern starts with 011 (US), 0011 (AU/NZ), or 00 (most of Europe). Toll-fraud reachability check — read-only.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Surfaces dial-plan structure that is operationally sensitive (which
	// routes carry international traffic and who can reach them). Admin-only
	// alongside the rest of the audit family.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$routes = $this->freepbx->Core->getAllRoutes();
		$findings = [];
		$counts = ['high' => 0, 'medium' => 0];

		foreach ($routes as $route) {
			$routeId = $route['route_id'] ?? $route['routeid'] ?? null;
			$name = $route['name'] ?? '';
			if ($routeId === null) continue;

			$patterns = $this->freepbx->Core->getRoutePatternsByID($routeId);
			if (empty($patterns) || !is_array($patterns)) continue;

			foreach ($patterns as $pat) {
				$prefix = (string)($pat['prepend'] ?? '') . (string)($pat['prefix'] ?? '');
				$match = (string)($pat['match_pattern'] ?? $pat['match_pattern_pass'] ?? '');
				$intlPrefix = $this->intlPrefix($prefix, $match);
				if ($intlPrefix === null) continue;

				$findings[] = [
					'route_id' => $routeId,
					'route_name' => $name,
					'prefix' => $prefix,
					'match_pattern' => $match,
					'international_prefix_detected' => $intlPrefix,
					'issue' => "Route allows international dialing via {$intlPrefix} prefix",
					'severity' => 'high',
					'recommendation' => 'Restrict this route with a route password or limit to a known extension allowlist via Outbound Routes.',
				];
				$counts['high']++;
			}
		}

		usort($findings, function ($a, $b) {
			return strnatcmp((string)$a['route_id'], (string)$b['route_id']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	/**
	 * Return the international prefix matched, or null if the pattern is
	 * domestic. Inspects both the user-supplied prefix string and the
	 * match-pattern dialplan slot. Order matters: longer prefixes first
	 * (0011 must beat 00).
	 */
	private function intlPrefix($prefix, $match) {
		$candidates = [$prefix, $match];
		foreach (['0011', '011', '00'] as $intl) {
			foreach ($candidates as $cand) {
				if ($cand === '') continue;
				if (strpos($cand, $intl) === 0) {
					return $intl;
				}
			}
		}
		return null;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'No outbound routes with international dial patterns found.';
		}
		return count($findings) . ' international-capable route pattern(s) found.';
	}
}
