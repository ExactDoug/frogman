<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// Write path uses Outboundrouting::deleteById(). Same surface as AddOutboundRoute.
// See header note in AddOutboundRoute.php for the in-walls justification.
class RemoveOutboundRoute extends AbstractTool {
	public function name() { return 'fm_remove_outbound_route'; }
	public function description() { return 'Remove an outbound route. Params: route_id OR route_name (one required). Requires confirm:true. Refuses to delete a route whose patterns include 911/999/112 (emergency safety guard).'; }
	public function validate($params) {
		if (empty($params['route_id']) && empty($params['route_name'])) return 'Parameter "route_id" or "route_name" is required';
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

	// Refuse deletion of a route whose patterns cover emergency numbers (911, 999, 112).
	// Operators who genuinely need to remove an emergency-only route can delete the
	// patterns first (via fm_update_outbound_route) or via the GUI.
	private function hasEmergencyPattern(array $patterns) {
		foreach ($patterns as $p) {
			$disp = strtoupper(($p['match_pattern_prefix'] ?? '') . ($p['match_pattern_pass'] ?? ''));
			if (in_array($disp, ['911', '999', '112'], true)) return $disp;
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

		// getRoute() returns stdClass; cast for consistent array access.
		$route = (array)$this->freepbx->Core->getRoute($routeId);
		$trunks = $this->freepbx->Core->getRouteTrunksByID($routeId);
		$patterns = $this->freepbx->Core->getRoutePatternsByID($routeId);

		// $route['name'] is DB-sourced — sanitize for chat (chat-formatter XSS pattern).
		$routeNameSan = $this->frogman->sanitizeForChat((string)($route['name'] ?? ''));

		$emerg = $this->hasEmergencyPattern($patterns);
		if ($emerg !== null) {
			$emergSan = $this->frogman->sanitizeForChat($emerg);
			return ['error' => "Refusing to delete outbound route `{$routeNameSan}` (id `{$routeId}`) because it includes the emergency pattern `{$emergSan}`. Remove the emergency pattern first via fm_update_outbound_route, or use the FreePBX GUI if you're sure."];
		}

		$summary = count($patterns) . ' pattern(s), ' . count($trunks) . ' trunk(s)';

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would delete outbound route `{$routeNameSan}` (id `{$routeId}`) with {$summary}.\n\nReply yes to confirm.", 'route_id' => $routeId, 'route_name' => $route['name']];
		}

		$routing = new \FreePBX\modules\Core\Components\Outboundrouting();
		$routing->deleteById($routeId);

		return ['dry_run' => false, 'message' => "✅ Outbound route `{$routeNameSan}` (id `{$routeId}`) deleted ({$summary} removed).", 'route_id' => $routeId, 'needs_reload' => true];
	}
}
