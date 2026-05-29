<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class MuteCall extends AbstractTool {
	public function name() { return 'fm_mute_call'; }
	public function description() { return 'Mute or unmute a channel. Params: channel (required), direction (in/out/all, default all), state (on/off, default on). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		// AMI-framing injection defense: reject CR/LF/NUL and other out-of-charset bytes that
		// could inject AMI headers. Charset covers real Asterisk channel shapes incl.
		// PJSIP/1001-0000abcd, SIP/trunk-00000001, Local/1001@from-internal-0000004f;2,
		// and feature-code / E.164 legs like Local/*97@... and Local/+15551234567@...
		if (!preg_match('/^[a-zA-Z0-9._\/@;*#+-]+$/', $params['channel'])) return 'Parameter "channel" contains invalid characters';
		// `direction` also flows into the AMI MuteAudio header — constrain to the enum.
		if (isset($params['direction']) && !in_array($params['direction'], ['in', 'out', 'all'], true)) return 'Parameter "direction" must be in, out, or all';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$state = ($params['state'] ?? 'on') === 'on' ? 'on' : 'off';
		$dir = $params['direction'] ?? 'all';
		$label = $state === 'on' ? 'Mute' : 'Unmute';
		if (!$confirm) return ['dry_run' => true, 'message' => "Would {$label} {$params['channel']} ({$dir}). Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('MuteAudio', ['Channel' => $params['channel'], 'Direction' => $dir, 'State' => $state]);
		return ['dry_run' => false, 'message' => "{$label}d {$params['channel']}", 'result' => $res];
	}
}
