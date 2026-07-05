<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

/**
 * Extracts fully qualified class/interface/trait/enum names declared in PHP source code.
 *
 * @internal
 */
final class DeclaredClassExtractor
{
    /**
     * Extracts declared class names (FQCN) from the given source code.
     * Anonymous classes (`new class { ... }`) are excluded and duplicate names are removed.
     *
     * @return list<string>
     */
    public function extract(string $content): array
    {
        $tokens = Token::tokenize($content);
        $tokenCount = count($tokens);
        $namespace = '';
        $classNames = [];

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            $id = $token->id;

            if ($id === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $tokenCount; $j++) {
                    $nameToken = $tokens[$j];
                    if ($nameToken->text === ';' || $nameToken->text === '{') {
                        break;
                    }
                    if (TokenHelper::isNameToken($nameToken->id)) {
                        $namespace .= $nameToken->text;
                    }
                }
                continue;
            }

            if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            if ($id === T_CLASS && $this->isNonDeclarationClassKeyword($tokens, $i)) {
                continue;
            }

            for ($j = $i + 1; $j < $tokenCount; $j++) {
                if ($tokens[$j]->id === T_STRING) {
                    $shortName = $tokens[$j]->text;
                    $classNames[] = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                    break;
                }
            }
        }

        return array_values(array_unique($classNames));
    }

    /**
     * Reports whether the file is a "pure declaration file": at the namespace
     * top level it contains nothing but class/interface/trait/enum declarations
     * (plus the allowed scaffolding — `declare`, `namespace`, `use` imports,
     * attributes, and modifiers). This mirrors PSR-1's "a file should declare
     * symbols OR cause side effects, not both".
     *
     * It matters because Composer autoload only ever loads class-like
     * declarations, and only lazily on first reference. If a required file also
     * defines functions or constants, or runs top-level statements, autoload
     * does not reproduce those, so the require is load-bearing and must not be
     * called redundant/fixable even when its classes are autoload-reachable.
     *
     * The check errs toward reporting side effects: anything unrecognized at the
     * top level counts, so the caller stays on the safe side (treats the require
     * as needed rather than deletable).
     */
    public function declaresOnlyTypes(string $content): bool
    {
        $tokens = Token::tokenize($content);
        $count = count($tokens);

        // Classify each top-level statement. Class-like declarations have their
        // whole body skipped, so the only braces this loop ever sees belong to
        // (braced) namespaces, whose contents are themselves top level — hence
        // braces are simply stepped over.
        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i]->id;
            $text = $tokens[$i]->text;

            // Scaffolding that carries no symbol and no side effect.
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            if ($text === ';' || $text === '{' || $text === '}') {
                continue;
            }
            if (in_array($id, [T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                continue; // modifiers preceding a class declaration
            }
            if ($id === T_ATTRIBUTE) {
                $i = $this->skipAttribute($tokens, $count, $i);
                continue;
            }

            // declare(...) / namespace ... / use ...: step over the header. For a
            // braced namespace the following `{` is stepped over above, so its
            // body is scanned as top level.
            if (in_array($id, [T_DECLARE, T_NAMESPACE, T_USE], true)) {
                $i = $this->skipToHeaderEnd($tokens, $count, $i);
                continue;
            }

            // A class-like declaration: skip its entire body.
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && !($id === T_CLASS && $this->isNonDeclarationClassKeyword($tokens, $i))
            ) {
                $i = $this->skipBody($tokens, $count, $i);
                continue;
            }

            // Anything else at the top level is a side effect or a
            // non-autoloadable symbol (function, const, statement, output, …).
            return false;
        }

        return true;
    }

    /**
     * Advances past a statement/declaration header, stopping at the index of the
     * terminating top-level `;` or `{`. Parenthesis and bracket nesting is
     * tracked so a `;`/`{` inside them — e.g. `declare(strict_types=1)` — does
     * not end the header.
     *
     * @param list<Token> $tokens
     */
    private function skipToHeaderEnd(array $tokens, int $count, int $start): int
    {
        $paren = 0;
        $bracket = 0;
        for ($j = $start + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren--;
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
            } elseif ($paren === 0 && $bracket === 0 && ($text === '{' || $text === ';')) {
                return $j;
            }
        }

        return $count - 1;
    }

    /**
     * From a class-like keyword, advances to the closing `}` of its body (the
     * matching brace of the first `{` after the keyword), tracking nested braces.
     *
     * @param list<Token> $tokens
     */
    private function skipBody(array $tokens, int $count, int $start): int
    {
        $depth = 0;
        $seenBrace = false;
        for ($j = $start + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '{') {
                $depth++;
                $seenBrace = true;
            } elseif ($text === '}') {
                $depth--;
                if ($seenBrace && $depth === 0) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Advances past a `#[...]` attribute group, returning the index of its
     * closing `]` (bracket nesting is tracked for nested arrays inside).
     *
     * @param list<Token> $tokens
     */
    private function skipAttribute(array $tokens, int $count, int $start): int
    {
        // T_ATTRIBUTE ("#[") opens one bracket level.
        $bracket = 1;
        for ($j = $start + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
                if ($bracket === 0) {
                    return $j;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Reports whether a `class` keyword is something other than a type
     * declaration, based on the preceding significant token:
     *   - `new class { ... }`  → an anonymous class (preceded by `new`)
     *   - `Foo::class`         → the class-name constant (preceded by `::`)
     * In both cases the following identifier is not a declared class name and
     * must not be collected (otherwise `Foo::class` would fabricate a phantom
     * declaration from whatever token happens to follow).
     *
     * @param list<Token> $tokens
     */
    private function isNonDeclarationClassKeyword(array $tokens, int $classIndex): bool
    {
        for ($i = $classIndex - 1; $i >= 0; $i--) {
            $id = $tokens[$i]->id;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $id === T_NEW || $id === T_DOUBLE_COLON;
        }

        return false;
    }
}
