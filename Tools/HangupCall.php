<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class HangupCall extends AbstractTool {
	public function name() { return 'fm_hangup_call'; }
	public function description() { return 'Hang up a specific channel. Params: channel (required, from active calls list). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		// AMI-framing injection defense: reject CR/LF/NUL and other out-of-charset bytes that
		// could inject AMI headers. Charset covers real Asterisk channel shapes incl.
		// PJSIP/1001-0000abcd, SIP/trunk-00000001, Local/1001@from-internal-0000004f;2,
		// and feature-code / E.164 legs like Local/*97@... and Local/+15551234567@...
		if (!preg_match('/^[a-zA-Z0-9._\/@;*#+-]+$/', $params['channel'])) return 'Parameter "channel" contains invalid characters';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would hang up channel {$params['channel']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->Hangup($params['channel']);
		return ['dry_run' => false, 'message' => "Channel {$params['channel']} hung up", 'result' => $res];
	}
}
