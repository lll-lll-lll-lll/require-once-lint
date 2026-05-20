<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Resolver;

use RedundantRequireOnce\Tokenizer\Token;
use RedundantRequireOnce\Tokenizer\TokenHelper;

/**
 * Detects class references inside a PHP file.
 *
 * Supported reference kinds:
 * - use statements (namespace imports)
 * - new ClassName
 * - ClassName::method() / ClassName::CONST
 * - extends ClassName
 * - implements Interface1, Interface2
 * - type hints (parameters and return types)
 * - catch (ExceptionClass $e)
 * - instanceof ClassName
 */
final class ClassReferenceDetector
{
    /**
     * PHP built-in scalar and special type names that must not be treated as class references.
     */
    private const BUILTIN_TYPES = [
        'int', 'string', 'bool', 'float', 'array', 'callable',
        'iterable', 'object', 'mixed', 'void', 'null', 'false', 'true', 'never',
    ];

    /**
     * Detects class references in a file.
     *
     * @param string $content PHP source code
     * @return array<string> List of fully qualified class names
     */
    public function detect(string $content): array
    {
        $tokens = Token::tokenize($content);
        $tokenCount = count($tokens);

        $namespace = '';
        $useMap = [];      // alias => FQCN
        $references = [];  // collected class references

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            $id = TokenHelper::id($token);

            // namespace declaration
            if ($id === T_NAMESPACE) {
                $namespace = $this->parseNamespace($tokens, $i, $tokenCount);
                continue;
            }

            // use statement
            if ($id === T_USE) {
                $uses = $this->parseUseStatement($tokens, $i, $tokenCount);
                foreach ($uses as $alias => $fqcn) {
                    $useMap[$alias] = $fqcn;
                    $references[] = $fqcn;
                }
                continue;
            }

            // new ClassName
            if ($id === T_NEW) {
                $className = $this->parseClassNameAfter($tokens, $i, $tokenCount);
                if ($className !== null) {
                    $resolved = $this->resolveClassName($className, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // extends ClassName
            if ($id === T_EXTENDS) {
                $className = $this->parseClassNameAfter($tokens, $i, $tokenCount);
                if ($className !== null) {
                    $resolved = $this->resolveClassName($className, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // implements Interface1, Interface2
            if ($id === T_IMPLEMENTS) {
                $interfaces = $this->parseCommaSeparatedClassNames($tokens, $i, $tokenCount);
                foreach ($interfaces as $interface) {
                    $resolved = $this->resolveClassName($interface, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // ClassName::method() or ClassName::CONST (static access)
            if ($id === T_DOUBLE_COLON) {
                $className = $this->parseClassNameBefore($tokens, $i);
                if ($className !== null) {
                    $resolved = $this->resolveClassName($className, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // instanceof ClassName
            if ($id === T_INSTANCEOF) {
                $className = $this->parseClassNameAfter($tokens, $i, $tokenCount);
                if ($className !== null) {
                    $resolved = $this->resolveClassName($className, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // catch (ExceptionClass $e)
            if ($id === T_CATCH) {
                $exceptions = $this->parseCatchClasses($tokens, $i, $tokenCount);
                foreach ($exceptions as $exception) {
                    $resolved = $this->resolveClassName($exception, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // function and method type hints
            if ($id === T_FUNCTION) {
                $typeHints = $this->parseTypeHints($tokens, $i, $tokenCount);
                foreach ($typeHints as $typeHint) {
                    $resolved = $this->resolveClassName($typeHint, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
            }

            // PHP 8.0+ attributes (#[ClassName(...)])
            if ($id === T_ATTRIBUTE) {
                $attrClasses = $this->parseAttributeClassNames($tokens, $i, $tokenCount);
                foreach ($attrClasses as $attrClass) {
                    $resolved = $this->resolveClassName($attrClass, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
                continue;
            }

            // property type declarations following visibility modifiers
            if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $typeHints = $this->parsePropertyTypeHints($tokens, $i, $tokenCount);
                foreach ($typeHints as $typeHint) {
                    $resolved = $this->resolveClassName($typeHint, $namespace, $useMap);
                    if ($resolved !== null) {
                        $references[] = $resolved;
                    }
                }
            }
        }

        return array_unique($references);
    }

    /**
     * Parses a namespace declaration.
     *
     * @param list<Token> $tokens
     */
    private function parseNamespace(array $tokens, int $i, int $tokenCount): string
    {
        $namespace = '';
        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            if ($token->text === ';' || $token->text === '{') {
                break;
            }
            $id = TokenHelper::id($token);
            if (TokenHelper::isNameToken($id)) {
                $namespace .= TokenHelper::text($token);
            }
        }

        return trim($namespace);
    }

    /**
     * Parses a use statement.
     *
     * @param list<Token> $tokens
     * @return array<string, string> Map of alias => FQCN
     */
    private function parseUseStatement(array $tokens, int $i, int $tokenCount): array
    {
        // Closure use clauses (`function () use ($var) {}`) start with `(` after `use`.
        // They are variable captures, not namespace imports, so skip them entirely.
        $peek = $i + 1;
        TokenHelper::skipTrivia($tokens, $peek);
        if (isset($tokens[$peek]) && $tokens[$peek]->text === '(') {
            return [];
        }

        $uses = [];
        $current = '';
        $alias = null;
        $inGroup = false;
        $groupPrefix = '';

        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $text = TokenHelper::text($token);
            $id = TokenHelper::id($token);

            // Ignore function/const imports.
            if ($id === T_FUNCTION || $id === T_CONST) {
                // Skip to the end of the statement.
                while ($j < $tokenCount && $tokens[$j]->text !== ';') {
                    $j++;
                }
                return [];
            }

            if ($token->text === ';') {
                if ($current !== '') {
                    $fqcn = ltrim($inGroup ? $groupPrefix . $current : $current, '\\');
                    $shortName = $alias ?? $this->getShortName($fqcn);
                    $uses[$shortName] = $fqcn;
                }
                break;
            }

            if ($token->text === '{') {
                $groupPrefix = $current;
                $current = '';
                $inGroup = true;
                continue;
            }

            if ($token->text === '}') {
                if ($current !== '') {
                    $fqcn = ltrim($groupPrefix . $current, '\\');
                    $shortName = $alias ?? $this->getShortName($fqcn);
                    $uses[$shortName] = $fqcn;
                }
                $current = '';
                $alias = null;
                $inGroup = false;
                continue;
            }

            if ($token->text === ',') {
                if ($current !== '') {
                    $fqcn = ltrim($inGroup ? $groupPrefix . $current : $current, '\\');
                    $shortName = $alias ?? $this->getShortName($fqcn);
                    $uses[$shortName] = $fqcn;
                }
                $current = '';
                $alias = null;
                continue;
            }

            if ($id === T_AS) {
                // The next T_STRING token is the alias.
                for ($k = $j + 1; $k < $tokenCount; $k++) {
                    if (TokenHelper::id($tokens[$k]) === T_STRING) {
                        $alias = TokenHelper::text($tokens[$k]);
                        $j = $k;
                        break;
                    }
                    if ($tokens[$k]->text === ',' || $tokens[$k]->text === ';') {
                        $j = $k - 1;
                        break;
                    }
                }
                continue;
            }

            if (TokenHelper::isNameToken($id)) {
                $current .= $text;
            }
        }

        return $uses;
    }

    /**
     * Returns the short class name from an FQCN.
     */
    private function getShortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Parses a class name after the current token position.
     *
     * @param list<Token> $tokens
     */
    private function parseClassNameAfter(array $tokens, int $i, int $tokenCount): ?string
    {
        $className = '';
        $started = false;

        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            // Skip whitespace and comments.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                if ($started) {
                    break;
                }
                continue;
            }

            // Parts of a class name.
            if (TokenHelper::isNameToken($id)) {
                $text = TokenHelper::text($token);
                // self/static/parent need special handling.
                $lowerText = strtolower($text);
                if (!$started && in_array($lowerText, ['self', 'parent', 'static'], true)) {
                    return null;
                }
                $className .= $text;
                $started = true;
                continue;
            }

            // Skip dynamic instantiation such as new $variable.
            if ($id === T_VARIABLE) {
                return null;
            }

            // static also needs special handling when tokenized as T_STATIC.
            if ($id === T_STATIC) {
                return null;
            }

            // Stop at any other token.
            if ($started) {
                break;
            }

            break;
        }

        return $className !== '' ? $className : null;
    }

    /**
     * Parses a class name before the current token position (before `::`).
     *
     * @param list<Token> $tokens
     */
    private function parseClassNameBefore(array $tokens, int $i): ?string
    {
        $className = '';

        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            // Skip whitespace and comments.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                if ($className !== '') {
                    break;
                }
                continue;
            }

            // Skip dynamic static calls such as $var::method().
            if ($id === T_VARIABLE) {
                return null;
            }

            // self/static/parent need special handling.
            if ($id === T_STATIC) {
                return null;
            }

            // Parts of a class name.
            if (TokenHelper::isNameToken($id)) {
                $text = TokenHelper::text($token);
                // self/parent need special handling.
                $lowerText = strtolower($text);
                if ($lowerText === 'self' || $lowerText === 'parent') {
                    return null;
                }
                $className = $text . $className;
                continue;
            }

            break;
        }

        return $className !== '' ? $className : null;
    }

    /**
     * Parses comma-separated class names such as those in implements clauses.
     *
     * @param list<Token> $tokens
     * @return array<string>
     */
    private function parseCommaSeparatedClassNames(array $tokens, int $i, int $tokenCount): array
    {
        $classNames = [];
        $current = '';

        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            // Stop at the opening class body.
            if ($token->text === '{') {
                if ($current !== '') {
                    $classNames[] = $current;
                }
                break;
            }

            // Split on commas.
            if ($token->text === ',') {
                if ($current !== '') {
                    $classNames[] = $current;
                }
                $current = '';
                continue;
            }

            // Skip whitespace.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            // Parts of a class name.
            if (TokenHelper::isNameToken($id)) {
                $current .= TokenHelper::text($token);
            }
        }

        return $classNames;
    }

    /**
     * Parses class names from a catch clause.
     *
     * @param list<Token> $tokens
     * @return array<string>
     */
    private function parseCatchClasses(array $tokens, int $i, int $tokenCount): array
    {
        $classes = [];
        $current = '';
        $inParen = false;

        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            if ($token->text === '(') {
                $inParen = true;
                continue;
            }

            if ($token->text === ')') {
                if ($current !== '') {
                    $classes[] = $current;
                }
                break;
            }

            if (!$inParen) {
                continue;
            }

            // Split on `|` for PHP 8.0+ multi-catch.
            if ($token->text === '|') {
                if ($current !== '') {
                    $classes[] = $current;
                }
                $current = '';
                continue;
            }

            // Stop when reaching the caught variable.
            if ($id === T_VARIABLE) {
                if ($current !== '') {
                    $classes[] = $current;
                }
                break;
            }

            // Skip whitespace.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            // Parts of a class name.
            if (TokenHelper::isNameToken($id)) {
                $current .= TokenHelper::text($token);
            }
        }

        return $classes;
    }

    /**
     * Parses parameter and return type hints from a function or method.
     *
     * @param list<Token> $tokens
     * @return array<string>
     */
    private function parseTypeHints(array $tokens, int $i, int $tokenCount): array
    {
        $typeHints = [];

        // Find the parameter list.
        $parenStart = -1;
        for ($j = $i + 1; $j < $tokenCount; $j++) {
            if ($tokens[$j]->text === '(') {
                $parenStart = $j;
                break;
            }
        }

        if ($parenStart === -1) {
            return [];
        }

        // Parameter type hints.
        $depth = 1;
        $current = '';
        $parenEnd = -1;

        for ($j = $parenStart + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            if ($token->text === '(') {
                $depth++;
                continue;
            }
            if ($token->text === ')') {
                $depth--;
                if ($depth === 0) {
                    if ($current !== '') {
                        $typeHints[] = $current;
                    }
                    $parenEnd = $j;
                    break;
                }
                continue;
            }

            // Stop collecting the current type when reaching a variable.
            if ($id === T_VARIABLE) {
                if ($current !== '') {
                    $typeHints[] = $current;
                }
                $current = '';
                continue;
            }

            // Move on to the next parameter at commas.
            if ($token->text === ',') {
                $current = '';
                continue;
            }

            // Skip the nullable marker.
            if ($token->text === '?') {
                continue;
            }

            // Split union types.
            if ($token->text === '|') {
                if ($current !== '') {
                    $typeHints[] = $current;
                }
                $current = '';
                continue;
            }

            // Skip whitespace.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            // Skip default values after `=`.
            if ($token->text === '=') {
                $current = '';
                // Skip over the full default-value expression.
                $defaultDepth = 0;
                for ($k = $j + 1; $k < $tokenCount; $k++) {
                    if ($tokens[$k]->text === '(' || $tokens[$k]->text === '[') {
                        $defaultDepth++;
                    } elseif ($tokens[$k]->text === ')' || $tokens[$k]->text === ']') {
                        if ($defaultDepth === 0) {
                            $j = $k - 1;
                            break;
                        }
                        $defaultDepth--;
                    } elseif ($tokens[$k]->text === ',' && $defaultDepth === 0) {
                        $j = $k - 1;
                        break;
                    }
                }
                continue;
            }

            // Parts of a class name.
            if (TokenHelper::isNameToken($id)) {
                $text = TokenHelper::text($token);
                // Exclude builtin scalar and special types.
                $lowerText = strtolower($text);
                if (in_array($lowerText, self::BUILTIN_TYPES, true)) {
                    continue;
                }
                $current .= $text;
            }
        }

        // Return type hints.
        if ($parenEnd !== -1) {
            $returnType = '';
            for ($j = $parenEnd + 1; $j < $tokenCount; $j++) {
                $token = $tokens[$j];
                $id = TokenHelper::id($token);

                // Stop at the function body or declaration terminator.
                if ($token->text === '{' || $token->text === ';') {
                    if ($returnType !== '') {
                        $typeHints[] = $returnType;
                    }
                    break;
                }

                // Ignore the return-type separator.
                if ($token->text === ':') {
                    continue;
                }

                // Skip the nullable marker.
                if ($token->text === '?') {
                    continue;
                }

                // Split union types.
                if ($token->text === '|') {
                    if ($returnType !== '') {
                        $typeHints[] = $returnType;
                    }
                    $returnType = '';
                    continue;
                }

                // Skip whitespace.
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }

                // Parts of a class name.
                if (TokenHelper::isNameToken($id)) {
                    $text = TokenHelper::text($token);
                    // Exclude builtin scalar and special types.
                    $lowerText = strtolower($text);
                    if (in_array($lowerText, self::BUILTIN_TYPES, true)) {
                        continue;
                    }
                    $returnType .= $text;
                }
            }
        }

        return $typeHints;
    }

    /**
     * Parses class names inside PHP 8.0+ attribute syntax (`#[...]`).
     *
     * Multiple attributes may appear in a comma-separated list.
     * Attribute arguments inside `(...)` are skipped entirely.
     * Parsing stops at `]`, while tracking parenthesis depth so `]` inside
     * arguments is not treated as the end of the attribute block.
     *
     * Examples:
     *   #[Route('/api')]              -> ['Route']
     *   #[Assert\NotBlank]            -> ['Assert\NotBlank']
     *   #[Attr1, Attr2('x')]          -> ['Attr1', 'Attr2']
     *   #[Column(options: ['a'])]     -> ['Column']  (`]` inside args is ignored)
     *
     * @param list<Token> $tokens
     * @return array<string>
     */
    private function parseAttributeClassNames(array $tokens, int $i, int $tokenCount): array
    {
        $classNames = [];
        $current = '';
        $depth = 0;  // parenthesis depth inside attribute arguments

        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            // `(` starts attribute arguments. Finalize the class name first.
            if ($token->text === '(') {
                if ($current !== '') {
                    $classNames[] = $current;
                    $current = '';
                }
                $depth++;
                continue;
            }

            // `)` closes one level of attribute arguments.
            if ($token->text === ')') {
                $depth--;
                continue;
            }

            // Ignore everything inside attribute arguments.
            if ($depth > 0) {
                continue;
            }

            // `]` ends the attribute block.
            if ($token->text === ']') {
                if ($current !== '') {
                    $classNames[] = $current;
                }
                break;
            }

            // `,` separates attributes.
            if ($token->text === ',') {
                if ($current !== '') {
                    $classNames[] = $current;
                }
                $current = '';
                continue;
            }

            // Skip whitespace and comments.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            // Class-name tokens.
            if (TokenHelper::isNameToken($id)) {
                $current .= TokenHelper::text($token);
            }
        }

        return $classNames;
    }

    /**
     * Parses type hints from property declarations.
     *
     * Parsing starts immediately after `public`/`protected`/`private` and
     * collects type names until reaching T_VARIABLE (the property name).
     * If T_FUNCTION or T_CONST appears first, the declaration is treated as a
     * method or constant and an empty array is returned.
     *
     * Supported forms:
     *   private Bar $x                  -> ['Bar']
     *   public ?Bar $x                  -> ['Bar']
     *   protected Bar|Baz $x            -> ['Bar', 'Baz']
     *   public readonly Bar $x          -> ['Bar']
     *   public static Bar $x            -> ['Bar']
     *   private Bar&Baz $x              -> ['Bar', 'Baz']  (intersection type)
     *   public string $x                -> []  (scalar type)
     *   public $x                       -> []  (untyped)
     *   public function foo(): Bar {}   -> []  (method declaration)
     *
     * @param list<Token> $tokens
     * @return array<string>
     */
    private function parsePropertyTypeHints(array $tokens, int $i, int $tokenCount): array
    {
        $typeHints = [];
        $current = '';

        for ($j = $i + 1; $j < $tokenCount; $j++) {
            $token = $tokens[$j];
            $id = TokenHelper::id($token);

            // Skip whitespace and comments.
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            // Skip modifiers and keep scanning.
            if (in_array($id, [T_STATIC, T_READONLY, T_ABSTRACT, T_FINAL], true)) {
                continue;
            }

            // This is a method or constant declaration, not a property.
            if ($id === T_FUNCTION || $id === T_CONST) {
                return [];
            }

            // Stop once the property variable is reached.
            if ($id === T_VARIABLE) {
                if ($current !== '') {
                    $typeHints[] = $current;
                }
                break;
            }

            // Skip the nullable marker.
            if ($token->text === '?') {
                continue;
            }

            // Split union types.
            if ($token->text === '|') {
                if ($current !== '') {
                    $typeHints[] = $current;
                }
                $current = '';
                continue;
            }

            // Split intersection types.
            // In PHP 8.1+, `&` is tokenized as T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG.
            if ($id === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                if ($current !== '') {
                    $typeHints[] = $current;
                }
                $current = '';
                continue;
            }

            // Class-name tokens.
            if (TokenHelper::isNameToken($id)) {
                $text = TokenHelper::text($token);
                $lowerText = strtolower($text);
                if (in_array($lowerText, self::BUILTIN_TYPES, true)) {
                    $current = '';  // Scalar and builtin types are not class references.
                    continue;
                }
                $current .= $text;
                continue;
            }

            // Stop at any other token such as `;`, `=`, or `{`.
            break;
        }

        return $typeHints;
    }

    /**
     * Resolves a class name to its fully qualified form.
     */
    private function resolveClassName(string $className, string $namespace, array $useMap): ?string
    {
        if ($className === '') {
            return null;
        }

        // Already fully qualified.
        if ($className[0] === '\\') {
            return ltrim($className, '\\');
        }

        // Resolve through the use map first.
        $firstPart = $className;
        $rest = '';
        $nsPos = strpos($className, '\\');
        if ($nsPos !== false) {
            $firstPart = substr($className, 0, $nsPos);
            $rest = substr($className, $nsPos);
        }

        if (isset($useMap[$firstPart])) {
            return $useMap[$firstPart] . $rest;
        }

        // Resolve within the current namespace.
        if ($namespace !== '') {
            return $namespace . '\\' . $className;
        }

        // Fall back to the global namespace.
        return $className;
    }
}
