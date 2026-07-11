<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use Depone\Internal\Tokenizer\DeclaredClassExtractor;
use Depone\Internal\Tokenizer\PathHelper;
use Depone\Internal\Tokenizer\Token;

/**
 * Removes provably-redundant `require_once` statements from source files.
 *
 * Only the `redundant` entries produced by {@see Analyzer::run()} are ever
 * touched — never `conflicting` or `unresolved`, which are hazards or unknowns.
 * A statement is removed only when it can be located unambiguously, it is a
 * standalone statement (not the brace-less body of a control structure and not
 * an operand of a larger expression — deleting those changes what the
 * surrounding code means, see {@see isStandaloneStatement()}), and it sits on
 * its own line(s) (nothing but whitespace before the `require_once` and after
 * the terminating `;`). Anything else is left untouched and reported as
 * skipped, so the fix never rewrites code it cannot delete cleanly. After
 * splicing, the file is re-parsed and written back only if it still parses —
 * a belt-and-braces guard against a removal that would break the file.
 *
 * @phpstan-import-type RedundantEntry from \Depone\Internal\Core\Analyzer
 * @phpstan-type Statement array{start: int, end: int, standalone: bool}
 * @phpstan-type RemovedEntry array{file: string, line: int, target: string}
 * @phpstan-type SkippedEntry array{file: string, line: int, target: string, reason: string}
 * @phpstan-type FixReport array{removed: list<RemovedEntry>, skipped: list<SkippedEntry>}
 *
 * @internal
 */
final class RedundantRequireRemover
{
    private string $repoRoot;
    private DeclaredClassExtractor $classExtractor;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
        $this->classExtractor = new DeclaredClassExtractor();
    }

    /**
     * Removes the given redundant require_once statements, writing each changed
     * file back to disk. Files are grouped so each is read, edited, and written
     * once even when it holds several redundant requires.
     *
     * A file that cannot be read or written is reported under `skipped` and the
     * run continues: throwing mid-run would abort with files already edited on
     * disk and no report of what changed.
     *
     * @param list<RedundantEntry> $redundant
     * @return FixReport
     */
    public function fix(array $redundant): array
    {
        $byFile = [];
        foreach ($redundant as $entry) {
            $byFile[$entry['file']][] = $entry;
        }
        // Deterministic order regardless of how the caller sorted the input.
        ksort($byFile);

        $removed = [];
        $skipped = [];

        foreach ($byFile as $relativeFile => $entries) {
            $absolute = PathHelper::normalize($this->repoRoot . '/' . $relativeFile);
            $content = @file_get_contents($absolute);
            if (!is_string($content)) {
                $skipped = array_merge($skipped, $this->skipAll($entries, 'could not read the file'));
                continue;
            }

            [$newContent, $fileRemoved, $fileSkipped] = $this->rewriteFile($content, $entries);

            if ($fileRemoved !== []) {
                // Never write source that no longer parses: a clean removal
                // cannot break parsing, so a failure here means our own span
                // logic was wrong — skip the file rather than corrupt it. Only
                // the entries that were actually cut are re-marked; the ones
                // rewriteFile skipped already carry their own reason.
                if (!$this->classExtractor->isParseable($newContent)) {
                    $fileSkipped = array_merge($fileSkipped, $this->skipAll($fileRemoved, 'removal would not re-parse'));
                    $fileRemoved = [];
                } elseif (@file_put_contents($absolute, $newContent) === false) {
                    $fileSkipped = array_merge($fileSkipped, $this->skipAll($fileRemoved, 'could not write the file'));
                    $fileRemoved = [];
                }
            }

            $removed = array_merge($removed, $fileRemoved);
            $skipped = array_merge($skipped, $fileSkipped);
        }

        return ['removed' => $removed, 'skipped' => $skipped];
    }

    /**
     * Rewrites a single file's content, removing the redundant require_once
     * statements that can be deleted cleanly.
     *
     * @param list<RedundantEntry> $entries
     * @return array{0: string, 1: list<RemovedEntry>, 2: list<SkippedEntry>}
     */
    private function rewriteFile(string $content, array $entries): array
    {
        $statementsByLine = $this->requireOnceStatementsByLine($content);

        $removed = [];
        $skipped = [];
        /** @var list<array{0: int, 1: int}> $regions [start, end) byte spans to cut */
        $regions = [];

        foreach ($entries as $entry) {
            $line = $entry['line'];
            $statements = $statementsByLine[$line] ?? [];

            // Locate the statement unambiguously, then require that it is a
            // standalone statement standing alone on its line(s) before
            // cutting it.
            if (count($statements) !== 1) {
                $skipped[] = $this->skip($entry, 'could not locate the statement unambiguously');
                continue;
            }

            if (!$statements[0]['standalone']) {
                $skipped[] = $this->skip($entry, 'body of a control structure or part of an expression');
                continue;
            }

            $region = $this->soloLineRegion($content, $statements[0]);
            if ($region === null) {
                $skipped[] = $this->skip($entry, 'shares a line with other code or a comment');
                continue;
            }

            $regions[] = $region;
            $removed[] = ['file' => $entry['file'], 'line' => $line, 'target' => $entry['target']];
        }

        // Splice from the end so earlier offsets stay valid.
        usort($regions, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($regions as [$start, $end]) {
            $content = substr($content, 0, $start) . substr($content, $end);
        }

        return [$content, $removed, $skipped];
    }

    /**
     * Indexes every `require_once` statement in the file by the line its keyword
     * starts on. Each statement records the byte span from the keyword to just
     * past its terminating `;`, and whether the keyword sits in standalone
     * statement position (see {@see isStandaloneStatement()}).
     *
     * @return array<int, list<Statement>>
     */
    private function requireOnceStatementsByLine(string $content): array
    {
        $tokens = Token::tokenize($content);
        $count = count($tokens);

        $byLine = [];
        $offset = 0;
        $previousSignificant = null;
        foreach ($tokens as $i => $token) {
            if ($token->id === T_REQUIRE_ONCE) {
                $end = $this->statementEnd($tokens, $count, $i, $offset);
                if ($end !== null) {
                    $byLine[$token->line][] = [
                        'start' => $offset,
                        'end' => $end,
                        'standalone' => $this->isStandaloneStatement($previousSignificant),
                    ];
                }
            }
            if (!in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificant = $token;
            }
            $offset += strlen($token->text);
        }

        return $byLine;
    }

    /**
     * Reports whether a `require_once` preceded by the given significant token
     * is a standalone statement — one whose removal deletes exactly itself.
     *
     * Anything else must not be cut, because deleting the require rewires the
     * code around it rather than removing a no-op — and the result can still
     * parse, so the re-parse guard cannot catch it:
     *
     *   - after `if (...)`, `else`, `do`, ... it is the structure's entire
     *     brace-less body, and removal rebinds the next statement as the body;
     *   - after `return`, `=`, `=>`, ... it is an operand of a larger
     *     expression, and removal grafts the next statement onto it.
     *
     * Only an open tag, `;`, `{`, `}`, `:` (a label or alternative-syntax
     * block, where removing one statement removes only itself), or the start
     * of the file put the keyword in standalone position.
     */
    private function isStandaloneStatement(?Token $previous): bool
    {
        if ($previous === null || $previous->id === T_OPEN_TAG) {
            return true;
        }

        return in_array($previous->text, [';', '{', '}', ':'], true);
    }

    /**
     * Scans forward from the `require_once` keyword to the byte offset just past
     * its terminating `;` (the first `;` at parenthesis depth zero), or null when
     * no terminator is found.
     *
     * @param list<Token> $tokens
     */
    private function statementEnd(array $tokens, int $count, int $keywordIndex, int $keywordOffset): ?int
    {
        $offset = $keywordOffset;
        $depth = 0;
        for ($j = $keywordIndex; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
            } elseif ($text === ';' && $depth === 0) {
                return $offset + strlen($text);
            }
            $offset += strlen($text);
        }

        return null;
    }

    /**
     * Returns the byte span to cut when the statement stands alone on its
     * line(s) — the whole line region including leading indentation and the
     * trailing newline — or null when the statement shares a line with other
     * code or a trailing comment (in which case it must not be auto-removed).
     *
     * @param Statement $statement
     * @return array{0: int, 1: int}|null [start, end) span, or null to skip
     */
    private function soloLineRegion(string $content, array $statement): ?array
    {
        $newlineBefore = strrpos(substr($content, 0, $statement['start']), "\n");
        $lineStart = $newlineBefore === false ? 0 : $newlineBefore + 1;

        $newlineAfter = strpos($content, "\n", $statement['end']);
        $lineEnd = $newlineAfter === false ? strlen($content) : $newlineAfter;

        $before = substr($content, $lineStart, $statement['start'] - $lineStart);
        $after = substr($content, $statement['end'], $lineEnd - $statement['end']);
        if (trim($before) !== '' || trim($after) !== '') {
            return null;
        }

        // Include the trailing newline so no blank line is left behind.
        $removeEnd = $newlineAfter === false ? strlen($content) : $newlineAfter + 1;

        return [$lineStart, $removeEnd];
    }

    /**
     * @param list<RedundantEntry>|list<RemovedEntry> $entries
     * @return list<SkippedEntry>
     */
    private function skipAll(array $entries, string $reason): array
    {
        return array_map(fn (array $entry): array => $this->skip($entry, $reason), $entries);
    }

    /**
     * @param RedundantEntry|RemovedEntry $entry
     * @return SkippedEntry
     */
    private function skip(array $entry, string $reason): array
    {
        return [
            'file' => $entry['file'],
            'line' => $entry['line'],
            'target' => $entry['target'],
            'reason' => $reason,
        ];
    }
}
