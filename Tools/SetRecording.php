<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetRecording extends AbstractTool {
	public function name() { return 'fm_set_recording'; }
	public function description() { return 'Set call recording mode on an extension. Params: ext (required), mode (required: force, yes, dontcare, no, never — aliases: always=yes, enable/on=yes, disable/off=no). Applies to all four directions (inbound/outbound × internal/external). Requires confirm:true.'; }

	private static $modeAliases = [
		'force'    => 'force',
		'yes'      => 'yes',
		'dontcare' => 'dontcare',
		'no'       => 'no',
		'never'    => 'never',
		'always'   => 'yes',
		'enable'   => 'yes',
		'enabled'  => 'yes',
		'on'       => 'yes',
		'disable'  => 'no',
		'disabled' => 'no',
		'off'      => 'no',
	];

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (empty($params['mode'])) return 'Parameter "mode" is required (force, yes, dontcare, no, never)';
		$key = strtolower(trim($params['mode']));
		if (!isset(self::$modeAliases[$key])) {
			return 'Parameter "mode" must be one of: force, yes, dontcare, no, never';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$ext = (string)$params['ext'];
		$mode = self::$modeAliases[strtolower(trim($params['mode']))];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$core = $this->freepbx->Core;
		$user = $core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		$current = [
			'in_external'  => $user['recording_in_external']  ?? 'dontcare',
			'out_external' => $user['recording_out_external'] ?? 'dontcare',
			'in_internal'  => $user['recording_in_internal']  ?? 'dontcare',
			'out_internal' => $user['recording_out_internal'] ?? 'dontcare',
		];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would set call recording to `{$mode}` on extension {$ext} ({$user['name']}) for all four directions.",
				'ext'     => $ext,
				'mode'    => $mode,
				'current' => $current,
				'new'     => array_fill_keys(array_keys($current), $mode),
			];
		}

		// BMO edit pattern — mirrors core_users_edit() in core/functions.inc.php:
		//   delUser($ext, editmode=true) leaves AstDB intact, then
		//   addUser($ext, $merged, editmode=true) re-inserts with new settings
		//   and fires Hooks->processHooks so other modules see the edit.
		// $merged starts from the full getUser snapshot so we don't reset
		// unrelated fields (recording_ondemand, recording_priority, ringtimer,
		// callwaiting, voicemail, etc.) to addUser's hard-coded defaults.
		// The user→device mapping in AstDB is preserved automatically:
		// addUser's database_put for /AMPUSER/<ext>/device runs only when
		// !editmode, so editmode=true leaves the existing link alone.
		$merged = $user;
		$merged['recording_in_external']  = $mode;
		$merged['recording_out_external'] = $mode;
		$merged['recording_in_internal']  = $mode;
		$merged['recording_out_internal'] = $mode;
		// Legacy single-mode field (pre-split FreePBX): keep it consistent with
		// the four directional fields. It's stored in users.recording (SQL) and
		// AstDB /AMPUSER/<ext>/recording. Modern UI/dialplan use the directional
		// fields, but legacy code paths may still read this — keep it in sync.
		$merged['recording'] = $mode;

		$core->delUser($ext, true);
		try {
			$ok = $core->addUser($ext, $merged, true);
		} catch (\Exception $e) {
			throw new \Exception("addUser failed for extension {$ext}: " . $e->getMessage());
		}
		if (!$ok) {
			throw new \Exception("addUser returned falsy for extension {$ext}");
		}

		$verify = $core->getUser($ext);
		$applied = [
			'in_external'  => $verify['recording_in_external']  ?? '',
			'out_external' => $verify['recording_out_external'] ?? '',
			'in_internal'  => $verify['recording_in_internal']  ?? '',
			'out_internal' => $verify['recording_out_internal'] ?? '',
		];
		$success = count(array_unique($applied)) === 1 && reset($applied) === $mode;

		// No needs_reload: Core::addUser updates AstDB directly, dialplan reads
		// /AMPUSER/<ext>/recording/* at call time, no fwconsole reload required.
		$host = $_SERVER['HTTP_HOST'] ?? gethostname();
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$extUrl = "{$scheme}://{$host}/admin/config.php?display=extensions&extdisplay={$ext}";

		return [
			'dry_run' => false,
			'success' => $success,
			'message' => $success
				? "Call recording set to `{$mode}` on extension {$ext} ({$user['name']})."
				: "Call recording write attempted but verification mismatch on extension {$ext}.",
			'ext'           => $ext,
			'mode'          => $mode,
			'previous'      => $current,
			'applied'       => $applied,
			'on_demand'     => $user['recording_ondemand'] ?? 'disabled',
			'extension_url' => $extUrl,
		];
	}
}
