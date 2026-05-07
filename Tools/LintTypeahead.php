<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * Walk every `preg_match('/^...')` anchor in ChatParser.php, derive a canonical
 * keyword for each one, and report anchors whose keyword doesn't appear anywhere
 * in the typeahead's suggestion list. Catches the case where someone adds a parser
 * regex without also adding a discoverable phrase to helpText.
 *
 * Run after adding any new chat command. Clean output ("0 gaps") = typeahead in
 * sync with parser.
 */
class LintTypeahead extends AbstractTool {
	public function name() { return 'fm_lint_typeahead'; }
	public function description() { return 'Dev/release lint: report chat parser anchors that have no matching backtick phrase in helpText (so they\'re unreachable from typeahead). Returns gaps grouped by keyword. 0 gaps = typeahead in sync with parser.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$parserPath = __DIR__ . '/ChatParser.php';
		$src = @file_get_contents($parserPath);
		if ($src === false) {
			throw new \Exception("Cannot read ChatParser.php at {$parserPath}");
		}

		// Anchors we never want to lint — wizard responses, confirms, bare-number entry,
		// etc. — they're not user-typed top-level commands.
		$skipKeywords = [
			'yes', 'y', 'ok', 'sure', 'yep', 'yeah',
			'no', 'n', 'cancel', 'skip', 'nevermind', 'nope', 'abort',
			'help', '?', 'cancel', 'stop',
		];

		require_once dirname(__DIR__) . '/Tools/ChatParser.php';
		$suggestions = array_map('strtolower', \FreePBX\modules\Frogman\ChatParser::getSuggestions());
		$haystack = "\n" . implode("\n", $suggestions) . "\n";

		// Match `preg_match('/^...'`  — the anchor at start-of-pattern.
		preg_match_all("#preg_match\\('/\\^([^']{1,200})'#", $src, $m);
		$anchors = array_unique($m[1]);

		$gaps = [];
		$keywordsSeen = [];
		foreach ($anchors as $rawAnchor) {
			if (preg_match('/^[\(\\\\\[\^\$\d\s]+\)?\$?\/?[a-z]?$/i', $rawAnchor)) continue;
			$synonyms = $this->extractKeywords($rawAnchor);
			// If ANY synonym is a wizard-confirm/cancel keyword, the whole anchor
			// is a wizard handler — not a user-typed top-level command.
			foreach ($synonyms as $k) {
				if (in_array($k, $skipKeywords, true)) continue 2;
			}
			$synonyms = array_filter($synonyms, function($k) {
				return strlen($k) >= 3;
			});
			if (empty($synonyms)) continue;

			// Dedup using the canonical (first) synonym so the same anchor isn't
			// reported twice across iterations.
			$canonical = reset($synonyms);
			if (isset($keywordsSeen[$canonical])) continue;
			$keywordsSeen[$canonical] = true;

			// Covered when ANY synonym appears as a word in the typeahead haystack.
			$covered = false;
			foreach ($synonyms as $kw) {
				$pattern = '/(^|\s)' . preg_quote($kw, '/') . '(\s|$)/i';
				if (preg_match($pattern, $haystack)) { $covered = true; break; }
			}
			if ($covered) continue;

			$gaps[] = [
				'keyword' => $canonical,
				'synonyms' => array_values($synonyms),
				'anchor' => substr($rawAnchor, 0, 80),
			];
		}

		// Sort gaps for stable output
		usort($gaps, function($a, $b) { return strcmp($a['keyword'], $b['keyword']); });

		return [
			'parser_anchors_total' => count($anchors),
			'distinct_keywords' => count($keywordsSeen),
			'typeahead_suggestions' => count($suggestions),
			'gap_count' => count($gaps),
			'gaps' => $gaps,
			'verdict' => empty($gaps)
				? '✓ typeahead in sync with parser'
				: count($gaps) . ' parser anchor(s) have keywords with no helpText coverage — add a backtick phrase to ChatParser::helpText()',
		];
	}

	/**
	 * Pull every literal start-token a user could type for this anchor. Returns an
	 * array of synonyms. For `(busiest|top)\s+ext` returns ['busiest', 'top']; for
	 * `^reload$` returns ['reload']; for purely-numeric anchors returns []. The
	 * caller treats coverage as "any synonym appears in typeahead = covered".
	 */
	private function extractKeywords($anchor) {
		$s = $anchor;
		$s = preg_replace('/\(\?:/', '(', $s);
		$s = preg_replace('/\(\?[=!<].*?\)/', '', $s);

		if (!preg_match('/[a-z]/i', $s)) return [];

		// Anchor starts with (a|b|c) → return every literal alternative.
		if (preg_match('/^\(([^)]+)\)/', $s, $m)) {
			$out = [];
			foreach (explode('|', $m[1]) as $alt) {
				if (preg_match('/[a-z][a-z_]*/i', $alt, $w)) {
					$out[] = strtolower($w[0]);
				}
			}
			return array_unique(array_filter($out));
		}

		// Otherwise the first literal word run.
		if (preg_match('/[a-z][a-z_]+/i', $s, $m)) {
			return [strtolower($m[0])];
		}
		return [];
	}

	private function extractKeyword($anchor) {
		$kws = $this->extractKeywords($anchor);
		return $kws ? $kws[0] : null;
	}
}
