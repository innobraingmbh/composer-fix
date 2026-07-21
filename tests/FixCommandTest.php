<?php

namespace Innobrain\ComposerFix\Tests;

use Composer\Advisory\SecurityAdvisory;
use Composer\Composer;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\RepositorySet;
use Composer\Semver\VersionParser;
use DateTimeImmutable;
use Innobrain\ComposerFix\FixCommand;
use Innobrain\ComposerFix\SafeVersionResolver;
use Innobrain\ComposerFix\SkippedFix;
use Innobrain\ComposerFix\Vulnerability;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class FixCommandTest extends TestCase
{
    public function test_falls_back_to_the_lock_file_when_vendor_is_not_installed(): void
    {
        $locked = new LockArrayRepository([$this->package('vendor/locked')]);

        $locker = $this->createMock(Locker::class);
        $locker->method('isLocked')->willReturn(true);
        $locker->expects($this->once())->method('getLockedRepository')->with(true)->willReturn($locked);

        $composer = $this->composer(new InstalledArrayRepository([]), $locker);

        $packages = $this->installedPackages($composer, noDev: false);

        $this->assertCount(1, $packages);
        $this->assertSame('vendor/locked', $packages[0]->getName());
    }

    public function test_lock_file_fallback_excludes_dev_packages_with_no_dev(): void
    {
        $locker = $this->createMock(Locker::class);
        $locker->method('isLocked')->willReturn(true);
        $locker->expects($this->once())->method('getLockedRepository')->with(false)->willReturn(new LockArrayRepository([]));

        $composer = $this->composer(new InstalledArrayRepository([]), $locker);

        $this->assertSame([], $this->installedPackages($composer, noDev: true));
    }

    public function test_fails_when_neither_vendor_nor_lock_file_exists(): void
    {
        $locker = $this->createMock(Locker::class);
        $locker->method('isLocked')->willReturn(false);
        $locker->expects($this->never())->method('getLockedRepository');

        $composer = $this->composer(new InstalledArrayRepository([]), $locker);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing to audit');

        $this->installedPackages($composer, noDev: false);
    }

    public function test_prefers_installed_packages_when_vendor_exists(): void
    {
        $locker = $this->createMock(Locker::class);
        $locker->expects($this->never())->method('getLockedRepository');

        $composer = $this->composer(new InstalledArrayRepository([$this->package('vendor/installed')]), $locker);

        $packages = $this->installedPackages($composer, noDev: false);

        $this->assertCount(1, $packages);
        $this->assertSame('vendor/installed', $packages[0]->getName());
    }

    public function test_classify_skip_reports_out_of_range_for_root_requirements(): void
    {
        $skip = $this->classifySkip(
            $this->vulnerability('vendor/pkg', '7.0.0', '<8.0.0'),
            installable: [$this->package('vendor/pkg', '8.0.0')],
            published: [$this->package('vendor/pkg', '8.0.0')],
            transitive: false,
        );

        $this->assertSame(SkippedFix::OUT_OF_RANGE, $skip->reason);
        $this->assertSame('8.0.0', $skip->safeVersion);
    }

    public function test_classify_skip_reports_transitive_when_dependents_block_the_fix(): void
    {
        $skip = $this->classifySkip(
            $this->vulnerability('vendor/pkg', '7.0.0', '<8.0.0'),
            installable: [$this->package('vendor/pkg', '8.0.0')],
            published: [$this->package('vendor/pkg', '8.0.0')],
            transitive: true,
        );

        $this->assertSame(SkippedFix::TRANSITIVE, $skip->reason);
    }

    public function test_classify_skip_reports_held_back_when_only_the_pool_lacks_the_fix(): void
    {
        $skip = $this->classifySkip(
            $this->vulnerability('vendor/pkg', '7.0.0', '<7.2.0'),
            installable: [$this->package('vendor/pkg', '7.1.0')],
            published: [$this->package('vendor/pkg', '7.1.0'), $this->package('vendor/pkg', '7.2.0')],
            transitive: false,
        );

        $this->assertSame(SkippedFix::HELD_BACK, $skip->reason);
        $this->assertSame('7.2.0', $skip->safeVersion);
    }

    public function test_classify_skip_treats_a_dev_only_escape_as_not_fixable(): void
    {
        $skip = $this->classifySkip(
            $this->vulnerability('laravel/framework', '10.10.0', '<10.48.29'),
            installable: [],
            published: [$this->package('laravel/framework', '10.x-dev')],
            transitive: false,
            minimumStability: 'dev',
        );

        $this->assertSame(SkippedFix::DEV_ONLY, $skip->reason);
    }

    public function test_classify_skip_reports_unfixable_when_nothing_escapes(): void
    {
        $skip = $this->classifySkip(
            $this->vulnerability('vendor/pkg', '1.0.0', '>=1.0.0'),
            installable: [$this->package('vendor/pkg', '1.5.0')],
            published: [$this->package('vendor/pkg', '1.5.0')],
            transitive: false,
        );

        $this->assertSame(SkippedFix::UNFIXABLE, $skip->reason);
    }

    public function test_constraint_for_combines_the_root_constraint_with_dependent_constraints(): void
    {
        $root = new RootPackage('root/root', '1.0.0.0', '1.0.0');
        $root->setRequires(['vendor/pkg' => $this->link('root/root', 'vendor/pkg', '^7.0')]);

        $dependent = $this->package('dep/dep', '1.0.0');
        $dependent->setRequires(['vendor/pkg' => $this->link('dep/dep', 'vendor/pkg', '~7.2.0')]);

        $method = new ReflectionMethod(FixCommand::class, 'constraintFor');
        $constraint = $method->invoke(new FixCommand(), $root, [$dependent], 'vendor/pkg');

        $parser = new VersionParser();

        $this->assertTrue($constraint->matches($parser->parseConstraints('7.2.5')));
        $this->assertFalse($constraint->matches($parser->parseConstraints('7.9.0')));
        $this->assertFalse($constraint->matches($parser->parseConstraints('8.0.0')));
    }

    private function classifySkip(
        Vulnerability $vulnerability,
        array $installable,
        array $published,
        bool $transitive,
        string $minimumStability = 'stable',
    ): SkippedFix {
        $repoSet = new RepositorySet($minimumStability);
        $repoSet->addRepository(new ArrayRepository($published));

        $method = new ReflectionMethod(FixCommand::class, 'classifySkip');

        return $method->invoke(
            new FixCommand(),
            new SafeVersionResolver(),
            $repoSet,
            $vulnerability,
            $installable,
            $transitive,
            $minimumStability,
        );
    }

    private function vulnerability(string $name, string $installed, string $affected): Vulnerability
    {
        $parser = new VersionParser();

        $advisory = new SecurityAdvisory(
            $name,
            'ADVISORY-1',
            $parser->parseConstraints($affected),
            'Test advisory',
            [['name' => 'test', 'remoteId' => '1']],
            new DateTimeImmutable(),
        );

        return new Vulnerability($this->package($name, $installed), [$advisory]);
    }

    private function link(string $source, string $target, string $constraint): Link
    {
        return new Link($source, $target, (new VersionParser())->parseConstraints($constraint), Link::TYPE_REQUIRE, $constraint);
    }

    private function composer(InstalledArrayRepository $localRepo, Locker $locker): Composer
    {
        $repositoryManager = $this->createMock(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn($localRepo);

        $composer = new Composer();
        $composer->setRepositoryManager($repositoryManager);
        $composer->setLocker($locker);

        return $composer;
    }

    private function installedPackages(Composer $composer, bool $noDev): array
    {
        $method = new ReflectionMethod(FixCommand::class, 'installedPackages');

        return $method->invoke(new FixCommand(), $composer, $noDev);
    }

    private function package(string $name, string $version = '1.0.0'): Package
    {
        return new Package($name, (new VersionParser())->normalize($version), $version);
    }
}
