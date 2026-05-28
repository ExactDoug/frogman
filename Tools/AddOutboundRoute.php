<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// Routing surface for outbound-route writes:
//   1. $freepbx->Core->X(): no facade methods exist for route writes (verified)
//   2. GraphQL mutation: Routes.php is a 10-line stub, no write scope
//   3. Outboundrouting class: what FreePBX itself uses; canonical and used here
//   4. functions.deprecated.php: thin wrapper of #3; fires deprecation warning
//   5. Direct DB writes: forbidden by Frogman Hard Rule 3
// We're on rung 3 because rungs 1 and 2 don't exist. If Outboundrouting changes
// shape, the legacy core_routing_addbyid() wrappers break the same instant, so
// this is de facto in-walls. Reads stay on the BMO facade ($freepbx->Core->X).
class AddOutboundRoute extends AbstractTool {
	public function name() { return 'fm_add_outbound_route'; }
	public function description() { return 'Add an outbound route. Params: name (required, route label), trunks (required, array of trunk IDs or trunk names in priority order), patterns (required, array of pattern rows where each row accepts either {match:"011|."} pipe-form or {prefix:"011", match:"."} explicit-form, plus optional prepend, cid, description), outcid (optional route CID), outcid_mode (optional, "off"|"on"|"on_emergency", default "off"), password (optional PIN, 4-15 digits), emergency_route (optional bool), intracompany_route (optional bool), mohclass (optional, default "default"), time_group_id (optional int, 0 = always). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		if (!preg_match('/^[a-zA-Z0-9_\-\s]{1,50}$/', $params['name'])) return 'Parameter "name" must be 1-50 chars, [a-zA-Z0-9_\-\s] only';
		if (empty($params['trunks']) || !is_array($params['trunks'])) return 'Parameter "trunks" is required (array of trunk IDs or names)';
		if (empty($params['patterns']) || !is_array($params['patterns'])) return 'Parameter "patterns" is required (array of pattern rows)';
		if (!empty($params['password']) && !preg_match('/^\d{4,15}$/', $params['password'])) return 'Parameter "password" must be 4-15 digits';
		if (!empty($params['outcid']) && !preg_match('/^\+?[0-9*#]{2,18}$/', $params['outcid'])) return 'Parameter "outcid" must look like a valid CID (digits, *, #, optional leading +)';
		if (isset($params['outcid_mode']) && !in_array($params['outcid_mode'], ['off', 'on', 'on_emergency'], true)) return 'Parameter "outcid_mode" must be one of: off, on, on_emergency';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Normalize one pattern row from user input into the {prefix, pass, cid, prepend}
	// shape Outboundrouting::updatePatterns() expects. Accepts either pipe-form
	// ({match: "011|."}) or explicit form ({prefix: "011", match: "."}).
	// Returns null on validation failure with $err populated.
	// Static so UpdateOutboundRoute can reuse without reflection.
	public static function normalizePattern($row, &$err) {
		$err = '';
		if (!is_array($row)) { $err = 'each pattern must be an object'; return null; }
		$prefix = (string)($row['prefix'] ?? '');
		$pass = (string)($row['match'] ?? '');
		$cid = (string)($row['cid'] ?? '');
		$prepend = (string)($row['prepend'] ?? '');
		// Pipe-form: "match" contains |, split into prefix + pass.
		if ($prefix === '' && strpos($pass, '|') !== false) {
			[$prefix, $pass] = explode('|', $pass, 2);
		}
		$prefix = strtoupper(trim($prefix));
		$pass = strtoupper(trim($pass));
		$cid = strtoupper(trim($cid));
		$prepend = strtoupper(trim($prepend));
		// Framework reject for code-generating tools (feedback_two_layer_validation).
		foreach ([$prefix, $pass, $cid, $prepend] as $v) {
			if (preg_match('/[\r\n\0;,]/', $v)) { $err = 'control / framing chars not allowed in pattern fields'; return null; }
		}
		// Whitelist matches Outboundrouting::updatePatterns filter exactly.
		$allowed = '/^[0-9*#+\-.\[\]XNZ]*$/';
		foreach (['prefix' => $prefix, 'match' => $pass, 'cid' => $cid, 'prepend' => $prepend] as $name => $v) {
			if ($v !== '' && !preg_match($allowed, $v)) { $err = "pattern field \"{$name}\" has disallowed chars (allowed: 0-9 * # + - . [] X N Z)"; return null; }
		}
		if ($prefix === '' && $pass === '' && $cid === '') { $err = 'pattern row needs at least one of prefix / match / cid'; return null; }
		return [
			'match_pattern_prefix' => $prefix,
			'match_pattern_pass'   => $pass,
			'match_cid'            => $cid,
			'prepend_digits'       => $prepend,
		];
	}

	// Resolve mixed trunk IDs and trunk names to a flat list of IDs in caller-supplied order.
	// Returns null on failure with $err populated. Static so UpdateOutboundRoute can reuse.
	public static function resolveTrunks($freepbx, $input, &$err) {
		$err = '';
		$out = [];
		$all = $freepbx->Core->listTrunks();
		$byId = []; $byName = [];
		foreach ($all as $t) {
			$id = (string)($t['trunkid'] ?? '');
			$name = (string)($t['name'] ?? '');
			if ($id !== '') $byId[$id] = $id;
			if ($name !== '') $byName[strtolower($name)] = $id;
		}
		foreach ($input as $item) {
			$item = trim((string)$item);
			if ($item === '') continue;
			if (isset($byId[$item])) { $out[] = $byId[$item]; continue; }
			if (isset($byName[strtolower($item)])) { $out[] = $byName[strtolower($item)]; continue; }
			$err = "trunk \"{$item}\" not found (must be a trunk ID or trunk name)";
			return null;
		}
		if (empty($out)) { $err = 'at least one valid trunk is required'; return null; }
		return $out;
	}

	// Inline safety check for the patterns we're about to write. Returns an array
	// of warnings (empty if clean). Mirrors fm_audit_outbound_international and
	// fm_audit_open_dial_patterns spot-checks without instantiating those tools.
	// Static so UpdateOutboundRoute can reuse.
	public static function safetyWarnings(array $patterns, $hasPin, $isEmergencyRoute) {
		$warnings = [];
		foreach ($patterns as $p) {
			$prefix = $p['match_pattern_prefix'];
			$pass = $p['match_pattern_pass'];
			$display = ($prefix !== '' ? "{$prefix}|" : '') . $pass;
			// International prefix scan
			if (preg_match('/^(011|0011|00)/', $prefix . $pass) && !$hasPin) {
				$warnings[] = "🚨 Pattern `{$display}` opens international dialing without a PIN. fm_audit_outbound_international will flag this.";
			}
			// Catch-all / open dial pattern detection
			if (in_array($pass, ['.', 'X.', 'N.', 'Z.'], true) && $prefix === '' && !$hasPin && !$isEmergencyRoute) {
				$warnings[] = "🚨 Pattern `{$display}` is an unbounded catch-all without PIN protection. Any digit string would match.";
			}
		}
		return $warnings;
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// Normalize patterns
		$patterns = [];
		foreach ($params['patterns'] as $i => $row) {
			$err = '';
			$np = self::normalizePattern($row, $err);
			if ($np === null) return ['error' => "pattern[{$i}]: {$err}"];
			$patterns[] = $np;
		}

		// Resolve trunks
		$err = '';
		$trunks = self::resolveTrunks($this->freepbx, $params['trunks'], $err);
		if ($trunks === null) return ['error' => $err];

		// Defaults
		$name = trim($params['name']);
		$outcid = trim((string)($params['outcid'] ?? ''));
		$outcid_mode = $params['outcid_mode'] ?? 'off';
		$password = (string)($params['password'] ?? '');
		$emergency_route = !empty($params['emergency_route']) ? 'YES' : 'NO';
		$intracompany_route = !empty($params['intracompany_route']) ? 'YES' : 'NO';
		$mohclass = trim((string)($params['mohclass'] ?? 'default'));
		$time_group_id = (int)($params['time_group_id'] ?? 0);

		// Safety nudges (computed before write so dry-run preview surfaces them too).
		$warnings = self::safetyWarnings($patterns, $password !== '', $emergency_route === 'YES');
		$warnNote = !empty($warnings) ? "\n\n" . implode("\n", $warnings) : '';

		// All chat-interpolated values pass through sanitizeForChat per the
		// chat-formatter XSS pattern (feedback_chat_formatter_xss_pattern.md).
		$nameSan = $this->frogman->sanitizeForChat($name);
		$outcidSan = $this->frogman->sanitizeForChat($outcid);

		// Dry-run preview
		if (!$confirm) {
			$frogman = $this->frogman;
			$patternSummary = array_map(function($p) use ($frogman) {
				$rawDisp = ($p['match_pattern_prefix'] !== '' ? "{$p['match_pattern_prefix']}|" : '') . $p['match_pattern_pass'];
				$dispSan = $frogman->sanitizeForChat($rawDisp);
				$prependSan = $frogman->sanitizeForChat($p['prepend_digits']);
				$cidSan = $frogman->sanitizeForChat($p['match_cid']);
				if ($p['prepend_digits'] !== '') $line = "prepend `{$prependSan}` then match `{$dispSan}`";
				else $line = "match `{$dispSan}`";
				if ($p['match_cid'] !== '') $line .= " (CID filter: `{$cidSan}`)";
				return $line;
			}, $patterns);
			return ['dry_run' => true, 'message' => "Would add outbound route `{$nameSan}` with " . count($patterns) . " pattern(s) and " . count($trunks) . " trunk(s):\n• " . implode("\n• ", $patternSummary) . ($password !== '' ? "\nPIN required: yes" : "\nPIN required: no") . ($emergency_route === 'YES' ? "\nEmergency route: yes" : '') . ($outcid !== '' ? "\nRoute CID: `{$outcidSan}` (override extension CID: " . ($outcid_mode === 'off' ? 'no' : $outcid_mode) . ")" : '') . $warnNote . "\n\nReply yes to confirm.", 'route' => [
				'name' => $name, 'patterns' => $patterns, 'trunks' => $trunks,
				'outcid' => $outcid, 'outcid_mode' => $outcid_mode, 'password' => $password !== '' ? '***' : '',
				'emergency_route' => $emergency_route, 'intracompany_route' => $intracompany_route,
				'mohclass' => $mohclass, 'time_group_id' => $time_group_id,
			]];
		}

		// Live write
		$routing = new \FreePBX\modules\Core\Components\Outboundrouting();
		$routeId = $routing->add(
			$name, $outcid, $outcid_mode, $password,
			$emergency_route, $intracompany_route,
			$mohclass, $time_group_id,
			$patterns, $trunks
		);

		return ['dry_run' => false, 'message' => "✅ Outbound route `{$nameSan}` added as route id `{$routeId}` with " . count($patterns) . " pattern(s) and " . count($trunks) . " trunk(s)." . $warnNote, 'route_id' => $routeId, 'needs_reload' => true];
	}
}
