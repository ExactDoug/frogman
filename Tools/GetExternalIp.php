<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetExternalIp extends AbstractTool {
	public function name() { return 'fm_get_external_ip'; }
	public function description() { return 'Get the external/public IP address of this PBX.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$r = $this->runFwconsole('extip');
		return ['output' => $r['output']];
	}
}
