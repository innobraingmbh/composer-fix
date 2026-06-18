<?php

namespace Innobrain\ComposerFix;

final class ScanResult
{
    /**
     * @param  list<Vulnerability>  $vulnerabilities
     * @param  list<string>  $unreachableRepos
     */
    public function __construct(
        public readonly array $vulnerabilities,
        public readonly array $unreachableRepos,
    ) {}

    public function isClean(): bool
    {
        return $this->vulnerabilities === [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Vulnerability $vulnerability): string => $vulnerability->name(), $this->vulnerabilities);
    }

    public function advisoryCount(): int
    {
        return array_sum(array_map(static fn (Vulnerability $vulnerability): int => count($vulnerability->advisories), $this->vulnerabilities));
    }
}
