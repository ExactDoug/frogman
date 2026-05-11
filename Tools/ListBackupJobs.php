<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListBackupJobs extends AbstractTool {
	public function name() { return 'fm_list_backup_jobs'; }

	public function description() {
		return 'List configured FreePBX backup jobs with schedule, destinations, retention, and email settings. Read-only.';
	}

	public function validate($params) { return true; }

	public function requiredPermission() { return null; }

	public function execute($params, $context) {
		$index = $this->freepbx->Backup->listBackups() ?: [];
		$locMap = $this->buildLocationMap();

		$jobs = [];
		foreach ($index as $row) {
			$id = $row['id'] ?? null;
			if (!$id) continue;

			$cfg = $this->freepbx->Backup->getBackup($id) ?: [];

			$storage = $cfg['backup_storage'] ?? [];
			if (!is_array($storage)) $storage = [];
			$destinations = [];
			foreach ($storage as $sid) {
				$destinations[] = $locMap[$sid] ?? ['id' => $sid, 'driver' => 'unknown', 'name' => $sid];
			}

			$jobs[] = [
				'id' => $id,
				'name' => $cfg['backup_name'] ?? $row['name'] ?? 'unnamed',
				'description' => $cfg['backup_description'] ?? $row['description'] ?? '',
				'schedule' => $cfg['backup_schedule'] ?? '',
				'schedule_enabled' => $this->boolish($cfg['schedule_enabled'] ?? false),
				'destinations' => $destinations,
				'retention' => [
					'max_age_days' => $this->intOrNull($cfg['maintage'] ?? null),
					'max_runs' => $this->intOrNull($cfg['maintruns'] ?? null),
				],
				'email' => [
					'address' => $cfg['backup_email'] ?? '',
					'type' => $cfg['backup_emailtype'] ?? '',
				],
				'warm_spare' => $this->boolish($cfg['warmspareenabled'] ?? false),
				'immortal' => $this->boolish($cfg['immortal'] ?? false),
			];
		}

		return ['count' => count($jobs), 'jobs' => $jobs];
	}

	private function buildLocationMap() {
		// Backup jobs store destinations as "{Driver}_{uuid}" (e.g. "Local_3067...").
		// Filestore->listLocations() returns ['locations' => ['Local' => [{id, name, ...}]]].
		// Build a map keyed by the prefixed form so we can resolve job storage IDs.
		$map = [];
		try {
			$resp = $this->freepbx->Filestore->listLocations() ?: [];
		} catch (\Throwable $e) {
			return $map;
		}
		$byDriver = $resp['locations'] ?? $resp;
		if (!is_array($byDriver)) return $map;
		foreach ($byDriver as $driver => $items) {
			if (!is_array($items)) continue;
			foreach ($items as $item) {
				$rawId = $item['id'] ?? null;
				if (!$rawId) continue;
				$key = is_string($driver) ? "{$driver}_{$rawId}" : $rawId;
				$map[$key] = [
					'id' => $key,
					'driver' => is_string($driver) ? $driver : '',
					'name' => $item['name'] ?? $item['description'] ?? $rawId,
				];
			}
		}
		return $map;
	}

	private function boolish($v) {
		if (is_bool($v)) return $v;
		if (is_numeric($v)) return ((int)$v) === 1;
		$s = strtolower(trim((string)$v));
		return $s === 'true' || $s === 'yes' || $s === 'on' || $s === '1';
	}

	private function intOrNull($v) {
		if ($v === null || $v === '') return null;
		return is_numeric($v) ? (int)$v : null;
	}
}
