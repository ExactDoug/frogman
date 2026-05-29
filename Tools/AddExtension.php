<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AddExtension extends AbstractTool {

	public function name() {
		return 'fm_add_extension';
	}

	public function description() {
		return 'Create a new PJSIP extension. Params: ext (required), name (required), secret, vm (yes/no), vmpwd, email, umpassword (optional UCP password — auto-generated if omitted). Requires confirm:true to execute.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		if (empty($params['name'])) {
			return 'Parameter "name" is required';
		}
		return true;
	}

	public function requiredPermission() {
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$name = $params['name'];
		// SEC-3: register secrets so they're scrubbed from any error text (downstream
		// exception in runTool's catch, or the userman 'failed:' notes on the success path).
		$secret = $this->markSecret($params['secret'] ?? bin2hex(random_bytes(8)));
		$vm = $params['vm'] ?? 'no';
		// SEC-3: vmpwd is forwarded into processQuickCreate()/voicemail — register it too so a
		// downstream exception echoing it gets scrubbed. markSecret no-ops on the empty default.
		$vmpwd = $this->markSecret($params['vmpwd'] ?? '');
		$email = $params['email'] ?? '';
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// Check if extension already exists
		$existing = $this->freepbx->Core->getDevice($ext);
		if (!empty($existing)) {
			throw new \Exception("Extension {$ext} already exists");
		}

		$preview = [
			'action' => 'add_extension',
			'extension' => $ext,
			'name' => $name,
			'tech' => 'pjsip',
			'secret' => $secret,
			'voicemail' => $vm,
		];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would create extension {$ext} ({$name}) as PJSIP. Pass confirm:true to execute.",
				'preview' => $preview,
			];
		}

		$data = [
			'extension' => $ext,
			'name' => $name,
			'tech' => 'pjsip',
			'outboundcid' => '',
			'secret' => $secret,
			'vm' => $vm,
			'vmpwd' => $vmpwd,
			'email' => $email,
		];

		$result = $this->freepbx->Core->processQuickCreate('pjsip', $ext, $data);

		if (empty($result['status'])) {
			throw new \Exception("Failed to create extension {$ext}");
		}

		// processQuickCreate creates the SIP extension but does NOT create the linked
		// User Manager user. Always create one so SC, UCP login, voicemail-to-email,
		// and other features have a target. Email is optional — if provided it's set
		// in extraData; otherwise the user is created without it and `set email <ext>`
		// can fill it in later.
		$umCreated = null;
		$umPasswordToReturn = null;
		$umPasswordWasGenerated = false;
		$userman = $this->freepbx->Userman;
		$existing = null;
		try { $existing = $userman->getUserByDefaultExtension($ext); } catch (\Throwable $e) {}
		$extraData = !empty($email) ? ['email' => $email] : [];
		$userId = null;
		try {
			if (empty($existing) || empty($existing['id'])) {
				if (!empty($params['umpassword'])) {
					$umPwd = $this->markSecret($params['umpassword']);
				} else {
					$umPwd = $this->markSecret(bin2hex(random_bytes(8)));
					$umPasswordWasGenerated = true;
				}
				$res = $userman->addUser($ext, $umPwd, $ext, $name, $extraData);
				if (!empty($res['status']) && !empty($res['id'])) {
					$userId = (int)$res['id'];
					$umCreated = !empty($email) ? 'created_with_email' : 'created';
					$umPasswordToReturn = $umPwd;
				} else {
					$umCreated = 'failed: ' . $this->scrubKnownSecrets($res['message'] ?? 'unknown error');
				}
			} elseif (!empty($email)) {
				$userman->updateUserExtraData((int)$existing['id'], ['email' => $email]);
				$userId = (int)$existing['id'];
				$umCreated = 'email_updated';
			} else {
				$userId = (int)$existing['id'];
				$umCreated = 'exists';
			}
		} catch (\Throwable $e) {
			$umCreated = 'failed: ' . $this->scrubKnownSecrets($e->getMessage());
		}

		// Wire UCP access the same way Userman::processQuickCreate does for GUI-created users:
		// link the user to their extension and add them to the directory's default groups.
		// Without these steps the userman row exists but UCP login fails — the user has no group
		// with UCP module access and no extension assignment to scope them to. Best-effort: a
		// failure here should not roll back successful user creation.
		if (!empty($userId)) {
			try {
				$userman->setGlobalSettingByID($userId, 'assigned', [$ext]);

				$directory = $userman->getDefaultDirectory();
				$defaultGroupIds = $userman->getDefaultGroups($directory['id'] ?? null);
				if (!empty($defaultGroupIds) && is_array($defaultGroupIds)) {
					foreach ($userman->getAllGroups() as $group) {
						if (!in_array($group['id'], $defaultGroupIds)) continue;
						$members = is_array($group['users'] ?? null) ? $group['users'] : [];
						if (in_array($userId, $members)) continue;
						$members[] = $userId;
						$userman->updateGroup(
							$group['id'],
							$group['groupname'],
							$group['groupname'],
							$group['description'],
							$members
						);
					}
				}

				$tempid = $userman->getCombinedModuleSettingByID($userId, 'ucp|template', 'templateid');
				if (!empty($tempid)) {
					$userman->updateUserUcpByTemplate($userId, $tempid);
				}
			} catch (\Throwable $e) {
				$umCreated = ($umCreated ?: 'created') . ' (ucp_wiring_failed: ' . $this->scrubKnownSecrets($e->getMessage()) . ')';
			}
		}

		$message = "Extension {$ext} ({$name}) created successfully";
		if (!empty($umPasswordToReturn)) {
			$genNote = $umPasswordWasGenerated ? ' (auto-generated)' : '';
			$resetHint = !empty($email)
				? "Reset later in User Manager or via the UCP \"Forgot Password\" link."
				: "Reset later in User Manager (no email on file, so the UCP \"Forgot Password\" link won't work until one is added).";
			// SEC-3: do NOT embed the password in this free-text message. redactSensitive()
			// matches by array KEY, so a secret inside `message` would persist UNredacted in
			// oc_audit_log.detail. The password is returned under the 'umpassword' key (which
			// IS redacted in the audit log) and surfaced to the user by the chat formatter's
			// fm_add_extension case and the raw API/MCP response.
			$message .= "\n\nA UCP password{$genNote} was set for this extension — see the credential below. {$resetHint}";
		}

		return [
			'dry_run' => false,
			'message' => $message,
			'extension' => $ext,
			'secret' => $secret,
			'needs_reload' => true,
			'userman' => $umCreated,
			'umpassword' => $umPasswordToReturn,
			'email' => $email ?: null,
		];
	}
}
