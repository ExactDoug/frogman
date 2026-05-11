<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SangomaConnectStatus extends AbstractTool {
	public function name() { return 'fm_sc_status'; }
	public function description() { return 'Sangoma Connect preflight diagnostic — license, domain, default cert, enabled SCD/Talk users, free seats. Drives the SC onboarding follow-up chain.'; }

	public function validate($params) { return true; }

	public function execute($params, $context) {
		if (!class_exists('\FreePBX') || !\FreePBX::Modules()->checkStatus('sangomaconnect')) {
			return [
				'installed' => false,
				'next_step' => 'module_missing',
				'message' => 'Sangoma Connect module is not installed or not enabled.',
			];
		}
		$sc = $this->freepbx->Sangomaconnect();

		$licensed = (bool)$sc->licensed();
		$expired  = (bool)$sc->isLicenseExpired();
		$expires  = $sc->getLicenseExpirationDate() ?: null;
		$seatCap  = (int)$sc->userLimit();
		$queueCap = (int)$sc->getQueueAppUsersLimit();

		$domain       = (string)($sc->getDomain() ?: '');
		$domainStatus = (bool)$sc->getDomainStatus();
		$fqdn         = $sc->getDefaultFQDN();
		$proxyStatus  = (string)($sc->getProxyClientStatus() ?: 'Unknown');

		$defaultCert = $sc->getDefaultCert();
		$certInfo = ['exists' => false, 'basename' => null, 'type' => null, 'id' => null];
		if (is_array($defaultCert) && !empty($defaultCert)) {
			$first = reset($defaultCert);
			if (is_array($first)) {
				$certInfo = [
					'exists' => true,
					'basename' => $first['basename'] ?? null,
					'type' => $first['type'] ?? null,
					'id' => isset($first['cid']) ? (int)$first['cid'] : null,
				];
			}
		}

		$scd  = $sc->desktopUserEnabledList() ?: [];
		$talk = $sc->mobileUserEnabledList() ?: [];
		$scdExts  = $this->extractExtensions($scd);
		$talkExts = $this->extractExtensions($talk);
		$distinct = array_unique(array_merge($scdExts, $talkExts));
		$freeSeats = max(0, $seatCap - count($distinct));

		$nextStep = $this->computeNextStep($licensed, $expired, $domainStatus, $certInfo['exists'], $freeSeats);

		return [
			'installed' => true,
			'license' => [
				'licensed' => $licensed,
				'expired' => $expired,
				'expires' => $expires,
				'seat_cap' => $seatCap,
				'queue_app_cap' => $queueCap,
			],
			'domain' => [
				'domain' => $domain,
				'status' => $domainStatus,
				'fqdn' => $fqdn === false ? null : $fqdn,
				'proxy_status' => $proxyStatus,
			],
			'cert' => $certInfo,
			'users' => [
				'scd_count' => count($scdExts),
				'talk_count' => count($talkExts),
				'scd_enabled' => $scdExts,
				'talk_enabled' => $talkExts,
				'distinct_users' => count($distinct),
				'free_seats' => $freeSeats,
			],
			'ready_to_enable_users' => ($nextStep === 'ready'),
			'next_step' => $nextStep,
		];
	}

	private function extractExtensions($list) {
		if (!is_array($list)) return [];
		$out = [];
		foreach ($list as $row) {
			if (is_string($row) || is_numeric($row)) { $out[] = (string)$row; continue; }
			if (is_array($row)) {
				$ext = $row['extension'] ?? $row['ext'] ?? $row['userext'] ?? $row['user'] ?? $row['default_extension'] ?? null;
				if ($ext !== null) $out[] = (string)$ext;
			}
		}
		return $out;
	}

	private function computeNextStep($licensed, $expired, $domainStatus, $certExists, $freeSeats) {
		if (!$licensed || $expired) return 'license_invalid';
		if (!$certExists)            return 'cert_required';
		if (!$domainStatus)          return 'domain_not_running';
		if ($freeSeats <= 0)         return 'license_exhausted';
		return 'ready';
	}
}
