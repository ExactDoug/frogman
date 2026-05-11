<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * Multicast emergency alert to Sangoma/DPMA phones.
 *
 * Plays a System Recording over a multicast paging zone. Phones subscribed
 * to the zone (via EPM template → Multicast tab) auto-join the multicast
 * group on boot and play whatever lands on it. The recording's codec must
 * match the zone's codec (typically PCMU).
 *
 * Wall routing:
 *   recording lookup → \FreePBX::Recordings()->getAllRecordings()  (BMO)
 *   zone lookup      → SELECT FROM endpoint_multicast              (no BMO surface; rule 3 read)
 *   send             → AMI Originate Channel=MulticastRTP/basic/<ip>:<port>
 */
class SangomaEmergencyAlert extends AbstractTool {

	public function name() { return 'fm_sangoma_emergency_alert'; }

	public function description() {
		return 'Trigger a multicast emergency alert on Sangoma/DPMA phones. Plays a System Recording over the named multicast zone. Zones must already exist (FreePBX → Endpoint Manager → template → Multicast tab). Params: recording (System Recording id or display name; required), zone (zone name; default "LOCKDOWN"), confirm (true to send).';
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function validate($params) {
		if (empty($params['recording'])) {
			return 'Parameter "recording" is required (System Recording id or display name).';
		}
		return true;
	}

	public function execute($params, $context) {
		$confirm  = !empty($params['confirm']) && $params['confirm'] === true;
		$zoneName = !empty($params['zone']) ? trim((string)$params['zone']) : 'LOCKDOWN';
		$needle   = trim((string)$params['recording']);

		$recording = $this->resolveRecording($needle);
		if (isset($recording['error'])) {
			return ['dry_run' => !$confirm] + $recording;
		}

		$zone = $this->resolveZone($zoneName);
		if (isset($zone['error'])) {
			return ['dry_run' => !$confirm] + $zone;
		}

		$channel = "MulticastRTP/basic/{$zone['ip_address']}:{$zone['port']}";
		$preview = [
			'zone' => [
				'name'      => $zone['name'],
				'brand'     => $zone['brand'],
				'template'  => $zone['template_name'],
				'address'   => "{$zone['ip_address']}:{$zone['port']}",
				'codec'     => $zone['codec'],
				'priority'  => (int)$zone['priority'],
				'interrupt' => (int)$zone['interrupt_calls'],
			],
			'recording' => [
				'id'          => (int)$recording['id'],
				'displayname' => $recording['displayname'],
				'filename'    => $recording['filename'],
			],
			'channel' => $channel,
		];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would broadcast '{$recording['displayname']}' to multicast zone '{$zone['name']}' ({$zone['ip_address']}:{$zone['port']}, {$zone['codec']}). Reply yes to confirm.",
				'preview' => $preview,
			];
		}

		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) {
			throw new \Exception('Cannot connect to Asterisk Manager');
		}

		$res = $astman->Originate([
			'Channel'     => $channel,
			'Application' => 'Playback',
			'Data'        => $recording['filename'],
			'Timeout'     => 30000,
			'Async'       => 'true',
		]);

		return [
			'dry_run' => false,
			'message' => "Emergency alert sent to zone '{$zone['name']}' ({$zone['ip_address']}:{$zone['port']}).",
			'preview' => $preview,
			'ami_response' => $res,
		];
	}

	private function resolveRecording($needle) {
		$recordings = $this->freepbx->Recordings->getAllRecordings();
		if ($needle !== '' && ctype_digit($needle)) {
			foreach ($recordings as $r) {
				if ((int)$r['id'] === (int)$needle) return $r;
			}
			return ['error' => "No System Recording with id {$needle}. Check FreePBX → Admin → System Recordings."];
		}
		$lower = strtolower($needle);
		foreach ($recordings as $r) {
			if (strtolower((string)$r['displayname']) === $lower) return $r;
		}
		$matches = [];
		foreach ($recordings as $r) {
			if (stripos((string)$r['displayname'], $needle) !== false) $matches[] = $r;
		}
		if (count($matches) === 1) return $matches[0];
		if (count($matches) > 1) {
			$names = array_map(function($m) { return $m['displayname']; }, $matches);
			return ['error' => "Recording name '{$needle}' is ambiguous. Matches: " . implode(', ', $names) . ". Use the System Recording id instead."];
		}
		return ['error' => "No System Recording named '{$needle}' found. Check FreePBX → Admin → System Recordings."];
	}

	private function resolveZone($name) {
		$db = $this->freepbx->Database;
		$stmt = $db->prepare("SELECT id, brand, template_name, multicast_type, name, ip_address, port, codec, priority, interrupt_calls FROM endpoint_multicast WHERE name = ? LIMIT 1");
		$stmt->execute([$name]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$row) {
			return ['error' => "No multicast zone named '{$name}' found. Create one in FreePBX → Endpoint Manager → template → Multicast tab. Suggested for LOCKDOWN: 239.255.0.1:10000, PCMU, priority 1, interrupt level 2."];
		}
		return $row;
	}
}
