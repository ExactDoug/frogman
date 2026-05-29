<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class Pm2Manage extends AbstractTool {
	public function name() { return 'fm_pm2_manage'; }
	public function description() { return 'Manage a PM2 process. Params: action (restart/stop/delete, required), name (process name, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['action'])) return 'Parameter "action" is required (restart, stop, or delete)';
		if (!in_array($params['action'], ['restart', 'stop', 'delete'], true)) return 'Action must be "restart", "stop", or "delete"';
		if (empty($params['name'])) return 'Parameter "name" is required';
		// Defense in depth: `name` is passed to the PM2 BMO, which shells out to
		// `pm2 <action> <name>`. Constrain to PM2 process-name characters so nothing
		// exotic reaches the shell layer.
		if (!preg_match('/^[a-zA-Z0-9._-]+$/', $params['name'])) return 'Parameter "name" must be alphanumeric (with . _ - allowed)';
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$action = $params['action'];
		$name = $params['name'];
		if (!$confirm) {
			// delete removes the process from PM2 management entirely (not just a stop),
			// so make the preview say so.
			$msg = $action === 'delete'
				? "Would delete PM2 process '{$name}' — removes it from PM2 management until something re-adds it. Reply yes to confirm."
				: "Would {$action} PM2 process '{$name}'. Reply yes to confirm.";
			return ['dry_run' => true, 'message' => $msg];
		}
		$this->freepbx->Pm2->$action($name);
		// Proper past tense — naive "{$action}ed" gives "stoped"/"deleteed". $action is
		// whitelisted to these three keys above, so the map is exhaustive.
		$past = ['restart' => 'restarted', 'stop' => 'stopped', 'delete' => 'deleted'][$action];
		return ['dry_run' => false, 'message' => "PM2 {$name} {$past}"];
	}
}
