<?php

namespace Innobrain\ComposerFix;

final class BumpPlan
{
    /**
     * @param  list<ConstraintBump>  $bumps  root constraints to widen to a safe version (--force only)
     * @param  list<string>  $updatable  packages with a safe version reachable within the current constraints
     * @param  list<SkippedFix>  $skipped  packages left at their installed version, with the reason
     */
    public function __construct(
        public readonly array $bumps,
        public readonly array $updatable,
        public readonly array $skipped,
    ) {}

    public function hasBumps(): bool
    {
        return $this->bumps !== [];
    }

    /**
     * @return list<string>
     */
    public function allowList(): array
    {
        return array_merge(
            array_map(static fn (ConstraintBump $bump): string => $bump->name, $this->bumps),
            $this->updatable,
        );
    }
}
