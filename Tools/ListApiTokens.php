<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListApiTokens extends AbstractTool {
	public function name() { return 'fm_list_api_tokens'; }
	public function description() { return 'List all API tokens. Identify by id/username/description/created_at — raw token values are never returned (only SHA-256 hashes are stored, see GHSA-9xf5-9ghq-p6cw).'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$sth = $db->query("SELECT id, username, description, level, active, created_at FROM oc_api_tokens ORDER BY created_at DESC");
		$tokens = $sth->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($tokens as &$t) {
			$t['created_at_human'] = date('Y-m-d H:i:s', $t['created_at']);
			$t['status'] = $t['active'] ? 'active' : 'revoked';
		}
		return ['count' => count($tokens), 'tokens' => $tokens];
	}
}
