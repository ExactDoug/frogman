<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class MonitorCall extends AbstractTool {
	public function name() { return 'fm_monitor_call'; }
	public function description() { return 'Start recording a live call. Params: channel (required). Requires confirm:true.'; }
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
		$channel = $params['channel'];
		if (!$confirm) return ['dry_run' => true, 'message' => "Would start recording on {$channel}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$filename = 'frogman-' . date('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9]/', '', $channel);
		$res = $astman->send_request('MixMonitor', ['Channel' => $channel, 'File' => "/var/spool/asterisk/monitor/{$filename}.wav"]);
		return ['dry_run' => false, 'message' => "Recording started on {$channel}", 'filename' => $filename, 'result' => $res];
	}
}
