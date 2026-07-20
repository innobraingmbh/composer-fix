<?php

namespace Innobrain\ComposerFix\Tests;

use Composer\Composer;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\RepositoryManager;
use Innobrain\ComposerFix\FixCommand;
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

    private function package(string $name): Package
    {
        return new Package($name, '1.0.0.0', '1.0.0');
    }
}
