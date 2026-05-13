<?php
namespace FreePBX\modules\Frogman\Dialplan;

require_once __DIR__ . '/TemplateBase.php';

/**
 * All dialplan templates in one file.
 * Each generates correct, complete Asterisk dialplan for extensions_custom.conf.
 *
 * Every template implements validateParams() per the contract in TemplateBase.
 * Validation is the load-bearing security boundary — generated dialplan is
 * appended to extensions_custom.conf which is included by Asterisk, so any
 * caller-controlled value that reaches generate() without being checked is a
 * remote-code-execution surface. See GHSA-pxfc-q72v-jh8m.
 */

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class IvrTemplate extends TemplateBase {
	public function id() { return 'ivr'; }
	public function name() { return 'IVR / Menu'; }
	public function description() { return 'Create a phone menu — caller hears a greeting, presses a key to reach a destination.'; }
	public function examples() { return [
		'create a menu on 8000 press 1 for sales ring 600 press 2 for support ring 601',
		'build an ivr on extension 7000 with greeting, 1 goes to 1001, 2 goes to 1002, 0 for operator',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (!isset($params['extension']) || !$this->isDigits($params['extension'])) {
			return "ivr: 'extension' is required and must be digits";
		}
		if (empty($params['options']) || !is_array($params['options'])) {
			return "ivr: 'options' is required and must be a map of digit => destination";
		}
		foreach ($params['options'] as $digit => $dest) {
			if (!preg_match('/^\d$/', (string)$digit)) {
				return "ivr: options key '{$digit}' must be a single digit (0-9)";
			}
			if (!$this->isDestination($dest)) {
				return "ivr: options[{$digit}] = '{$dest}' is not a valid destination";
			}
		}
		if (isset($params['greeting']) && !$this->isSoundFile($params['greeting'])) {
			return "ivr: 'greeting' must be a sound-file reference (alphanumerics, /, ., _, -)";
		}
		foreach (['timeout', 'default', 'invalid'] as $key) {
			if (isset($params[$key]) && $params[$key] !== 'i' && $params[$key] !== 't' && !$this->isDestination($params[$key])) {
				return "ivr: '{$key}' must be 'i', 't', or a valid destination";
			}
		}
		return true;
	}

	public function generate($params) {
		$ext = $params['extension'];
		$options = $params['options']; // ['1' => '600', '2' => '601', ...]
		$greeting = $params['greeting'] ?? 'custom/frogman-greeting';
		$timeout_dest = $params['timeout'] ?? $params['default'] ?? 'i';
		$invalid_dest = $params['invalid'] ?? $timeout_dest;
		$context = $this->contextName('ivr', $params);
		$desc = "IVR menu on extension {$ext}";

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		// Main extension — answer, play greeting, wait for digit
		$apps = [
			'Answer()',
			'Wait(1)',
		];
		// Use Background to play greeting and listen for DTMF
		$apps[] = "Background({$greeting})";
		$apps[] = 'WaitExten(5)';
		$lines[] = implode("\n", $this->buildExten($ext, $apps));

		// Option extensions
		foreach ($options as $digit => $dest) {
			$destStr = is_numeric($dest) ? "from-internal,{$dest},1" : $dest;
			$lines[] = "exten => {$digit},1,Goto({$destStr})";
		}

		// Invalid and timeout
		$lines[] = "";
		$lines[] = "exten => i,1,Playback(pbx-invalid)";
		$lines[] = "exten => i,n,Goto({$context},{$ext},1)";
		$lines[] = "exten => t,1,Playback(vm-goodbye)";
		$lines[] = "exten => t,n,Hangup()";

		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class TimeRouteTemplate extends TemplateBase {
	public function id() { return 'time-route'; }
	public function name() { return 'Time-Based Routing'; }
	public function description() { return 'Route calls differently based on time of day — business hours vs after hours.'; }
	public function examples() { return [
		'route calls to ring group 600 during business hours and voicemail 1001 after hours',
		'time route for 1001 9am-5pm monday-friday to 600 otherwise to voicemail',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		$ext = $params['extension'] ?? $params['did'] ?? null;
		if ($ext !== null && $ext !== 's' && !$this->isDigits($ext)) {
			return "time-route: 'extension'/'did' must be digits or 's'";
		}
		foreach (['business_dest', 'after_dest'] as $key) {
			if (!isset($params[$key])) return "time-route: '{$key}' is required";
			if (!$this->isDestination($params[$key])) {
				return "time-route: '{$key}' = '{$params[$key]}' is not a valid destination";
			}
		}
		if (isset($params['start_time']) && !$this->isTime($params['start_time'])) {
			return "time-route: 'start_time' must be HH:MM";
		}
		if (isset($params['end_time']) && !$this->isTime($params['end_time'])) {
			return "time-route: 'end_time' must be HH:MM";
		}
		if (isset($params['days']) && !$this->isDays($params['days'])) {
			return "time-route: 'days' must be a day spec (e.g. mon-fri, mon|wed|fri, *)";
		}
		return true;
	}

	public function generate($params) {
		$ext = $params['extension'] ?? $params['did'] ?? 's';
		$biz_dest = $params['business_dest'];
		$after_dest = $params['after_dest'];
		$start = $params['start_time'] ?? '09:00';
		$end = $params['end_time'] ?? '17:00';
		$days = $params['days'] ?? 'mon-fri';
		$context = $this->contextName('timeroute', $params);
		$desc = "Time routing: {$start}-{$end} {$days} → {$biz_dest}, otherwise → {$after_dest}";

		$bizStr = is_numeric($biz_dest) ? "from-internal,{$biz_dest},1" : $biz_dest;
		$afterStr = is_numeric($after_dest) ? "from-internal,{$after_dest},1" : $after_dest;

		// Handle voicemail destinations
		if (stripos($after_dest, 'voicemail') !== false || stripos($after_dest, 'vm') !== false) {
			$vmExt = preg_replace('/[^0-9]/', '', $after_dest) ?: $ext;
			$afterStr = "ext-local,vmu{$vmExt},1";
		}
		if (stripos($biz_dest, 'voicemail') !== false) {
			$vmExt = preg_replace('/[^0-9]/', '', $biz_dest) ?: $ext;
			$bizStr = "ext-local,vmu{$vmExt},1";
		}

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";
		$lines[] = "exten => {$ext},1,GotoIfTime({$start}-{$end},{$days},*,*?open,1)";
		$lines[] = "exten => {$ext},n,Goto({$afterStr})";
		$lines[] = "";
		$lines[] = "exten => open,1,Goto({$bizStr})";

		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class WebhookTemplate extends TemplateBase {
	public function id() { return 'webhook'; }
	public function name() { return 'Webhook / HTTP Notification'; }
	public function description() { return 'Send call data to an external URL via HTTP at a point in the call flow (on answer, on hangup, etc).'; }
	public function examples() { return [
		'after every call send caller number and duration to https://hooks.slack.com/xxx',
		'when a call is answered on 1001 POST to https://api.example.com/call-start',
		'send a webhook to our crm when calls end',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (!isset($params['url']) || !$this->isUrl($params['url'])) {
			return "webhook: 'url' is required and must be a plain http(s) URL (no \${...} placeholders, no backticks)";
		}
		if (isset($params['event']) && !$this->inSet($params['event'], ['hangup', 'answer', 'all'])) {
			return "webhook: 'event' must be one of: hangup, answer, all";
		}
		if (isset($params['extension']) && !$this->isDigits($params['extension']) && $params['extension'] !== '_X.') {
			return "webhook: 'extension' must be digits or '_X.'";
		}
		if (isset($params['method']) && !$this->inSet($params['method'], ['POST', 'GET', 'PUT', 'DELETE', 'PATCH'])) {
			return "webhook: 'method' must be one of: POST, GET, PUT, DELETE, PATCH";
		}
		if (isset($params['fields'])) {
			if (!is_array($params['fields'])) return "webhook: 'fields' must be an array of identifiers";
			foreach ($params['fields'] as $f) {
				if (!$this->isIdentifier($f)) return "webhook: fields entry '{$f}' must be alphanumeric/_/-";
			}
		}
		return true;
	}

	public function generate($params) {
		$url = $params['url'];
		$event = $params['event'] ?? 'hangup'; // hangup, answer, all
		$ext = $params['extension'] ?? '_X.';
		$fields = $params['fields'] ?? ['caller', 'destination', 'duration', 'disposition'];
		$method = strtoupper($params['method'] ?? 'POST');
		$context = $this->contextName('webhook', ['extension' => $event]);
		$desc = "Webhook on {$event}: {$method} {$url}";

		// Build the CURL data string with channel variables
		$fieldMap = [
			'caller' => '${CALLERID(num)}',
			'callerid' => '${CALLERID(num)}',
			'callername' => '${CALLERID(name)}',
			'destination' => '${EXTEN}',
			'dest' => '${EXTEN}',
			'duration' => '${CDR(duration)}',
			'billsec' => '${CDR(billsec)}',
			'disposition' => '${CDR(disposition)}',
			'uniqueid' => '${UNIQUEID}',
			'channel' => '${CHANNEL}',
			'timestamp' => '${STRFTIME(${EPOCH},,%Y-%m-%dT%H:%M:%S)}',
			'did' => '${FROM_DID}',
			'accountcode' => '${CDR(accountcode)}',
		];

		$postParts = [];
		foreach ($fields as $f) {
			$f = strtolower(trim($f));
			$var = $fieldMap[$f] ?? '${' . strtoupper($f) . '}';
			$postParts[] = "{$f}={$var}";
		}
		$postData = implode('&', $postParts);

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		if ($event === 'hangup') {
			$lines[] = "exten => h,1,Set(CURL_RESULT=\${CURL({$url},{$postData})})";
			$lines[] = "exten => h,n,NoOp(Webhook sent: \${CURL_RESULT})";
		} elseif ($event === 'answer') {
			$apps = [
				"Set(CURL_RESULT=\${CURL({$url},{$postData})})",
				"NoOp(Webhook sent: \${CURL_RESULT})",
			];
			$lines[] = implode("\n", $this->buildExten($ext, $apps));
		} else {
			// Generic — caller provides context to include this in
			$apps = [
				"Set(CURL_RESULT=\${CURL({$url},{$postData})})",
				"NoOp(Webhook sent: \${CURL_RESULT})",
			];
			$lines[] = implode("\n", $this->buildExten($ext, $apps));
		}

		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class ApiLookupTemplate extends TemplateBase {
	public function id() { return 'api-lookup'; }
	public function name() { return 'API Lookup'; }
	public function description() { return 'Query an external API during a call and use the response to set variables, announce info, or route the call.'; }
	public function examples() { return [
		'when someone calls 1001 look up their number at https://crm.example.com/lookup and tell the agent who is calling',
		'look up caller at https://api.example.com/customer?phone=callerid and set the caller name',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		// URL may contain {callerid}/{CALLERID}/{caller}/{phone} placeholders that
		// the template substitutes pre-generation. Substitute a numeric stub before
		// validating so the placeholder doesn't trip filter_var.
		if (!isset($params['url'])) return "api-lookup: 'url' is required";
		$urlForValidation = str_replace(['{callerid}', '{CALLERID}', '{caller}', '{phone}'], '5551234567', $params['url']);
		if (!$this->isUrl($urlForValidation)) {
			return "api-lookup: 'url' must be a plain http(s) URL (placeholders {callerid}/{caller}/{phone} are allowed)";
		}
		if (isset($params['extension']) && !$this->isDigits($params['extension']) && $params['extension'] !== '_X.') {
			return "api-lookup: 'extension' must be digits or '_X.'";
		}
		if (isset($params['response_field']) && !$this->isIdentifier($params['response_field'])) {
			return "api-lookup: 'response_field' must be alphanumeric/_/-";
		}
		if (isset($params['action']) && !$this->inSet($params['action'], ['set-callerid', 'set-name', 'announce', 'route'])) {
			return "api-lookup: 'action' must be one of: set-callerid, set-name, announce, route";
		}
		if (isset($params['next_dest']) && !$this->isDestination($params['next_dest'])) {
			return "api-lookup: 'next_dest' is not a valid destination";
		}
		return true;
	}

	public function generate($params) {
		$url = $params['url'];
		$ext = $params['extension'] ?? '_X.';
		$response_field = $params['response_field'] ?? 'name';
		$action = $params['action'] ?? 'set-callerid'; // set-callerid, announce, route
		$next_dest = $params['next_dest'] ?? null;
		$context = $this->contextName('apilookup', $params);

		// Build URL with callerid substitution
		$apiUrl = str_replace(['{callerid}', '{CALLERID}', '{caller}', '{phone}'],
			'${CALLERID(num)}', $url);
		// If URL doesn't have a variable, append callerid as query param
		if (strpos($apiUrl, '${') === false) {
			$separator = (strpos($apiUrl, '?') !== false) ? '&' : '?';
			$apiUrl .= "{$separator}phone=\${CALLERID(num)}";
		}

		$desc = "API lookup: {$url} → {$action}";
		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		$apps = [
			"Set(API_RESPONSE=\${CURL({$apiUrl})})",
			"Set(LOOKUP_RESULT=\${JSON_DECODE(API_RESPONSE,{$response_field})})",
		];

		switch ($action) {
			case 'set-callerid':
			case 'set-name':
				$apps[] = 'GotoIf($["${LOOKUP_RESULT}" = ""]?skip)';
				$apps[] = 'Set(CALLERID(name)=${LOOKUP_RESULT})';
				$apps[] = 'NoOp(Caller identified as ${LOOKUP_RESULT})';
				$apps[] = 'n(skip),NoOp(No lookup result)';
				break;

			case 'announce':
				$apps[] = 'Answer()';
				$apps[] = 'GotoIf($["${LOOKUP_RESULT}" = ""]?skip)';
				$apps[] = 'SayAlpha(${LOOKUP_RESULT})';
				$apps[] = 'n(skip),NoOp(No lookup result)';
				break;

			case 'route':
				$apps[] = 'GotoIf($["${LOOKUP_RESULT}" = ""]?default)';
				$apps[] = 'Goto(from-internal,${LOOKUP_RESULT},1)';
				$default = $next_dest ? "Goto(from-internal,{$next_dest},1)" : 'Hangup()';
				$apps[] = "n(default),{$default}";
				break;
		}

		if ($next_dest && $action !== 'route') {
			$apps[] = "Goto(from-internal,{$next_dest},1)";
		}

		$lines[] = implode("\n", $this->buildExten($ext, $apps));
		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class CallerIdRouteTemplate extends TemplateBase {
	public function id() { return 'cid-route'; }
	public function name() { return 'Caller ID Routing'; }
	public function description() { return 'Route calls based on the caller\'s number — area code, specific number, or pattern.'; }
	public function examples() { return [
		'when calls come from 212 area code route to ring group 700',
		'route calls from 5551234567 directly to extension 1001',
		'send all 800 numbers to queue 400',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (empty($params['rules']) || !is_array($params['rules'])) {
			return "cid-route: 'rules' is required and must be a list of {pattern, dest}";
		}
		foreach ($params['rules'] as $i => $rule) {
			if (!is_array($rule) || empty($rule['pattern']) || empty($rule['dest'])) {
				return "cid-route: rules[{$i}] needs both 'pattern' and 'dest'";
			}
			// Pattern is interpolated raw into a GotoIf string comparison; restrict
			// to caller-ID-like values (digits, optionally with leading +).
			if (!preg_match('/^\+?\d+$/', (string)$rule['pattern'])) {
				return "cid-route: rules[{$i}].pattern '{$rule['pattern']}' must be digits (optionally with leading +)";
			}
			if (!$this->isDestination($rule['dest'])) {
				return "cid-route: rules[{$i}].dest '{$rule['dest']}' is not a valid destination";
			}
		}
		if (isset($params['default']) && !$this->isDestination($params['default'])) {
			return "cid-route: 'default' is not a valid destination";
		}
		if (isset($params['extension']) && $params['extension'] !== 's' && !$this->isDigits($params['extension'])) {
			return "cid-route: 'extension' must be digits or 's'";
		}
		return true;
	}

	public function generate($params) {
		$rules = $params['rules']; // [['pattern' => '212', 'dest' => '700'], ...]
		$default_dest = $params['default'] ?? null;
		$ext = $params['extension'] ?? 's';
		$context = $this->contextName('cidroute', $params);
		$desc = "Caller ID routing with " . count($rules) . " rules";

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		$apps = ['NoOp(Caller ID routing for ${CALLERID(num)})'];

		foreach ($rules as $i => $rule) {
			$pattern = $rule['pattern'];
			$dest = $rule['dest'];
			$destStr = is_numeric($dest) ? "from-internal,{$dest},1" : $dest;
			$label = "rule{$i}";

			// Area code match (3 digits) — check first 3 digits
			if (strlen($pattern) <= 3) {
				$apps[] = "GotoIf(\$[\"\${CALLERID(num):0:3}\" = \"{$pattern}\"]?{$label})";
			} else {
				// Full number match
				$apps[] = "GotoIf(\$[\"\${CALLERID(num)}\" = \"{$pattern}\"]?{$label})";
			}
		}

		// Default
		if ($default_dest) {
			$defaultStr = is_numeric($default_dest) ? "from-internal,{$default_dest},1" : $default_dest;
			$apps[] = "Goto({$defaultStr})";
		} else {
			$apps[] = 'Goto(from-internal,${EXTEN},1)';
		}

		// Rule destinations
		foreach ($rules as $i => $rule) {
			$dest = $rule['dest'];
			$destStr = is_numeric($dest) ? "from-internal,{$dest},1" : $dest;
			$apps[] = "n(rule{$i}),Goto({$destStr})";
		}

		$lines[] = implode("\n", $this->buildExten($ext, $apps));
		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class FailoverTemplate extends TemplateBase {
	public function id() { return 'failover'; }
	public function name() { return 'Ring with Failover'; }
	public function description() { return 'Ring a series of extensions/numbers in order — if one doesn\'t answer, try the next.'; }
	public function examples() { return [
		'ring 1001 for 15 seconds then try 1002 then go to voicemail',
		'failover chain: 1001, 1002, 1003, then voicemail 1001',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (empty($params['steps']) || !is_array($params['steps'])) {
			return "failover: 'steps' is required and must be a list of {dest, timeout}";
		}
		foreach ($params['steps'] as $i => $step) {
			if (!is_array($step) || empty($step['dest'])) {
				return "failover: steps[{$i}] needs 'dest'";
			}
			// dest reaches Dial() either as PJSIP/<digits> or PJSIP/<digits>@from-internal
			// so it must be safe for an Asterisk identifier — digits or alphanumerics-with-dashes.
			if (!preg_match('/^[a-zA-Z0-9._-]+$/', (string)$step['dest'])) {
				return "failover: steps[{$i}].dest '{$step['dest']}' must be alphanumeric/_/.";
			}
			if (isset($step['timeout']) && (!is_numeric($step['timeout']) || (int)$step['timeout'] <= 0 || (int)$step['timeout'] > 600)) {
				return "failover: steps[{$i}].timeout must be 1-600 seconds";
			}
		}
		if (isset($params['final'])) {
			$f = $params['final'];
			if ($f !== 'hangup' && !$this->isDigits($f) && stripos($f, 'voicemail') === false && stripos($f, 'vm') === false) {
				return "failover: 'final' must be 'hangup', digits, or contain 'voicemail'/'vm'";
			}
		}
		if (isset($params['extension']) && $params['extension'] !== 's' && !$this->isDigits($params['extension'])) {
			return "failover: 'extension' must be digits or 's'";
		}
		return true;
	}

	public function generate($params) {
		$steps = $params['steps']; // [['dest' => '1001', 'timeout' => 15], ...]
		$final = $params['final'] ?? 'hangup'; // voicemail, hangup, extension
		$ext = $params['extension'] ?? 's';
		$context = $this->contextName('failover', $params);
		$desc = "Failover ring chain: " . implode(' → ', array_column($steps, 'dest'));

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		$apps = ['Answer()'];

		foreach ($steps as $step) {
			$dest = $step['dest'];
			$timeout = $step['timeout'] ?? 15;
			if (is_numeric($dest)) {
				$apps[] = "Dial(PJSIP/{$dest},{$timeout})";
			} else {
				// External number
				$apps[] = "Dial(PJSIP/{$dest}@from-internal,{$timeout})";
			}
		}

		// Final destination
		if (stripos($final, 'voicemail') !== false || stripos($final, 'vm') !== false) {
			$vmExt = preg_replace('/[^0-9]/', '', $final) ?: ($steps[0]['dest'] ?? '1001');
			$apps[] = "VoiceMail({$vmExt}@default,u)";
		} elseif ($final === 'hangup') {
			$apps[] = 'Playback(vm-goodbye)';
			$apps[] = 'Hangup()';
		} elseif (is_numeric($final)) {
			$apps[] = "Goto(from-internal,{$final},1)";
		}

		$lines[] = implode("\n", $this->buildExten($ext, $apps));
		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class FeatureCodeTemplate extends TemplateBase {
	public function id() { return 'feature-code'; }
	public function name() { return 'Custom Feature Code'; }
	public function description() { return 'Create a star code that does something when dialed — toggle a setting, play info, trigger an action.'; }
	public function examples() { return [
		'create feature code *99 that reads back the caller their own extension number',
		'make *71 forward my calls to 5551234567',
		'create star code *80 that plays the current time',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (empty($params['code']) || !$this->isFeatureCode($params['code'])) {
			return "feature-code: 'code' is required and must be '*' followed by digits (e.g. *99)";
		}
		if (empty($params['action'])) {
			return "feature-code: 'action' is required";
		}
		$validActions = ['readback', 'read-extension', 'time', 'say-time', 'forward', 'call-forward',
			'forward-cancel', 'announce', 'playback', 'speed-dial', 'echo-test'];
		if (!$this->inSet($params['action'], $validActions)) {
			return "feature-code: 'action' must be one of: " . implode(', ', $validActions);
		}
		if (isset($params['action_params']) && is_array($params['action_params'])) {
			$ap = $params['action_params'];
			if (isset($ap['destination']) && !$this->isDigits($ap['destination'])) {
				return "feature-code: action_params.destination must be digits";
			}
			if (isset($ap['file']) && !$this->isSoundFile($ap['file'])) {
				return "feature-code: action_params.file must be a sound-file reference";
			}
		}
		return true;
	}

	public function generate($params) {
		$code = $params['code']; // e.g. *99
		$action = $params['action']; // readback, forward, time, announce, toggle-dnd
		$action_params = $params['action_params'] ?? [];
		$context = $this->contextName('feature', ['extension' => str_replace('*', 'star', $code)]);
		$desc = "Feature code {$code}: {$action}";

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		$apps = ['Answer()', 'Wait(1)'];

		switch ($action) {
			case 'readback':
			case 'read-extension':
				$apps[] = 'SayDigits(${CALLERID(num)})';
				$apps[] = 'Hangup()';
				break;

			case 'time':
			case 'say-time':
				$apps[] = 'SayUnixTime(,,IMp)';
				$apps[] = 'Hangup()';
				break;

			case 'forward':
			case 'call-forward':
				$dest = $action_params['destination'] ?? '0';
				$apps[] = "Set(DB(CF/\${CALLERID(num)})={$dest})";
				$apps[] = 'Playback(beep)';
				$apps[] = 'SayDigits(' . $dest . ')';
				$apps[] = 'Hangup()';
				break;

			case 'forward-cancel':
				$apps[] = 'Set(DB_DELETE(CF/${CALLERID(num)})=)';
				$apps[] = 'Playback(beep)';
				$apps[] = 'Hangup()';
				break;

			case 'announce':
			case 'playback':
				$file = $action_params['file'] ?? 'beep';
				$apps[] = "Playback({$file})";
				$apps[] = 'Hangup()';
				break;

			case 'speed-dial':
				$dest = $action_params['destination'] ?? '';
				$apps[] = "Goto(from-internal,{$dest},1)";
				break;

			case 'echo-test':
				$apps[] = 'Echo()';
				break;

			default:
				$apps[] = 'Playback(beep)';
				$apps[] = 'Hangup()';
		}

		$lines[] = implode("\n", $this->buildExten($code, $apps));
		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class AnnouncementTemplate extends TemplateBase {
	public function id() { return 'announcement'; }
	public function name() { return 'Announcement + Transfer'; }
	public function description() { return 'Play an announcement or message, then transfer the caller somewhere.'; }
	public function examples() { return [
		'on extension 8000 play a greeting then transfer to ring group 600',
		'announce "please hold" on 9000 then send to queue 400',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (!isset($params['extension']) || !$this->isDigits($params['extension'])) {
			return "announcement: 'extension' is required and must be digits";
		}
		if (!isset($params['destination']) || !$this->isDestination($params['destination'])) {
			return "announcement: 'destination' is required and must be a valid destination";
		}
		if (isset($params['recording']) && !$this->isSoundFile($params['recording'])) {
			return "announcement: 'recording' must be a sound-file reference";
		}
		return true;
	}

	public function generate($params) {
		$ext = $params['extension'];
		$recording = $params['recording'] ?? 'custom/frogman-announcement';
		$dest = $params['destination'];
		$context = $this->contextName('announce', $params);
		$desc = "Announcement on {$ext} then → {$dest}";

		$destStr = is_numeric($dest) ? "from-internal,{$dest},1" : $dest;

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		$apps = [
			'Answer()',
			'Wait(1)',
			"Playback({$recording})",
			"Goto({$destStr})",
		];

		$lines[] = implode("\n", $this->buildExten($ext, $apps));
		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
class CollectAndQueryTemplate extends TemplateBase {
	public function id() { return 'collect-query'; }
	public function name() { return 'Collect Digits + API Query'; }
	public function description() { return 'Prompt the caller to enter digits, send them to an API, and act on the response.'; }
	public function examples() { return [
		'on extension 9000 ask for a 5 digit account number send it to https://billing.example.com/lookup and read back the balance',
		'collect a PIN on 8500 and verify it at https://auth.example.com/check',
	];}

	public function validateParams($params) {
		if ($err = $this->rejectDangerous($params)) return $err;
		if (!isset($params['extension']) || !$this->isDigits($params['extension'])) {
			return "collect-query: 'extension' is required and must be digits";
		}
		if (!isset($params['url']) || !$this->isUrl($params['url'])) {
			return "collect-query: 'url' is required and must be a plain http(s) URL";
		}
		if (isset($params['digits']) && (!is_numeric($params['digits']) || (int)$params['digits'] < 1 || (int)$params['digits'] > 32)) {
			return "collect-query: 'digits' must be 1-32";
		}
		if (isset($params['response_field']) && !$this->isIdentifier($params['response_field'])) {
			return "collect-query: 'response_field' must be alphanumeric/_/-";
		}
		if (isset($params['prompt']) && !$this->isSoundFile($params['prompt'])) {
			return "collect-query: 'prompt' must be a sound-file reference";
		}
		if (isset($params['action']) && !$this->inSet($params['action'], ['readback', 'route', 'verify'])) {
			return "collect-query: 'action' must be one of: readback, route, verify";
		}
		if (isset($params['next_dest']) && !$this->isDestination($params['next_dest'])) {
			return "collect-query: 'next_dest' is not a valid destination";
		}
		return true;
	}

	public function generate($params) {
		$ext = $params['extension'];
		$digits = $params['digits'] ?? 5;
		$url = $params['url'];
		$response_field = $params['response_field'] ?? 'result';
		$prompt = $params['prompt'] ?? 'enter-ext-of-person';
		$action = $params['action'] ?? 'readback'; // readback, route, transfer
		$next_dest = $params['next_dest'] ?? null;
		$context = $this->contextName('collect', $params);
		$desc = "Collect {$digits} digits on {$ext}, query {$url}";

		$lines = [];
		$lines[] = $this->header($desc);
		$lines[] = "[{$context}]";

		$apps = [
			'Answer()',
			'Wait(1)',
			"Read(DIGITS,{$prompt},{$digits},,2,10)",
			'GotoIf($["${DIGITS}" = ""]?invalid)',
			"Set(API_RESPONSE=\${CURL({$url}?input=\${DIGITS})})",
			"Set(RESULT=\${JSON_DECODE(API_RESPONSE,{$response_field})})",
		];

		switch ($action) {
			case 'readback':
				$apps[] = 'GotoIf($["${RESULT}" = ""]?notfound)';
				$apps[] = 'SayAlpha(${RESULT})';
				if ($next_dest) {
					$apps[] = "Goto(from-internal,{$next_dest},1)";
				} else {
					$apps[] = 'Hangup()';
				}
				break;

			case 'route':
				$apps[] = 'GotoIf($["${RESULT}" = ""]?notfound)';
				$apps[] = 'Goto(from-internal,${RESULT},1)';
				break;

			case 'verify':
				$apps[] = 'GotoIf($["${RESULT}" = "ok"]?pass)';
				$apps[] = 'GotoIf($["${RESULT}" = "true"]?pass)';
				$apps[] = 'GotoIf($["${RESULT}" = "1"]?pass)';
				$apps[] = 'Playback(access-denied)';
				$apps[] = 'Hangup()';
				$apps[] = 'n(pass),Playback(auth-thankyou)';
				if ($next_dest) {
					$apps[] = "Goto(from-internal,{$next_dest},1)";
				}
				break;
		}

		$apps[] = 'n(invalid),Playback(pbx-invalid)';
		$apps[] = 'Hangup()';
		$apps[] = 'n(notfound),Playback(number-not-in-db)';
		$apps[] = 'Hangup()';

		$lines[] = implode("\n", $this->buildExten($ext, $apps));
		return implode("\n", $lines);
	}
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

/**
 * Template registry.
 */
class TemplateRegistry {
	private static $templates = null;

	public static function all() {
		if (self::$templates === null) {
			self::$templates = [];
			$classes = [
				IvrTemplate::class,
				TimeRouteTemplate::class,
				WebhookTemplate::class,
				ApiLookupTemplate::class,
				CallerIdRouteTemplate::class,
				FailoverTemplate::class,
				FeatureCodeTemplate::class,
				AnnouncementTemplate::class,
				CollectAndQueryTemplate::class,
			];
			foreach ($classes as $cls) {
				$t = new $cls();
				self::$templates[$t->id()] = $t;
			}
		}
		return self::$templates;
	}

	public static function get($id) {
		$all = self::all();
		return $all[$id] ?? null;
	}

	public static function listTemplates() {
		$result = [];
		foreach (self::all() as $t) {
			$result[] = [
				'id' => $t->id(),
				'name' => $t->name(),
				'description' => $t->description(),
				'examples' => $t->examples(),
			];
		}
		return $result;
	}
}
