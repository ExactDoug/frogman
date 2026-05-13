<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ToggleTimecondition extends AbstractTool {
	public function name() { return 'fm_toggle_time_condition'; }
	public function description() { return 'Toggle a time condition override. Params: id (required), state (optional: 0=normal, 1=override-match, 2=override-nomatch). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		if (!preg_match('/^\d+$/', (string)$params['id'])) return 'Parameter "id" must be numeric';
		// state is optional. When set, must be one of: 0 (normal), 1 (override-match),
		// 2 (override-nomatch). Validate the raw value before the (int) cast in execute()
		// — otherwise "99" or "abc" silently cast to 99/0 and reach setState() unfiltered.
		if (isset($params['state']) && !in_array((string)$params['state'], ['0','1','2'], true)) {
			return 'Parameter "state" must be 0 (normal), 1 (override-match), or 2 (override-nomatch)';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$id = $params['id'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$tc = $this->freepbx->Timeconditions->getTimeCondition($id);
		// getTimeCondition() returns a non-empty array even when no row matches
		// (default-shaped record with empty values), so empty($tc) misses the
		// missing-row case. Check for a populated identity field instead.
		if (empty($tc['displayname']) && empty($tc['timeconditions_id'])) {
			throw new \Exception("Time condition {$id} not found");
		}
		$currentState = $this->freepbx->Timeconditions->getState($id);
		$newState = isset($params['state']) ? (int) $params['state'] : ($currentState == 0 ? 1 : 0);
		$stateNames = [0 => 'normal', 1 => 'override (matched)', 2 => 'override (unmatched)'];
		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would change \"{$tc['displayname']}\" from {$stateNames[$currentState]} to {$stateNames[$newState]}. Reply yes to confirm.",
			];
		}
		$this->freepbx->Timeconditions->setState($id, $newState);
		return ['dry_run' => false, 'message' => "Time condition \"{$tc['displayname']}\" set to {$stateNames[$newState]}.", 'needs_reload' => true];
	}
}
