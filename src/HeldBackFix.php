<?php

namespace Innobrain\ComposerFix;

final class HeldBackFix
{
    public function __construct(
        public readonly string $name,
        public readonly string $safeVersion,
    ) {}
}
