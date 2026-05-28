<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AddOutboundRoute.php';

// Write path uses Outboundrouting::editById(). Same surface as AddOutboundRoute.
// See header note in AddOutboundRoute.php for the in-walls justification.
class UpdateOutboundRoute extends AbstractTool {
	public function name() { return 'fm_update_outbound_route'; }
	public function description() { return 'Update an outbound route. Params: route_id OR route_name (one required as identifier), plus any subset of name, trunks, patterns, outcid, outcid_mode, password, emergency_route, intracompany_route, mohclass, time_group_id. Selective update where fields not supplied keep their current values. Patterns and trunks, if supplied, REPLACE the current sets (full replacement, not delta). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['route_id']) && empty($params['route_name'])) return 'Parameter "route_id" or "route_name" is required';
		if (isset($params['name']) && !preg_match('/^[a-zA-Z0-9_\-\s]{1,50}$/', $params['name'])) return 'Parameter "name" must be 1-50 chars, [a-zA-Z0-9_\-\s] only';
		if (isset($params['trunks']) && !is_array($params['trunks'])) return 'Parameter "trunks" must be an array if supplied';
		if (isset($params['patterns']) && !is_array($params['patterns'])) return 'Parameter "patterns" must be an array if supplied';
		if (!empty($params['password']) && !preg_match('/^\d{4,15}$/', $params['password'])) return 'Parameter "password" must be 4-15 digits';
		if (!empty($params['outcid']) && !preg_match('/^\+?[0-9*#]{2,18}$/', $params['outcid'])) return 'Parameter "outcid" must look like a valid CID';
		if (isset($params['outcid_mode']) && !in_array($params['outcid_mode'], ['off', 'on', 'on_emergency'], true)) return 'Parameter "outcid_mode" must be one of: off, on, on_emergency';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Resolve route_id or route_name to a route_id, or null if not found.
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

		// getRoute() returns stdClass; cast for consistent array access.
		$current = (array)$this->freepbx->Core->getRoute($routeId);
		$currentTrunks = $this->freepbx->Core->getRouteTrunksByID($routeId);
		$currentPatterns = $this->freepbx->Core->getRoutePatternsByID($routeId);

		// Compose the merged write payload. For each field, prefer supplied value, else fall back to current.
		$name = isset($params['name']) ? trim($params['name']) : (string)$current['name'];

		// Patterns: full replacement if supplied
		if (isset($params['patterns'])) {
			$patterns = [];
			foreach ($params['patterns'] as $i => $row) {
				$err = '';
				$out = AddOutboundRoute::normalizePattern($row, $err);
				if ($out === null) return ['error' => "pattern[{$i}]: {$err}"];
				$patterns[] = $out;
			}
		} else {
			$patterns = $currentPatterns;
		}

		// Trunks: full replacement if supplied
		if (isset($params['trunks'])) {
			$err = '';
			$trunks = AddOutboundRoute::resolveTrunks($this->freepbx, $params['trunks'], $err);
			if ($trunks === null) return ['error' => $err];
		} else {
			$trunks = $currentTrunks;
		}

		$outcid = isset($params['outcid']) ? trim((string)$params['outcid']) : (string)($current['outcid'] ?? '');
		$outcid_mode = $params['outcid_mode'] ?? ($current['outcid_mode'] ?? 'off');
		$password = isset($params['password']) ? (string)$params['password'] : (string)($current['password'] ?? '');
		$emergency_route = isset($params['emergency_route']) ? (!empty($params['emergency_route']) ? 'YES' : 'NO') : (string)($current['emergency_route'] ?? 'NO');
		$intracompany_route = isset($params['intracompany_route']) ? (!empty($params['intracompany_route']) ? 'YES' : 'NO') : (string)($current['intracompany_route'] ?? 'NO');
		$mohclass = isset($params['mohclass']) ? trim((string)$params['mohclass']) : (string)($current['mohclass'] ?? 'default');
		$time_group_id = isset($params['time_group_id']) ? (int)$params['time_group_id'] : (int)($current['time_group_id'] ?? 0);

		// Safety nudges
		$warnings = AddOutboundRoute::safetyWarnings($patterns, $password !== '', $emergency_route === 'YES');
		$warnNote = !empty($warnings) ? "\n\n" . implode("\n", $warnings) : '';

		// All chat-interpolated values pass through sanitizeForChat per the
		// chat-formatter XSS pattern (feedback_chat_formatter_xss_pattern.md).
		// $current['name'] is DB-sourced (could carry payload from a route created
		// via GUI before this validation existed).
		$currentNameSan = $this->frogman->sanitizeForChat((string)($current['name'] ?? ''));
		$nameSan = $this->frogman->sanitizeForChat($name);

		// Dry-run preview
		if (!$confirm) {
			$diff = [];
			if (isset($params['name']) && $params['name'] !== $current['name']) $diff[] = "name: `{$currentNameSan}` → `{$nameSan}`";
			if (isset($params['trunks'])) $diff[] = 'trunks: ' . count($currentTrunks) . ' → ' . count($trunks);
			if (isset($params['patterns'])) $diff[] = 'patterns: ' . count($currentPatterns) . ' → ' . count($patterns);
			foreach (['outcid' => $outcid, 'outcid_mode' => $outcid_mode, 'mohclass' => $mohclass, 'time_group_id' => $time_group_id] as $f => $newVal) {
				if (isset($params[$f]) && (string)$params[$f] !== (string)($current[$f] ?? '')) {
					$oldSan = $this->frogman->sanitizeForChat((string)($current[$f] ?? ''));
					$newSan = $this->frogman->sanitizeForChat((string)$newVal);
					$diff[] = "{$f}: `{$oldSan}` → `{$newSan}`";
				}
			}
			if (isset($params['password']) && $password !== ($current['password'] ?? '')) $diff[] = "password: " . (($current['password'] ?? '') === '' ? 'unset' : '***') . ' → ' . ($password === '' ? 'unset' : '***');
			if (isset($params['emergency_route']) && $emergency_route !== ($current['emergency_route'] ?? 'NO')) $diff[] = "emergency_route: `{$current['emergency_route']}` → `{$emergency_route}`";
			if (isset($params['intracompany_route']) && $intracompany_route !== ($current['intracompany_route'] ?? 'NO')) $diff[] = "intracompany_route: `{$current['intracompany_route']}` → `{$intracompany_route}`";
			if (empty($diff)) return ['dry_run' => true, 'message' => "No changes detected for outbound route `{$currentNameSan}` (id `{$routeId}`)."];
			return ['dry_run' => true, 'message' => "Would update outbound route `{$currentNameSan}` (id `{$routeId}`):\n• " . implode("\n• ", $diff) . $warnNote . "\n\nReply yes to confirm."];
		}

		// Live write. editById takes the same long arg list as add(), with route_id first.
		$routing = new \FreePBX\modules\Core\Components\Outboundrouting();
		$routing->editById(
			$routeId,
			$name, $outcid, $outcid_mode, $password,
			$emergency_route, $intracompany_route,
			$mohclass, $time_group_id,
			$patterns, $trunks
		);

		return ['dry_run' => false, 'message' => "✅ Outbound route `{$nameSan}` (id `{$routeId}`) updated." . $warnNote, 'route_id' => $routeId, 'needs_reload' => true];
	}
}
