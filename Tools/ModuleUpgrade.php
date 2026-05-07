<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleUpgrade extends AbstractTool {
	public function name() { return 'fm_module_upgrade'; }
	public function description() { return 'Upgrade a FreePBX module. Params: name (required, or "all" for all modules). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$name = $params['name'];
		if (!$confirm) {
			$label = ($name === 'all') ? 'all modules' : "module `{$name}`";
			return ['dry_run' => true, 'message' => "Would upgrade {$label}. This may take a few minutes."];
		}
		$r = ($name === 'all')
			? $this->runFwconsole('ma upgradeall')
			: $this->runFwconsole(['ma', 'upgrade', $name]);
		$out = $r['output'];

		// fwconsole sometimes exits 0 for hard errors (unknown module, missing locally) — catch those.
		if (preg_match('/is not a locally installed module|module not found/i', $out)) {
			throw new \Exception("Upgrade failed: {$out}");
		}
		if ($r['exit_code'] !== 0) {
			throw new \Exception("Upgrade failed: {$out}");
		}

		// No-op cases. Single-module path emits "is the same as the online version";
		// upgradeall emits a standalone "Up to date.".
		if (preg_match('/is the same as the online version|already up[- ]?to[- ]?date|^\s*Up to date\.?\s*$/im', $out)) {
			$label = ($name === 'all') ? 'All modules are' : "Module `{$name}` is";
			return ['dry_run' => false, 'message' => "{$label} already up-to-date.", 'output' => $out];
		}

		$label = ($name === 'all') ? 'All modules upgraded' : "Module `{$name}` upgraded";
		return ['dry_run' => false, 'message' => $label, 'output' => $out, 'needs_reload' => true];
	}
}
