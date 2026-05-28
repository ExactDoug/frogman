<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AddOutboundRoute.php';

// Single-pattern append to an existing outbound route. Read current patterns,
// append the new one, write the full list back via Outboundrouting::updatePatterns
// with $delete=true. Same in-walls surface as Add/Update/Remove OutboundRoute.
class AddOutboundPattern extends AbstractTool {
	public function name() { return 'fm_add_outbound_pattern'; }
	public function description() { return 'Add a single dial pattern to an existing outbound route. Params: route_id OR route_name (one required), match (required, e.g. "1NXXNXXXXXX" or pipe-form "9|NXXNXXXXXX"), prefix (optional, alternative explicit form), prepend (optional digits to prepend on dial), cid (optional CID filter), description (optional). Requires confirm:true. Duplicate of an existing pattern on the same route is rejected.'; }
	public function validate($params) {
		if (empty($params['route_id']) && empty($params['route_name'])) return 'Parameter "route_id" or "route_name" is required';
		if (empty($params['match']) && empty($params['prefix'])) return 'Parameter "match" or "prefix" is required (a pattern needs at least one of them)';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	private function resolveRouteId($params) {
		if (!empty($params['route_id'])) {
			$r = $this->freepbx->Core->getRoute((string)$params['route_id']);
			return !empty($r) ? (string)$params['route_id'] : null;
		}
		$all = $this->freepbx->Core->getAllRoutes();
		$needle = strtolower(trim((string)$params['route_name']));
		foreach ($all as $r) {
			if (strtolower((string)($r['name'] ?? '')) === $needle) return (string)$r['route_id'];
		}
		return null;
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$routeId = $this->resolveRouteId($params);
		if ($routeId === null) {
			$identSan = $this->frogman->sanitizeForChat((string)($params['route_id'] ?? $params['route_name']));
			return ['error' => "outbound route `{$identSan}` not found"];
		}

		// Normalize the new pattern using the shared helper so format / validation
		// match what AddOutboundRoute would produce.
		$err = '';
		$newPattern = AddOutboundRoute::normalizePattern($params, $err);
		if ($newPattern === null) return ['error' => "pattern: {$err}"];

		$route = (array)$this->freepbx->Core->getRoute($routeId);
		$currentPatterns = $this->freepbx->Core->getRoutePatternsByID($routeId);

		// All chat-interpolated values pass through sanitizeForChat per the
		// chat-formatter XSS pattern (feedback_chat_formatter_xss_pattern.md).
		// $route['name'] is DB-sourced. Pattern values are filter-normalized to
		// [0-9*#+\-.\[\]XNZ] only so they can't carry XSS, but we sanitize for
		// convention.
		$routeNameSan = $this->frogman->sanitizeForChat((string)($route['name'] ?? ''));
		$dispRaw = ($newPattern['match_pattern_prefix'] !== '' ? "{$newPattern['match_pattern_prefix']}|" : '') . $newPattern['match_pattern_pass'];
		$dispSan = $this->frogman->sanitizeForChat($dispRaw);
		$prependSan = $this->frogman->sanitizeForChat($newPattern['prepend_digits']);
		$cidSan = $this->frogman->sanitizeForChat($newPattern['match_cid']);

		// Duplicate guard: same prefix+pass+cid means same dial-plan slot.
		foreach ($currentPatterns as $p) {
			if ((string)($p['match_pattern_prefix'] ?? '') === $newPattern['match_pattern_prefix']
				&& (string)($p['match_pattern_pass'] ?? '') === $newPattern['match_pattern_pass']
				&& (string)($p['match_cid'] ?? '') === $newPattern['match_cid']) {
				return ['error' => "Route `{$routeNameSan}` already has pattern `{$dispSan}`. Use fm_update_outbound_route if you want to change the prepend or CID filter."];
			}
		}

		// Safety nudge for the new pattern, in context of the route's existing PIN/emergency state.
		$hasPin = !empty($route['password']);
		$isEmerg = (string)($route['emergency_route'] ?? 'NO') === 'YES';
		$warnings = AddOutboundRoute::safetyWarnings([$newPattern], $hasPin, $isEmerg);
		$warnNote = !empty($warnings) ? "\n\n" . implode("\n", $warnings) : '';

		$prependNote = $newPattern['prepend_digits'] !== '' ? " (prepend `{$prependSan}`)" : '';
		$cidNote = $newPattern['match_cid'] !== '' ? " (CID filter: `{$cidSan}`)" : '';

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would add pattern `{$dispSan}`{$prependNote}{$cidNote} to outbound route `{$routeNameSan}` (id `{$routeId}`). Route would have " . (count($currentPatterns) + 1) . " pattern(s)." . $warnNote . "\n\nReply yes to confirm."];
		}

		// Write back: full list (existing + new), $delete=true for atomic replace.
		$mergedPatterns = array_merge($this->normalizeStoredPatterns($currentPatterns), [$newPattern]);
		$routing = new \FreePBX\modules\Core\Components\Outboundrouting();
		$routing->updatePatterns($routeId, $mergedPatterns, true);

		return ['dry_run' => false, 'message' => "✅ Added pattern `{$dispSan}`{$prependNote}{$cidNote} to outbound route `{$routeNameSan}`. Route now has " . (count($currentPatterns) + 1) . " pattern(s)." . $warnNote, 'route_id' => $routeId, 'needs_reload' => true];
	}

	// Stored pattern rows from getRoutePatternsByID include a route_id column we
	// must strip before passing back to updatePatterns (it adds its own).
	private function normalizeStoredPatterns(array $rows) {
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'match_pattern_prefix' => (string)($r['match_pattern_prefix'] ?? ''),
				'match_pattern_pass'   => (string)($r['match_pattern_pass'] ?? ''),
				'match_cid'            => (string)($r['match_cid'] ?? ''),
				'prepend_digits'       => (string)($r['prepend_digits'] ?? ''),
			];
		}
		return $out;
	}
}
