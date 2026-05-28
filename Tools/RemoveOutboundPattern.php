<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AddOutboundRoute.php';

// Single-pattern removal from an existing outbound route. Read current patterns,
// filter out the matching row, write the remaining list back via
// Outboundrouting::updatePatterns with $delete=true. Same in-walls surface as
// the other Outbound Route tools. Identity match is on (prefix, pass, cid);
// prepend_digits is action, not identity.
class RemoveOutboundPattern extends AbstractTool {
	public function name() { return 'fm_remove_outbound_pattern'; }
	public function description() { return 'Remove a single dial pattern from an existing outbound route. Params: route_id OR route_name (one required), match (required, the match part,pipe-form "9|NXXNXXXXXX" or plain), prefix (optional, alternative explicit form), cid (optional, only set if removing a CID-filtered pattern). Requires confirm:true. Refuses to remove the LAST pattern on a route (use fm_remove_outbound_route instead). Refuses 911/999/112 patterns (emergency safety guard).'; }
	public function validate($params) {
		if (empty($params['route_id']) && empty($params['route_name'])) return 'Parameter "route_id" or "route_name" is required';
		if (empty($params['match']) && empty($params['prefix'])) return 'Parameter "match" or "prefix" is required (need to identify which pattern to remove)';
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

		// Normalize the lookup pattern so we match exactly what we'd have stored.
		$err = '';
		$lookup = AddOutboundRoute::normalizePattern($params, $err);
		if ($lookup === null) return ['error' => "pattern: {$err}"];

		// All chat-interpolated values pass through sanitizeForChat
		// (feedback_chat_formatter_xss_pattern.md).
		$dispRaw = ($lookup['match_pattern_prefix'] !== '' ? "{$lookup['match_pattern_prefix']}|" : '') . $lookup['match_pattern_pass'];
		$dispSan = $this->frogman->sanitizeForChat($dispRaw);
		$cidSan = $this->frogman->sanitizeForChat($lookup['match_cid']);

		// Emergency safety guard: refuse to remove a 911/999/112 pattern via this tool.
		$fullPattern = strtoupper($lookup['match_pattern_prefix'] . $lookup['match_pattern_pass']);
		if (in_array($fullPattern, ['911', '999', '112'], true)) {
			return ['error' => "Refusing to remove emergency pattern `{$dispSan}`. Use the FreePBX GUI if you're sure, emergency patterns should rarely be removed via automation."];
		}

		$route = (array)$this->freepbx->Core->getRoute($routeId);
		$currentPatterns = $this->freepbx->Core->getRoutePatternsByID($routeId);
		$routeNameSan = $this->frogman->sanitizeForChat((string)($route['name'] ?? ''));

		// Find the match
		$remaining = [];
		$found = false;
		foreach ($currentPatterns as $p) {
			$match = (string)($p['match_pattern_prefix'] ?? '') === $lookup['match_pattern_prefix']
				&& (string)($p['match_pattern_pass'] ?? '') === $lookup['match_pattern_pass']
				&& (string)($p['match_cid'] ?? '') === $lookup['match_cid'];
			if ($match && !$found) {
				$found = true;
				continue;
			}
			$remaining[] = [
				'match_pattern_prefix' => (string)($p['match_pattern_prefix'] ?? ''),
				'match_pattern_pass'   => (string)($p['match_pattern_pass'] ?? ''),
				'match_cid'            => (string)($p['match_cid'] ?? ''),
				'prepend_digits'       => (string)($p['prepend_digits'] ?? ''),
			];
		}

		if (!$found) {
			$cidNote = $lookup['match_cid'] !== '' ? " with CID filter `{$cidSan}`" : '';
			return ['error' => "Pattern `{$dispSan}`{$cidNote} not found on outbound route `{$routeNameSan}`. Use fm_get_outbound_route or fm_get_route_patterns to list current patterns."];
		}

		// Last-pattern guard
		if (empty($remaining)) {
			return ['error' => "Refusing to remove the last pattern on route `{$routeNameSan}`. A route with zero patterns matches nothing and is dead config. Use fm_remove_outbound_route to remove the whole route, or fm_add_outbound_pattern to add a replacement before removing this one."];
		}

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would remove pattern `{$dispSan}` from outbound route `{$routeNameSan}` (id `{$routeId}`). Route would have " . count($remaining) . " pattern(s) remaining.\n\nReply yes to confirm."];
		}

		$routing = new \FreePBX\modules\Core\Components\Outboundrouting();
		$routing->updatePatterns($routeId, $remaining, true);

		return ['dry_run' => false, 'message' => "✅ Removed pattern `{$dispSan}` from outbound route `{$routeNameSan}`. Route now has " . count($remaining) . " pattern(s).", 'route_id' => $routeId, 'needs_reload' => true];
	}
}
