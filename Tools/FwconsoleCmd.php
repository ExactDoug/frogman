<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class FwconsoleCmd extends AbstractTool {
	public function name() { return 'fm_fwconsole'; }
	public function description() { return 'Run an fwconsole command. Params: args (required, e.g. "ma list" or "sa info"). Requires confirm:true for non-read commands.'; }
	public function validate($params) {
		if (empty($params['args'])) return 'Parameter "args" is required';
		$args = $params['args'];
		// Block shell injection characters
		if (preg_match('/[;&|`$(){}]/', $args)) {
			return 'Shell metacharacters are not allowed';
		}
		// Whitelist: only allow known safe fwconsole subcommands.
		// Trailing (\s|$) anchor required so a bare token (e.g. `context`) doesn't
		// prefix-match a longer subcommand name (e.g. `contextual`, hypothetical
		// future `context-foo`). Every alternation entry must match either a full
		// token to end-of-string or be followed by a space (then args).
		//
		// NOTE: `pm2` is deliberately NOT in this allowlist. `fwconsole pm2 <sub>`
		// covers mutating subcommands (restart/stop/delete/kill) that this generic
		// tool treated as read-only, bypassing the confirm gate. PM2 process control
		// goes through the dedicated, confirm-gated `fm_pm2_manage` tool instead.
		$allowed = '/^(ma\s+(list|install|uninstall|enable|disable|upgrade|upgradeall|download)|sa\s+(info|update)|reload|restart|start|stop|chown|status|--version|-V|context|certificates)(\s|$)/i';
		if (!preg_match($allowed, $args)) {
			return 'Command not in allowed list. Use specific Frogman tools instead.';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$args = $params['args'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		// Same trailing-anchor fix as the allowlist above — keep these in sync.
		// `pm2` removed: it is no longer allowed at all (see allowlist note), and it
		// must never be classified read-only since pm2 subcommands mutate process state.
		$readOnly = preg_match('/^(ma\s+list|sa\s+info|status|--version|-V|context)(\s|$)/i', $args);
		if (!$readOnly && !$confirm) {
			return ['dry_run' => true, 'message' => "Would run: fwconsole {$args}."];
		}
		$r = $this->runFwconsole($args);
		return ['command' => "fwconsole {$args}", 'exit_code' => $r['exit_code'], 'output' => $r['output']];
	}
}
