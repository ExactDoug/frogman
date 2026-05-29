<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class TransferCall extends AbstractTool {
	public function name() { return 'fm_transfer_call'; }
	public function description() { return 'Transfer a live call to another extension. Params: channel (required), dest (destination extension, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		if (empty($params['dest'])) return 'Parameter "dest" is required';
		// AMI-framing injection defense: both `channel` and `dest` flow into AMI Redirect headers.
		// Channel charset covers real Asterisk shapes incl. Local/1001@from-internal-0000004f;2
		// and feature-code / E.164 legs like Local/*97@... and Local/+15551234567@...
		if (!preg_match('/^[a-zA-Z0-9._\/@;*#+-]+$/', $params['channel'])) return 'Parameter "channel" contains invalid characters';
		if (!preg_match('/^[0-9*#+]{1,20}$/', (string)$params['dest'])) return 'Parameter "dest" must be digits or feature-code characters (* # +)';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would transfer {$params['channel']} to {$params['dest']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->Redirect($params['channel'], 'from-internal', $params['dest'], '1');
		return ['dry_run' => false, 'message' => "Call transferred to {$params['dest']}", 'result' => $res];
	}
}
