<?php

namespace Innobrain\ComposerFix;

final class BumpPlan
{
    /**
     * @param  list<ConstraintBump>  $bumps  root constraints to widen to a safe version
     * @param  list<string>  $transitive  vulnerable packages with no root constraint to bump
     * @param  list<HeldBackFix>  $heldBack  safe version exists but a pool filter (e.g. soak-time) hides it
     * @param  list<string>  $unfixable  no published version escapes the advisory
     */
    public function __construct(
        public readonly array $bumps,
        public readonly array $transitive,
        public readonly array $heldBack,
        public readonly array $unfixable,
    ) {}

    public function hasBumps(): bool
    {
        return $this->bumps !== [];
    }
}
