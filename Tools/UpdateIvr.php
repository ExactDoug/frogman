<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// In-place IVR update. Selective field merge: any field not supplied keeps
// its current value. Entries, if supplied, replace the existing set in full
// (BMO has no add-one-entry method, only saveEntry which delete-then-inserts).
//
// Routing surface: Ivr->getDetails / saveDetails / saveEntry / getAllEntries
// are all on the Ivr BMO facade ($this->freepbx->Ivr->X), so this tool is
// fully in-walls. Same shape as fm_update_outbound_route.
class UpdateIvr extends AbstractTool {
	public function name() { return 'fm_update_ivr'; }
	public function description() { return 'Update an existing IVR in place. Params: id (required). Any subset of the following fields gets updated; unspecified fields keep their current value: name, description, announcement, directdial, timeout (seconds), timeout_destination, invalid_destination, timeout_recording, invalid_recording, timeout_loops, invalid_loops, retvm. entries (array of {selection, dest, ivr_ret?}) replaces the full entry set when supplied. Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		if (isset($params['timeout']) && (!is_numeric($params['timeout']) || (int)$params['timeout'] < 1 || (int)$params['timeout'] > 600)) return 'Parameter "timeout" must be a number between 1 and 600 seconds';
		if (isset($params['entries']) && !is_array($params['entries'])) return 'Parameter "entries" must be an array of {selection, dest, ivr_ret?} rows';
		if (isset($params['entries'])) {
			foreach ($params['entries'] as $i => $e) {
				if (!is_array($e)) return "entries[{$i}] must be an object";
				if (!isset($e['selection']) || $e['selection'] === '') return "entries[{$i}] needs a non-empty 'selection'";
				if (empty($e['dest'])) return "entries[{$i}] needs a 'dest'";
				// Reject framing + comment chars in selection and dest (two-layer
				// validation). `;` is Asterisk dialplan comment marker; a dest
				// like "from-did-direct,1001,1;X" would silently truncate the
				// goto target, not exploitable but breaks the route.
				foreach (['selection', 'dest'] as $k) {
					if (preg_match('/[\r\n\0;]/', (string)$e[$k])) return "entries[{$i}].{$k} contains disallowed control or comment characters";
				}
			}
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$id = (int)$params['id'];

		$current = $this->freepbx->Ivr->getDetails($id);
		if (empty($current)) return ['error' => "IVR `{$id}` not found"];

		// getDetails returns a list; pull the single row.
		if (isset($current[0]) && is_array($current[0])) $current = $current[0];

		// Two quirks of getDetails() to neutralize before REPLACE INTO:
		//   (1) fetchAll() uses PDO::FETCH_BOTH by default, so $current has
		//       duplicate numeric keys (0, 1, 2, ...). saveDetails iterates
		//       them and tries to bind :0, :1 as column placeholders, which
		//       fails with HY093 "Invalid parameter number".
		//   (2) name and description get run through htmlentities() before
		//       return, so writing back would persist "Tom &amp; Jerry"
		//       literally. Decode before merge.
		$cleanCurrent = [];
		foreach ($current as $k => $v) {
			if (is_string($k)) $cleanCurrent[$k] = $v;
		}
		foreach (['name', 'description'] as $f) {
			if (isset($cleanCurrent[$f])) {
				$cleanCurrent[$f] = html_entity_decode($cleanCurrent[$f], ENT_COMPAT | ENT_HTML401, 'UTF-8');
			}
		}
		$current = $cleanCurrent;

		// Current entries: getAllEntries returns map keyed by ivr_id.
		$allEntries = $this->freepbx->Ivr->getAllEntries();
		$currentEntries = $allEntries[$id] ?? [];

		// Field mapping. Caller-friendly names on left, DB column on right.
		// Only DB columns saveDetails will write; everything else gets dropped
		// before the REPLACE INTO call.
		$fieldMap = [
			'name'                => 'name',
			'description'         => 'description',
			'announcement'        => 'announcement',
			'directdial'          => 'directdial',
			'timeout'             => 'timeout_time',
			'timeout_destination' => 'timeout_destination',
			'invalid_destination' => 'invalid_destination',
			'timeout_recording'   => 'timeout_recording',
			'invalid_recording'   => 'invalid_recording',
			'timeout_loops'       => 'timeout_loops',
			'invalid_loops'       => 'invalid_loops',
			'retvm'               => 'retvm',
		];

		// Build merged details payload. Start from current row so REPLACE INTO
		// doesn't null fields the caller didn't touch.
		$merged = $current;
		$merged['id'] = $id;
		$changed = [];
		foreach ($fieldMap as $userKey => $dbKey) {
			if (array_key_exists($userKey, $params)) {
				$old = (string)($current[$dbKey] ?? '');
				$new = (string)$params[$userKey];
				if ($old !== $new) {
					$merged[$dbKey] = $params[$userKey];
					$changed[] = ['field' => $userKey, 'old' => $old, 'new' => $new];
				}
			}
		}

		// Entries: full replacement if supplied, else preserve.
		$entriesChanged = false;
		if (isset($params['entries'])) {
			$normalizedEntries = [];
			foreach ($params['entries'] as $e) {
				$normalizedEntries[] = [
					'ivr_id'    => $id,
					'selection' => (string)$e['selection'],
					'dest'      => (string)$e['dest'],
					'ivr_ret'   => isset($e['ivr_ret']) ? (int)$e['ivr_ret'] : 0,
				];
			}
			// Detect if the new entry set actually differs from the current one
			// (order-insensitive comparison by selection+dest+ivr_ret tuple).
			$tupleize = function($rows) {
				$out = [];
				foreach ($rows as $r) {
					$out[] = ($r['selection'] ?? '') . '|' . ($r['dest'] ?? '') . '|' . ((int)($r['ivr_ret'] ?? 0));
				}
				sort($out);
				return $out;
			};
			$entriesChanged = $tupleize($normalizedEntries) !== $tupleize($currentEntries);
		} else {
			$normalizedEntries = $currentEntries;
		}

		// sanitizeForChat on every chat-interpolated user-controlled value
		// (feedback_chat_formatter_xss_pattern.md).
		$nameSan = $this->frogman->sanitizeForChat((string)($merged['name'] ?? ''));
		$currentNameSan = $this->frogman->sanitizeForChat((string)($current['name'] ?? ''));

		if (empty($changed) && !$entriesChanged) {
			return ['dry_run' => true, 'message' => "No changes detected for IVR `{$currentNameSan}` (id `{$id}`)."];
		}

		if (!$confirm) {
			$diff = [];
			foreach ($changed as $c) {
				$f = $c['field'];
				$oldSan = $this->frogman->sanitizeForChat($c['old']);
				$newSan = $this->frogman->sanitizeForChat($c['new']);
				$diff[] = "{$f}: `{$oldSan}` → `{$newSan}`";
			}
			if ($entriesChanged) {
				$diff[] = "entries: " . count($currentEntries) . " → " . count($normalizedEntries) . " row(s)";
			}
			return ['dry_run' => true, 'message' => "Would update IVR `{$currentNameSan}` (id `{$id}`):\n• " . implode("\n• ", $diff) . "\n\nReply yes to confirm."];
		}

		// Live write
		// saveDetails uses REPLACE INTO when id is set, so passing the full
		// merged row is the documented update path.
		$this->freepbx->Ivr->saveDetails($merged);
		if ($entriesChanged) {
			$this->freepbx->Ivr->saveEntry($id, $normalizedEntries);
		}

		return ['dry_run' => false, 'message' => "✅ IVR `{$nameSan}` (id `{$id}`) updated.", 'id' => $id, 'needs_reload' => true];
	}
}
