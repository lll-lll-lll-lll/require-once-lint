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
