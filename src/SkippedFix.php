<?php

namespace Innobrain\ComposerFix;

final class SkippedFix
{
    /** Safe version exists but is outside the root constraint — --force can bump it. */
    public const OUT_OF_RANGE = 'out-of-range';

    /** Safe version exists but the constraints of installed dependents exclude it. */
    public const TRANSITIVE = 'transitive';

    /** Safe version exists but a pool filter (e.g. soak-time) hides it. */
    public const HELD_BACK = 'held-back';

    /** Only a branch head escapes the advisory — a dev build is not a fix. */
    public const DEV_ONLY = 'dev-only';

    /** No published version escapes the advisory. */
    public const UNFIXABLE = 'unfixable';

    public function __construct(
        public readonly string $name,
        public readonly string $reason,
        public readonly ?string $safeVersion = null,
    ) {}
}
