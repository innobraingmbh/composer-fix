<?php

namespace Innobrain\ComposerFix;

final class ConstraintBump
{
    /**
     * @param  'require'|'require-dev'  $requireKey
     */
    public function __construct(
        public readonly string $name,
        public readonly string $requireKey,
        public readonly string $from,
        public readonly string $to,
        public readonly string $safeVersion,
    ) {}
}
