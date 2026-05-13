<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class UpdateExtension extends AbstractTool {

	public function name() {
		return 'fm_update_extension';
	}

	public function description() {
		return 'Update an existing extension in place. Params: ext (required), plus any fields to change: name, secret, outboundcid. Voicemail, follow-me, Userman, and every other extension setting are preserved. Requires confirm:true to execute.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		return true;
	}

	public function requiredPermission() {
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$ext = $params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$device = $this->freepbx->Core->getDevice($ext);
		if (empty($device)) {
			throw new \Exception("Extension {$ext} not found");
		}
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			throw new \Exception("Extension {$ext} has a device but no user record — inconsistent state, edit aborted");
		}

		$userChanges = [];
		$deviceChanges = [];
		if (isset($params['name']) && $params['name'] !== ($user['name'] ?? '')) {
			$userChanges['name'] = ['from' => $user['name'] ?? '', 'to' => $params['name']];
		}
		if (isset($params['outboundcid']) && $params['outboundcid'] !== ($user['outboundcid'] ?? '')) {
			$userChanges['outboundcid'] = ['from' => $user['outboundcid'] ?? '', 'to' => $params['outboundcid']];
		}
		if (isset($params['secret']) && $params['secret'] !== ($device['secret'] ?? '')) {
			$deviceChanges['secret'] = ['from' => '***', 'to' => '***'];
		}

		$allChanges = array_merge($userChanges, $deviceChanges);
		if (empty($allChanges)) {
			return [
				'dry_run' => false,
				'message' => "No changes detected for extension {$ext}",
			];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would update extension {$ext}. Pass confirm:true to execute.",
				'changes' => $allChanges,
				'preserved' => 'Voicemail, follow-me, Userman, recording prefs, and every unchanged setting will be preserved.',
			];
		}

		// FreePBX's own edit flow is delete-with-editmode + add-with-editmode. The
		// editmode flag tells Core to skip the AstDB teardown that a real delete
		// would do, so registrations, hint state, and device→user links survive
		// the cycle. We seed the add from get*() output so every field we didn't
		// touch carries through untouched.
		if (!empty($userChanges)) {
			$userVars = $user;
			if (isset($userChanges['name'])) {
				$userVars['name'] = $params['name'];
			}
			if (isset($userChanges['outboundcid'])) {
				$userVars['outboundcid'] = $params['outboundcid'];
			}
			$userVars['extension'] = $ext;

			$this->freepbx->Core->delUser($ext, true);
			$this->freepbx->Core->addUser($ext, $userVars, true);
		}

		// addDevice expects the wrapped ['key' => ['value' => x]] shape that
		// generateDefaultDeviceSettings produces; getDevice returns flat. Wrap
		// before calling.
		if (!empty($deviceChanges)) {
			$deviceVars = [];
			foreach ($device as $k => $v) {
				$deviceVars[$k] = ['value' => $v];
			}
			if (isset($deviceChanges['secret'])) {
				$deviceVars['secret']['value'] = $params['secret'];
			}

			$this->freepbx->Core->delDevice($ext, true);
			$this->freepbx->Core->addDevice($ext, $device['tech'], $deviceVars, true);
		}

		return [
			'dry_run' => false,
			'message' => "Extension {$ext} updated successfully",
			'changes' => $allChanges,
			'needs_reload' => true,
		];
	}
}
