<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListManagers extends AbstractTool {
	public function name() { return 'fm_list_managers'; }
	public function description() { return 'List AMI manager users.'; }
	public function validate($params) { return true; }
	// AMI manager records carry secrets (the secret column is the AMI password used to
	// connect to Asterisk). PERM_READ would expose them to any read-tier caller.
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$mgrs = $this->freepbx->Manager->list_managers(); return ['count' => count($mgrs ?: []), 'managers' => $mgrs ?: []];
	}
}
