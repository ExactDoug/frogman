<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';
require_once dirname(__DIR__) . '/Dialplan/DialplanFile.php';

class DialplanRemove extends AbstractTool {
	public function name() { return 'fm_dialplan_remove'; }
	public function description() { return 'Remove a custom dialplan context. Params: name (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		// `name` flows into DialplanFile::contextExists() / removeContext(), both of
		// which use regex to locate the context block in extensions_custom.conf.
		// Allow only alphanumeric / _ / - — every legitimate context name (oc-*,
		// from-internal, app-*, etc.) matches; rejects regex metachars, newlines,
		// and `]` that could confuse the locator. Brackets are stripped in execute(),
		// so we don't need to allow them here.
		$nameStripped = trim((string)$params['name'], '[]');
		if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $nameStripped)) {
			return 'Parameter "name" must be alphanumeric (with - and _ allowed)';
		}
		return true;
	}
	public function requiredPermission() { return null; }

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$name = $params['name'];
		// Strip brackets if user included them
		$name = trim($name, '[]');
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$df = \FreePBX\modules\Frogman\Dialplan\DialplanFile::class;

		if (!$df::contextExists($name)) {
			throw new \Exception("Context '{$name}' not found");
		}

		$ctx = $df::getContext($name);
		$lineCount = count($ctx['lines']);

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would remove context [{$name}] ({$lineCount} lines). Reply yes to confirm.",
				'context' => $name,
				'lines' => $lineCount,
			];
		}

		$df::backup();
		$df::removeContext($name);
		$df::reloadDialplan();

		return [
			'dry_run' => false,
			'message' => "Context [{$name}] removed and dialplan reloaded.",
		];
	}
}
