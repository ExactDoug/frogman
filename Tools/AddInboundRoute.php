<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddInboundRoute extends AbstractTool {
	public function name() { return 'fm_add_inbound_route'; }
	public function description() { return 'Add an inbound route (DID). Params: extension (DID number, required), destination (required — an extension number, "vm <ext>", "rg <id>", "ivr <id>", "tc <id>", or a full destination string like "from-did-direct,1001,1"), description (optional), cidnum (optional CID match). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['extension'])) return 'Parameter "extension" is required';
		if (empty($params['destination'])) return 'Parameter "destination" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Resolve a friendly destination shorthand to a full FreePBX destination string.
	// Supported inputs:
	//   "1001"             → from-did-direct,1001,1   (extension; respects DND/follow-me)
	//   "vm 1001"          → ext-local,vmu1001,1      (voicemail unavailable greeting)
	//   "rg 600"           → ext-group,600,1          (ring group)
	//   "ringgroup 600"    → ext-group,600,1
	//   "ivr 1"            → ivr-1,s,1
	//   "tc 1"             → timeconditions,1,1
	//   "timecondition 1"  → timeconditions,1,1
	// Anything containing a comma is treated as already-formatted and returned as-is.
	private function resolveDestination($input) {
		$dest = trim((string)$input);
		if ($dest === '') return $dest;
		if (strpos($dest, ',') !== false) return $dest; // already a full destination
		if (preg_match('/^\d+$/', $dest)) {
			return "from-did-direct,{$dest},1";
		}
		if (preg_match('/^(vm|voicemail)\s+(\d+)$/i', $dest, $m)) {
			return "ext-local,vmu{$m[2]},1";
		}
		if (preg_match('/^(rg|ringgroup)\s+(\d+)$/i', $dest, $m)) {
			return "ext-group,{$m[2]},1";
		}
		if (preg_match('/^ivr\s+(\d+)$/i', $dest, $m)) {
			return "ivr-{$m[1]},s,1";
		}
		if (preg_match('/^(tc|timecondition)\s+(\d+)$/i', $dest, $m)) {
			return "timeconditions,{$m[2]},1";
		}
		return $dest; // unknown shape — let Core validate
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$dest = $this->resolveDestination($params['destination']);
		$incoming = [
			'extension' => $params['extension'],
			'cidnum' => $params['cidnum'] ?? '',
			'destination' => $dest,
			'description' => $params['description'] ?? '',
			'privacyman' => 0, 'alertinfo' => '', 'ringing' => '', 'fanswer' => '',
			'mohclass' => 'default', 'grppre' => '', 'delay_answer' => 0, 'pricid' => '',
			'pmmaxretries' => '', 'pmminlength' => '', 'reversal' => '', 'rvolume' => '',
		];
		if (!$confirm) {
			$cidNote = !empty($params['cidnum']) ? " (CID match: {$params['cidnum']})" : '';
			return ['dry_run' => true, 'message' => "Would add inbound route: DID `{$params['extension']}`{$cidNote} → `{$dest}`. Reply yes to confirm.", 'route' => $incoming];
		}
		\FreePBX::Core()->addDID($incoming);
		return ['dry_run' => false, 'message' => "Inbound route added: DID `{$params['extension']}` → `{$dest}`", 'needs_reload' => true];
	}
}
