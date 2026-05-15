<?php
namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Chat extends Command {

	protected function configure() {
		$this->setName('frogman:chat')
			->setDescription('Interactive Frogman chat console');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Cap parser memory so a runaway bug fails fast instead of swapping the box
		ini_set('memory_limit', '256M');

		$freepbx = \FreePBX::Create();
		$frogman = $freepbx->Frogman;
		$sessionId = 'cli-' . posix_getuid() . '-' . getmypid();

		require_once dirname(__DIR__) . '/Tools/ChatParser.php';

		$output->writeln('');
		$output->writeln('<info>Frogman Interactive Console</info>');
		$output->writeln('<comment>Type a command, "help" for options, or "quit" to exit.</comment>');
		$output->writeln('');

		while (true) {
			$line = readline('frogman> ');
			if ($line === false) break; // Ctrl+D

			$line = trim($line);
			if ($line === '') continue;

			// Add to readline history
			readline_add_history($line);

			// Exit commands
			if (in_array(strtolower($line), ['quit', 'exit', 'bye', 'q'])) {
				$output->writeln('<comment>Goodbye.</comment>');
				break;
			}

			// Parse through ChatParser
			$parsed = \FreePBX\modules\Frogman\ChatParser::parse($line, $sessionId);

			// Direct text response (help, error, cancel)
			if (isset($parsed['response'])) {
				$output->writeln($this->formatForTerminal($parsed['response']));
				continue;
			}

			// Tool execution
			$toolName = $parsed['tool'];
			$params = $parsed['params'];

			$result = $frogman->runTool($toolName, $params, 0, $sessionId);

			// Format using the same formatter as the web console
			$reply = $frogman->formatToolResult($toolName, $result, $sessionId);

			// Check for follow-up offers
			$followUp = $frogman->getFollowUpOffer($toolName, $result, $params);
			if ($followUp) {
				\FreePBX\modules\Frogman\ChatParser::setFollowUp($sessionId, $followUp['tool'], $followUp['params']);
				$reply .= "\n\n" . $followUp['question'] . ' (yes/no)';
			}

			$output->writeln($this->formatForTerminal($reply));
			$output->writeln('');
		}

		return 0;
	}

	/**
	 * Convert chat markup to terminal-friendly output.
	 * Strips {{cmd:...|label}} to just the label, converts markdown to ANSI.
	 */
	private function formatForTerminal($text) {
		// Strip clickable commands — show just the label
		$text = preg_replace('/\{\{cmd:[^|]+\|([^}]+)\}\}/', '$1', $text);

		// Strip markdown links — show just the text
		$text = preg_replace('/\[([^\]]+)\]\(https?:\/\/[^)]+\)/', '$1', $text);

		// Bold → ANSI bright
		$text = preg_replace('/\*\*(.+?)\*\*/', "\033[1m$1\033[0m", $text);

		// Inline code → ANSI cyan
		$text = preg_replace('/`([^`]+)`/', "\033[36m$1\033[0m", $text);

		// Code blocks → indented, no backticks
		$text = preg_replace('/```([\s\S]*?)```/', '$1', $text);

		return $text;
	}
}
