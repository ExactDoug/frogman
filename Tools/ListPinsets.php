<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListPinsets extends AbstractTool {
	public function name() { return 'fm_list_pinsets'; }
	public function description() { return 'List all PIN sets.'; }
	public function validate($params) { return true; }
	// PIN sets carry outbound dial PINs in plaintext. Exposing them at PERM_READ
	// hands any read-tier caller the codes that gate long-distance / international calling.
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$pins = $this->freepbx->Pinsets->listPinsets(); return ['count' => count($pins ?: []), 'pinsets' => $pins ?: []];
	}
}
