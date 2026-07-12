<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

/**
 * Everything the analyzer needs to know about a require target, from a single
 * parse: which class-likes the file declares (Composer's classmap view,
 * guarded declarations included), which of those it declares unconditionally
 * at the namespace top level, and the inventory of its top-level side effects.
 *
 * A side effect is any namespace-top-level statement that is not a class-like
 * declaration or pure scaffolding (`declare`, `use` imports) — the statements
 * Composer autoload would never reproduce. `kind` is one of: `function`,
 * `constant`, `define`, `ini_set`, `call`, `assignment`, `include`,
 * `control_flow`, `return`, `output`, `other`, `unparsable`. The list may
 * grow; consumers must tolerate unknown kinds.
 *
 * @phpstan-type SideEffect array{kind: string, line: int, excerpt: string}
 *
 * @internal
 */
final class TargetProfile
{
    /**
     * @param bool $parsed false when the source could not be parsed; the side
     *                     effects then hold a single `unparsable` record
     * @param list<string> $declaredClasses FQCNs found anywhere in the file,
     *                                      matching Composer's classmap generator
     * @param list<string> $topLevelClasses FQCNs declared unconditionally at the
     *                                      namespace top level
     * @param list<SideEffect> $sideEffects
     */
    public function __construct(
        public readonly bool $parsed,
        public readonly array $declaredClasses,
        public readonly array $topLevelClasses,
        public readonly array $sideEffects,
    ) {
    }

    /**
     * Reports whether the file is a "pure declaration file" (PSR-1: a file
     * should declare symbols OR cause side effects, not both). Unparsable
     * source counts as having side effects, keeping callers on the safe side.
     */
    public function declaresOnlyTypes(): bool
    {
        return $this->parsed && $this->sideEffects === [];
    }
}
