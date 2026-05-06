<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetRingtimer extends AbstractTool {
	public function name() { return 'fm_set_ringtimer'; }
	public function description() { return 'Set ring timeout for an extension. Params: ext (required), seconds (required, 0 for default/unlimited). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!isset($params['seconds'])) return 'Parameter "seconds" is required';
		if (!is_numeric($params['seconds']) || $params['seconds'] < 0) return 'Parameter "seconds" must be a positive number';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$seconds = (int)$params['seconds'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		$current = (int)($user['ringtimer'] ?? 0);
		if (!$confirm) {
			$label = $seconds == 0 ? 'default (unlimited)' : "{$seconds}s";
			$curLabel = $current == 0 ? 'default' : "{$current}s";
			return ['dry_run' => true, 'message' => "Would set ring timeout to {$label} on extension {$ext} ({$user['name']}). Current: {$curLabel}."];
		}

		// Round-trip through Core BMO so both the users table and astdb stay in sync.
		// Asterisk reads ringtimer from astdb at call time; writing only the MySQL row has no runtime effect.
		$settings = $user;
		$settings['extension'] = $ext;
		$settings['ringtimer'] = $seconds;
		$this->freepbx->Core->delUser($ext, true);
		$this->freepbx->Core->addUser($ext, $settings, true);

		$label = $seconds == 0 ? 'default' : "{$seconds}s";
		return ['dry_run' => false, 'message' => "Ring timeout set to {$label} on extension {$ext} ({$user['name']}).", 'needs_reload' => true];
	}
}
