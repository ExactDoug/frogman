<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ValidatePbx extends AbstractTool {
	public function name() { return 'fm_validate'; }
	public function description() { return 'Run a security validation scan on the PBX.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		// Validate needs a pseudo-TTY via script wrapper
		if (!$this->canSudo()) return ['needs_root' => true, 'message' => 'This command requires root access.'];
		$result = $this->runFwconsole('validate --no-interaction', ['sudo' => true, 'tty' => true, 'no_ansi' => true]);
		if (!empty($result['needs_root'])) return $result;

		$raw = $result['output'];
		// Strip progress bars
		$raw = preg_replace('/[>=\-]{10,}/', '', $raw);
		$raw = preg_replace('/Downloading\.\.\.\s*/', '', $raw);
		$raw = preg_replace('/\n{2,}/', "\n", trim($raw));

		$passed = strpos($raw, 'passed') !== false || strpos($raw, 'clean') !== false;
		$error = $result['exit_code'] !== 0 || strpos($raw, 'Error') !== false;

		return ['result' => trim($raw), 'passed' => $passed, 'error' => $error, 'exit_code' => $result['exit_code']];
	}
}
