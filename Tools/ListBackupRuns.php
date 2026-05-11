<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListBackupRuns extends AbstractTool {
	// Grace window after a scheduled tick before a missed tick is reported.
	const MISSED_GRACE_SECONDS = 3600;

	// Max missed-tick rows to synthesize per scheduled job (most recent first).
	// Keeps output bounded for jobs that have been broken for a long time.
	const MAX_INFERRED_MISSED_PER_JOB = 5;

	const STATUS_SUCCESS = 'success';
	const STATUS_RUNNING = 'running';
	const STATUS_FAILED_INFERRED = 'failed_inferred';
	const STATUS_FAILED = 'failed';

	public function name() { return 'fm_list_backup_runs'; }

	public function description() {
		return 'List backup run history. Real runs come from FreePBX runningBackupstatus; missed scheduled ticks are emitted as failed_inferred rows (no artifact = inferred failure). Params: job_id (UUID), job_name (substring), status (success|running|failed|failed_inferred|all), since (ISO8601 or unix ts), limit (default 50).';
	}

	public function validate($params) {
		if (isset($params['status'])) {
			$allowed = ['all', self::STATUS_SUCCESS, self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_FAILED_INFERRED];
			if (!in_array($params['status'], $allowed, true)) {
				return 'status must be one of: ' . implode(', ', $allowed);
			}
		}
		return true;
	}

	public function requiredPermission() { return null; }

	public function execute($params, $context) {
		$jobIdFilter = $params['job_id'] ?? null;
		$jobNameFilter = isset($params['job_name']) ? strtolower(trim((string)$params['job_name'])) : null;
		$statusFilter = $params['status'] ?? 'all';
		$limit = isset($params['limit']) ? max(1, min(500, (int)$params['limit'])) : 50;
		$sinceTs = $this->parseSince($params['since'] ?? null);

		$jobs = $this->loadJobs($jobIdFilter, $jobNameFilter);
		$locMap = $this->buildLocationMap();
		$runs = $this->collectRealRuns($jobs, $locMap);

		if ($statusFilter === 'all' || $statusFilter === self::STATUS_FAILED_INFERRED) {
			$inferred = $this->computeInferredMissed($jobs, $runs);
			$runs = array_merge($runs, $inferred);
		}

		// Filter
		$out = [];
		foreach ($runs as $r) {
			if ($statusFilter !== 'all' && $r['status'] !== $statusFilter) continue;
			if ($sinceTs && ($r['_ts'] ?? 0) < $sinceTs) continue;
			$out[] = $r;
		}

		// Sort newest first, then trim and strip internal sort key.
		usort($out, function($a, $b) { return ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0); });
		$truncated = count($out) > $limit;
		$out = array_slice($out, 0, $limit);
		foreach ($out as &$r) unset($r['_ts']);

		$response = [
			'count' => count($out),
			'truncated' => $truncated,
			'status_filter' => $statusFilter,
			'runs' => $out,
		];
		if (empty($out)) {
			$response['message'] = $this->emptyMessage($statusFilter, count($jobs));
		}
		return $response;
	}

	private function emptyMessage($statusFilter, $jobCount) {
		if ($jobCount === 0) return 'No backup jobs configured.';
		switch ($statusFilter) {
			case self::STATUS_FAILED_INFERRED:
				return '✓ No failed backups detected — all scheduled jobs are up to date.';
			case self::STATUS_FAILED:
				return '✓ No failed runs on record.';
			case self::STATUS_RUNNING:
				return 'No backups currently running.';
			case self::STATUS_SUCCESS:
				return 'No successful runs on record yet.';
			default:
				return 'No backup runs on record yet.';
		}
	}

	private function loadJobs($jobIdFilter, $jobNameFilter) {
		$index = $this->freepbx->Backup->listBackups() ?: [];
		$jobs = [];
		foreach ($index as $row) {
			$id = $row['id'] ?? null;
			if (!$id) continue;
			if ($jobIdFilter && $id !== $jobIdFilter) continue;
			$cfg = $this->freepbx->Backup->getBackup($id) ?: [];
			$name = $cfg['backup_name'] ?? $row['name'] ?? 'unnamed';
			if ($jobNameFilter && strpos(strtolower($name), $jobNameFilter) === false) continue;
			$storage = $cfg['backup_storage'] ?? [];
			if (!is_array($storage)) $storage = [];
			$jobs[$id] = [
				'id' => $id,
				'name' => $name,
				'schedule' => (string)($cfg['backup_schedule'] ?? ''),
				'schedule_enabled' => $this->boolish($cfg['schedule_enabled'] ?? false),
				'storage' => $storage,
			];
		}
		return $jobs;
	}

	private function collectRealRuns(array $jobs, array $locMap) {
		$runs = [];
		try {
			$raw = $this->freepbx->Backup->getAll('runningBackupstatus') ?: [];
		} catch (\Throwable $e) {
			return $runs;
		}
		foreach ($raw as $txId => $entry) {
			if (!is_array($entry)) continue;
			$buid = $entry['buid'] ?? null;
			if (!$buid) continue;
			// Skip runs whose job isn't in the filtered set.
			if (!isset($jobs[$buid])) continue;
			$job = $jobs[$buid];

			$file = $entry['backupfile'] ?? '';
			$resolved = $this->resolveArtifactPath($file);
			$ts = $resolved ? (filemtime($resolved) ?: 0) : 0;
			$size = $resolved ? (filesize($resolved) ?: null) : null;

			$rawStatus = (string)($entry['status'] ?? '');
			$status = $this->classifyStatus($rawStatus, $resolved !== null);

			$runs[] = [
				'job_id' => $buid,
				'job_name' => $job['name'],
				'status' => $status,
				'raw_status' => $rawStatus,
				'finished_at' => $ts ? date('c', $ts) : null,
				'file' => $resolved ?: $file,
				'file_exists' => $resolved !== null,
				'file_size' => $size,
				'destinations' => $this->resolveDestinations($job['storage'], $locMap),
				'transaction_id' => is_string($txId) ? $txId : '',
				'error' => null,
				'_ts' => $ts,
			];
		}
		return $runs;
	}

	/**
	 * For each scheduled job, emit failed_inferred rows for missed ticks. A tick is
	 * "missed" when it's older than the grace window and no successful run is on
	 * record after that tick. Bounded at MAX_INFERRED_MISSED_PER_JOB per job to keep
	 * long-broken jobs from drowning the response.
	 */
	private function computeInferredMissed(array $jobs, array $realRuns) {
		$now = time();
		$lastSuccessByJob = [];
		foreach ($realRuns as $r) {
			if ($r['status'] !== self::STATUS_SUCCESS) continue;
			$buid = $r['job_id'];
			if (!isset($lastSuccessByJob[$buid]) || $r['_ts'] > $lastSuccessByJob[$buid]) {
				$lastSuccessByJob[$buid] = $r['_ts'];
			}
		}

		$inferred = [];
		foreach ($jobs as $job) {
			if (!$job['schedule_enabled'] || $job['schedule'] === '') continue;
			try {
				$cx = \Cron\CronExpression::factory($job['schedule']);
			} catch (\Throwable $e) {
				continue;
			}
			$lastSuccess = $lastSuccessByJob[$job['id']] ?? 0;
			$ticks = [];
			// Walk backward from "now" collecting recent ticks past the grace window.
			$cursor = 'now';
			for ($i = 0; $i < self::MAX_INFERRED_MISSED_PER_JOB; $i++) {
				try {
					$tickDate = $cx->getPreviousRunDate($cursor);
				} catch (\Throwable $e) {
					break;
				}
				$tickTs = $tickDate->getTimestamp();
				if (($now - $tickTs) <= self::MISSED_GRACE_SECONDS) {
					// Still in grace — not missed yet.
					$cursor = $tickDate;
					continue;
				}
				if ($tickTs <= $lastSuccess) break; // Successful run covered this tick and earlier.
				$ticks[] = $tickTs;
				$cursor = $tickDate;
			}
			foreach ($ticks as $tickTs) {
				$inferred[] = [
					'job_id' => $job['id'],
					'job_name' => $job['name'],
					'status' => self::STATUS_FAILED_INFERRED,
					'raw_status' => '',
					'finished_at' => null,
					'file' => null,
					'file_exists' => false,
					'file_size' => null,
					'destinations' => [],
					'transaction_id' => '',
					'error' => 'No artifact recorded for scheduled tick at ' . date('c', $tickTs),
					'_ts' => $tickTs,
				];
			}
		}
		return $inferred;
	}

	private function classifyStatus($rawStatus, $artifactExists) {
		if ($rawStatus === 'FINISHED' && $artifactExists) return self::STATUS_SUCCESS;
		if ($rawStatus === 'FINISHED' && !$artifactExists) return self::STATUS_FAILED;
		if ($rawStatus === '') return self::STATUS_FAILED;
		return self::STATUS_RUNNING;
	}

	private function resolveDestinations(array $storage, array $locMap) {
		$out = [];
		foreach ($storage as $sid) {
			$out[] = $locMap[$sid] ?? ['id' => $sid, 'driver' => 'unknown', 'name' => $sid];
		}
		return $out;
	}

	private function buildLocationMap() {
		// backup_storage stores the prefixed form "{Driver}_{uuid}" while
		// Filestore returns {locations: {Driver: [{id, name}]}} — index by prefixed form.
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

	private function resolveArtifactPath($path) {
		// Backup writes sometimes record a per-job subdir that wasn't actually used;
		// fall back to the parent directory if the recorded path is missing.
		if (!$path) return null;
		if (file_exists($path)) return $path;
		$flat = dirname(dirname($path)) . '/' . basename($path);
		if ($flat !== $path && file_exists($flat)) return $flat;
		return null;
	}

	private function parseSince($v) {
		if ($v === null || $v === '') return 0;
		if (is_numeric($v)) return (int)$v;
		$ts = strtotime((string)$v);
		return $ts === false ? 0 : $ts;
	}

	private function boolish($v) {
		if (is_bool($v)) return $v;
		if (is_numeric($v)) return ((int)$v) === 1;
		$s = strtolower(trim((string)$v));
		return $s === 'true' || $s === 'yes' || $s === 'on' || $s === '1';
	}
}
