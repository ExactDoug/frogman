<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListSangomaMulticastZones extends AbstractTool {
	public function name() { return 'fm_list_sangoma_multicast_zones'; }
	public function description() { return 'List Sangoma/DPMA multicast paging zones from EPM. Zones must be created in the Endpoint Manager template GUI first (Frogman cannot create them — no public BMO setter exists). Returns brand, template, name, ip, port, codec, priority, interrupt level.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$rows = $db->query("SELECT id, brand, template_name, multicast_type, name, ip_address, port, priority, interrupt_calls, codec FROM endpoint_multicast ORDER BY brand, template_name, priority")->fetchAll(\PDO::FETCH_ASSOC);

		$zones = [];
		foreach ($rows as $r) {
			$zones[] = [
				'id' => (int)$r['id'],
				'brand' => $r['brand'],
				'template' => $r['template_name'],
				'type' => $r['multicast_type'],
				'name' => $r['name'],
				'ip' => $r['ip_address'],
				'port' => (int)$r['port'],
				'priority' => (int)$r['priority'],
				'interrupt' => (int)$r['interrupt_calls'],
				'codec' => $r['codec'],
			];
		}

		return [
			'count' => count($zones),
			'zones' => $zones,
			'note' => empty($zones) ? 'No multicast zones configured. Create one in EPM → template → Multicast tab.' : null,
		];
	}
}
