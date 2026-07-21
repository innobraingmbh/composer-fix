<?php

namespace Innobrain\ComposerFix\Tests;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Innobrain\ComposerFix\SafeVersionResolver;
use PHPUnit\Framework\TestCase;

final class SafeVersionResolverTest extends TestCase
{
    private VersionParser $parser;

    private SafeVersionResolver $resolver;

    protected function setUp(): void
    {
        $this->parser = new VersionParser();
        $this->resolver = new SafeVersionResolver();
    }

    public function test_it_picks_the_lowest_version_outside_the_affected_range(): void
    {
        $safe = $this->resolver->lowestSafeVersion(
            $this->packages('1.2.0', '1.3.0', '2.0.0'),
            $this->affected('<1.2.0'),
            $this->parser->normalize('1.1.0'),
        );

        $this->assertSame('1.2.0', $safe?->getPrettyVersion());
    }

    public function test_it_returns_null_when_every_version_is_affected(): void
    {
        $safe = $this->resolver->lowestSafeVersion(
            $this->packages('1.5.0', '2.0.0'),
            $this->affected('>=1.0.0,<3.0.0'),
            $this->parser->normalize('1.5.0'),
        );

        $this->assertNull($safe);
    }

    public function test_it_never_downgrades_below_the_installed_version(): void
    {
        $safe = $this->resolver->lowestSafeVersion(
            $this->packages('1.5.0', '2.5.0'),
            $this->affected('>=2.0.0,<2.3.0'),
            $this->parser->normalize('2.0.0'),
        );

        $this->assertSame('2.5.0', $safe?->getPrettyVersion());
    }

    public function test_it_skips_versions_that_are_less_stable_than_the_minimum(): void
    {
        $candidates = $this->packages('2.0.0-alpha1', '2.0.0');

        $stable = $this->resolver->lowestSafeVersion(
            $candidates,
            $this->affected('<1.1.5'),
            $this->parser->normalize('1.1.0'),
            'stable',
        );

        $this->assertSame('2.0.0', $stable?->getPrettyVersion());

        $alpha = $this->resolver->lowestSafeVersion(
            $candidates,
            $this->affected('<1.1.5'),
            $this->parser->normalize('1.1.0'),
            'alpha',
        );

        $this->assertSame('2.0.0-alpha1', $alpha?->getPrettyVersion());
    }

    public function test_it_never_picks_a_branch_head_even_at_dev_stability(): void
    {
        $candidates = $this->packages('10.10.0', '10.x-dev');

        $safe = $this->resolver->lowestSafeVersion(
            $candidates,
            $this->affected('<10.48.29'),
            $this->parser->normalize('10.10.0'),
            'dev',
        );

        $this->assertNull($safe);
    }

    public function test_allow_dev_detects_a_branch_head_escape(): void
    {
        $dev = $this->resolver->lowestSafeVersion(
            $this->packages('10.x-dev'),
            $this->affected('<10.48.29'),
            $this->parser->normalize('10.10.0'),
            'dev',
            allowDev: true,
        );

        $this->assertSame('10.x-dev', $dev?->getPrettyVersion());
    }

    /**
     * @return list<PackageInterface>
     */
    private function packages(string ...$versions): array
    {
        return array_map(
            fn (string $version): PackageInterface => new Package('vendor/pkg', $this->parser->normalize($version), $version),
            $versions,
        );
    }

    private function affected(string $constraint): ConstraintInterface
    {
        return $this->parser->parseConstraints($constraint);
    }
}
