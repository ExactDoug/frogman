<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetExtensionEmail extends AbstractTool {
	public function name() { return 'fm_set_extension_email'; }
	public function description() { return 'Set the email address on the User Manager user linked to an extension. Required for Sangoma Connect invites and voicemail-to-email. Requires confirm:true.'; }
	public function permissionLevel() { return self::PERM_WRITE; }

	public function validate($params) {
		if (empty($params['ext']) || !preg_match('/^\d+$/', (string)$params['ext'])) {
			throw new \InvalidArgumentException('ext must be a numeric extension');
		}
		if (empty($params['email']) || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('email must be a valid address');
		}
		return true;
	}

	public function execute($params, $context) {
		$ext = (string)$params['ext'];
		$email = trim($params['email']);
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$userman = $this->freepbx->Userman;
		$umUser = null;
		try { $umUser = $userman->getUserByDefaultExtension($ext); } catch (\Throwable $e) {}

		// Look up the extension name for use as description if we need to create the user
		$extName = '';
		try {
			$dev = $this->freepbx->Core->getDevice($ext);
			$extName = $dev['description'] ?? $dev['name'] ?? "Extension {$ext}";
		} catch (\Throwable $e) { $extName = "Extension {$ext}"; }

		$umExists = !empty($umUser) && !empty($umUser['id']);
		$umId = $umExists ? (int)$umUser['id'] : null;
		$currentEmail = $umExists ? (string)($umUser['email'] ?? '') : '';

		if (!$confirm) {
			if (!$umExists) {
				$msg = "Would create User Manager user for ext {$ext} ({$extName}) with email `{$email}`.";
			} elseif ($currentEmail) {
				$msg = "Would change email on ext {$ext} from `{$currentEmail}` to `{$email}`.";
			} else {
				$msg = "Would set email on ext {$ext} to `{$email}`.";
			}
			return [
				'dry_run' => true,
				'message' => $msg,
				'preflight' => [
					'ext' => $ext,
					'userman_id' => $umId,
					'will_create_user' => !$umExists,
					'current_email' => $currentEmail,
					'new_email' => $email,
				],
			];
		}

		try {
			if (!$umExists) {
				$umPwd = bin2hex(random_bytes(8));
				$userman->addUser($ext, $umPwd, $ext, $extName, ['email' => $email]);
				$reread = $userman->getUserByDefaultExtension($ext) ?: [];
				$umId = !empty($reread['id']) ? (int)$reread['id'] : null;
			} else {
				$userman->updateUserExtraData($umId, ['email' => $email]);
			}
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'error' => ($umExists ? 'updateUserExtraData' : 'addUser') . ' failed: ' . $e->getMessage(),
				'ext' => $ext,
				'userman_id' => $umId,
			];
		}

		// Also update the voicemail mailbox email so voicemail-to-email works.
		// Use updateMailbox(mailbox, settings) — granular update that only touches
		// the email field while preserving all other VM options. Earlier attempt
		// with saveVMSettingsByExtension flipped attach/saycid/envelope/delete
		// values incorrectly. Best-effort: if no mailbox exists yet, silently skip.
		$vmUpdated = false;
		try {
			$vmBox = $this->freepbx->Voicemail->getMailbox($ext, false);
			if (is_array($vmBox) && !empty($vmBox['pwd'])) {
				$vmBox['email'] = $email;
				$this->freepbx->Voicemail->updateMailbox($ext, $vmBox);
				$vmUpdated = true;
			}
		} catch (\Throwable $e) {
			// non-fatal
		}

		// Verify
		$reread = $userman->getUserByID($umId) ?: [];
		$newEmail = (string)($reread['email'] ?? '');

		$layers = ['User Manager'];
		if ($vmUpdated) $layers[] = 'voicemail-to-email';

		return [
			'success' => $newEmail === $email,
			'message' => "Email on ext {$ext} set to `{$newEmail}` (" . implode(' + ', $layers) . ").",
			'ext' => $ext,
			'userman_id' => $umId,
			'previous_email' => $currentEmail,
			'email' => $newEmail,
			'voicemail_updated' => $vmUpdated,
			'layers_updated' => $layers,
		];
	}
}
