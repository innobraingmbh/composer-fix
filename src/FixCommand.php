<?php

namespace Innobrain\ComposerFix;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\DependencyResolver\Request;
use Composer\Factory;
use Composer\Installer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RepositoryUtils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FixCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('fix')
            ->setDescription('Updates packages flagged by composer audit to non-vulnerable versions')
            ->setDefinition([
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Ignore require-dev packages.'),
                new InputOption('force', null, InputOption::VALUE_NONE, 'Bump composer.json constraints when the safe version is out of range (may include breaking changes).'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without touching composer.json, the lock file, or vendor.'),
                new InputOption('with-dependencies', 'w', InputOption::VALUE_NONE, 'Also update the dependencies of the affected packages, except root requirements.'),
                new InputOption('with-all-dependencies', 'W', InputOption::VALUE_NONE, 'Also update the dependencies of the affected packages, including root requirements.'),
                new InputOption('ignore-unreachable', null, InputOption::VALUE_NONE, 'Ignore repositories that are unreachable or return a non-200 status code.'),
            ])
            ->setHelp(
                <<<'EOT'
Audits installed packages and updates those with security advisories to a version
that is no longer affected.

By default it updates only within your existing composer.json constraints, like a
normal <info>composer update</info>; packages whose safe version is out of range are
reported but left untouched.

<info>--force</info> first bumps the affected root constraints to the lowest safe version,
like <info>npm audit fix --force</info>, which may pull in breaking changes. Use
<info>--dry-run</info> to preview the plan.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composer = $this->requireComposer();

        $noDev = (bool) $input->getOption('no-dev');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $ignoreUnreachable = (bool) $input->getOption('ignore-unreachable');

        $scan = $this->scan($composer, $noDev, $ignoreUnreachable);

        foreach ($scan->unreachableRepos as $message) {
            $io->writeError('<warning>[composer-fix] '.$message.'</warning>');
        }

        if ($scan->isClean()) {
            $io->write('<info>[composer-fix] No security vulnerability advisories found for installed packages.</info>');

            return 0;
        }

        $this->reportVulnerabilities($io, $scan);

        $composerFile = Factory::getComposerFile();
        $lockFile = Factory::getLockFile($composerFile);
        $jsonBackup = null;
        $lockBackup = null;

        if ($force) {
            $plan = $this->planBumps($composer, $scan);
            $this->reportPlan($io, $plan, $dryRun);

            if (! $dryRun && $plan->hasBumps()) {
                $jsonBackup = file_get_contents($composerFile);
                $lockBackup = file_exists($lockFile) ? file_get_contents($lockFile) : null;

                $this->applyBumps($composerFile, $plan);

                $this->resetComposer();
                $composer = $this->requireComposer();
            }
        }

        $io->write($dryRun
            ? '<info>[composer-fix] Dry run — simulating an in-range update of the affected packages.</info>'
            : '<info>[composer-fix] Updating affected packages...</info>');

        $status = $this->runUpdate($input, $io, $composer, $scan->names(), $dryRun);

        if ($status !== 0) {
            if ($jsonBackup !== null) {
                file_put_contents($composerFile, $jsonBackup);

                if ($lockBackup !== null) {
                    file_put_contents($lockFile, $lockBackup);
                }

                $io->writeError('<error>[composer-fix] Update failed — restored composer.json'.($lockBackup !== null ? ' and composer.lock' : '').'.</error>');
            }

            return $status;
        }

        if (! $dryRun) {
            $this->reportResidual($io, $noDev, $ignoreUnreachable, $force);
        }

        return 0;
    }

    private function scan(Composer $composer, bool $noDev, bool $ignoreUnreachable): ScanResult
    {
        $installed = $this->installedPackages($composer, $noDev);

        if ($installed === []) {
            return new ScanResult([], []);
        }

        return (new AdvisoryScanner())->scan($this->createRepositorySet($composer), $installed, $ignoreUnreachable);
    }

    /**
     * @return list<PackageInterface>
     */
    private function installedPackages(Composer $composer, bool $noDev): array
    {
        $installedRepo = new InstalledRepository([$composer->getRepositoryManager()->getLocalRepository()]);

        if ($noDev) {
            return array_values(RepositoryUtils::filterRequiredPackages($installedRepo->getPackages(), $composer->getPackage()));
        }

        return array_values($installedRepo->getPackages());
    }

    private function createRepositorySet(Composer $composer): RepositorySet
    {
        $rootPackage = $composer->getPackage();
        $repoSet = new RepositorySet($rootPackage->getMinimumStability(), $rootPackage->getStabilityFlags());

        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $repoSet->addRepository($repository);
        }

        return $repoSet;
    }

    private function planBumps(Composer $composer, ScanResult $scan): BumpPlan
    {
        $rootPackage = $composer->getPackage();
        $requires = $rootPackage->getRequires();
        $devRequires = $rootPackage->getDevRequires();
        $minimumStability = $rootPackage->getMinimumStability() ?: 'stable';

        $repoSet = $this->createRepositorySet($composer);
        $resolver = new SafeVersionResolver();
        $versionSelector = new VersionSelector($repoSet);

        $rootRequired = [];
        $transitive = [];

        foreach ($scan->vulnerabilities as $vulnerability) {
            $name = $vulnerability->name();

            if (isset($requires[$name])) {
                $rootRequired[$name] = 'require';
            } elseif (isset($devRequires[$name])) {
                $rootRequired[$name] = 'require-dev';
            } else {
                $transitive[] = $name;
            }
        }

        // Two candidate sets: every published version (findPackages) and only
        // those that survive a real pool build (installableVersions). A version
        // in the first but not the second was held back by a pool filter such as
        // soak-time, so we report it instead of bumping to a version that won't
        // install.
        $published = [];
        foreach (array_keys($rootRequired) as $name) {
            $published[$name] = $repoSet->findPackages($name);
        }

        $installable = $this->installableVersions($repoSet, $composer, array_keys($rootRequired));

        $bumps = [];
        $heldBack = [];
        $unfixable = [];

        foreach ($scan->vulnerabilities as $vulnerability) {
            $name = $vulnerability->name();

            if (! isset($rootRequired[$name])) {
                continue;
            }

            $affected = $vulnerability->affectedConstraint();
            $installedVersion = $vulnerability->installedVersion();

            $safe = $resolver->lowestSafeVersion($installable[$name] ?? [], $affected, $installedVersion, $minimumStability);

            if ($safe !== null) {
                $requireKey = $rootRequired[$name];
                $current = ($requireKey === 'require' ? $requires : $devRequires)[$name];

                $bumps[] = new ConstraintBump(
                    $name,
                    $requireKey,
                    $current->getPrettyConstraint(),
                    $this->recommendedConstraint($safe, $versionSelector),
                    $safe->getPrettyVersion(),
                );

                continue;
            }

            $safeIfNotFiltered = $resolver->lowestSafeVersion($published[$name] ?? [], $affected, $installedVersion, $minimumStability);

            if ($safeIfNotFiltered !== null) {
                $heldBack[] = new HeldBackFix($name, $safeIfNotFiltered->getPrettyVersion());
            } else {
                $unfixable[] = $name;
            }
        }

        return new BumpPlan($bumps, $transitive, $heldBack, $unfixable);
    }

    /**
     * Versions that survive a real pool build — built through the live event
     * dispatcher so pool-filtering plugins (e.g. soak-time) prune them just as
     * they will during the update.
     *
     * @param  list<string>  $names
     * @return array<string, list<PackageInterface>>
     */
    private function installableVersions(RepositorySet $repoSet, Composer $composer, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $request = new Request();

        foreach ($names as $name) {
            $request->requireName($name);
        }

        $request->restrictPackages(array_map('strtolower', $names));

        $pool = $repoSet->createPool($request, $this->getIO(), $composer->getEventDispatcher());

        $byName = [];

        foreach ($pool->getPackages() as $package) {
            $byName[$package->getName()][] = $package;
        }

        return $byName;
    }

    /**
     * Patch-level caret (e.g. ^5.4.20) so the constraint excludes the vulnerable
     * lower versions, not just the install. Falls back to Composer's own
     * recommendation for branch/dev versions.
     */
    private function recommendedConstraint(PackageInterface $safe, VersionSelector $versionSelector): string
    {
        $pretty = ltrim($safe->getPrettyVersion(), 'vV');

        if (preg_match('/^\d+\.\d+/', $pretty) === 1) {
            return '^'.$pretty;
        }

        return $versionSelector->findRecommendedRequireVersion($safe);
    }

    private function applyBumps(string $composerFile, BumpPlan $plan): void
    {
        $manipulator = new JsonManipulator((string) file_get_contents($composerFile));

        foreach ($plan->bumps as $bump) {
            if (! $manipulator->addLink($bump->requireKey, $bump->name, $bump->to, true)) {
                $this->applyBumpsViaJsonFile($composerFile, $plan);

                return;
            }
        }

        file_put_contents($composerFile, $manipulator->getContents());
    }

    private function applyBumpsViaJsonFile(string $composerFile, BumpPlan $plan): void
    {
        $json = new JsonFile($composerFile);
        $definition = $json->read();

        foreach ($plan->bumps as $bump) {
            $definition[$bump->requireKey][$bump->name] = $bump->to;
        }

        $json->write($definition);
    }

    private function runUpdate(InputInterface $input, IOInterface $io, Composer $composer, array $allowList, bool $dryRun): int
    {
        $install = Installer::create($io, $composer);

        $install
            ->setDryRun($dryRun)
            ->setUpdate(true)
            ->setUpdateAllowList($allowList)
            ->setUpdateAllowTransitiveDependencies($this->transitiveFlag($input))
            ->setDevMode(! (bool) $input->getOption('no-dev'))
            ->setOptimizeAutoloader((bool) $composer->getConfig()->get('optimize-autoloader'))
            ->setPreferStable($composer->getPackage()->getPreferStable());

        return $install->run();
    }

    private function transitiveFlag(InputInterface $input): int
    {
        if ($input->getOption('with-all-dependencies')) {
            return Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
        }

        if ($input->getOption('with-dependencies')) {
            return Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
        }

        return Request::UPDATE_ONLY_LISTED;
    }

    private function reportVulnerabilities(IOInterface $io, ScanResult $scan): void
    {
        $io->write(sprintf(
            '<warning>[composer-fix] Found %d advisory(ies) affecting %d installed package(s):</warning>',
            $scan->advisoryCount(),
            count($scan->vulnerabilities),
        ));

        foreach ($scan->vulnerabilities as $vulnerability) {
            $severity = $vulnerability->highestSeverity();

            $io->write(sprintf(
                '  <info>%s</info> (%s)%s',
                $vulnerability->name(),
                $vulnerability->prettyInstalledVersion(),
                $severity !== null ? ' — '.$severity : '',
            ));

            foreach ($vulnerability->advisories as $advisory) {
                $io->write(sprintf(
                    '    %s%s',
                    $advisory->title,
                    $advisory->cve !== null && $advisory->cve !== '' ? ' ('.$advisory->cve.')' : '',
                ));

                if ($io->isVerbose() && $advisory->link !== null && $advisory->link !== '') {
                    $io->write('      '.$advisory->link);
                }
            }
        }
    }

    private function reportPlan(IOInterface $io, BumpPlan $plan, bool $dryRun): void
    {
        foreach ($plan->bumps as $bump) {
            $io->write(sprintf(
                '<info>[composer-fix] %s %s: %s -> %s (safe: %s)%s</info>',
                $dryRun ? 'Would bump' : 'Bumping',
                $bump->name,
                $bump->from,
                $bump->to,
                $bump->safeVersion,
                $bump->requireKey === 'require-dev' ? ' [require-dev]' : '',
            ));
        }

        foreach ($plan->transitive as $name) {
            $io->writeError(sprintf(
                '<warning>[composer-fix] %s is a transitive dependency — no root constraint to bump. '
                .'Add it to "require" or update its dependent.</warning>',
                $name,
            ));
        }

        foreach ($plan->heldBack as $held) {
            $io->writeError(sprintf(
                '<warning>[composer-fix] %s %s is safe but held back by a pool filter (e.g. soak-time). '
                .'Leaving the constraint unchanged — re-run once it ages out, or skip the filter (SOAK_TIME_SKIP=%s).</warning>',
                $held->name,
                $held->safeVersion,
                $held->name,
            ));
        }

        foreach ($plan->unfixable as $name) {
            $io->writeError(sprintf(
                '<error>[composer-fix] No published version of %s escapes the advisory — cannot fix automatically.</error>',
                $name,
            ));
        }
    }

    private function reportResidual(IOInterface $io, bool $noDev, bool $ignoreUnreachable, bool $force): void
    {
        $this->resetComposer();

        $scan = $this->scan($this->requireComposer(), $noDev, $ignoreUnreachable);

        if ($scan->isClean()) {
            $io->write('<info>[composer-fix] All advisories resolved.</info>');

            return;
        }

        $io->writeError(sprintf(
            '<warning>[composer-fix] %d package(s) still vulnerable after the update:</warning>',
            count($scan->vulnerabilities),
        ));

        foreach ($scan->vulnerabilities as $vulnerability) {
            $io->writeError('  <comment>'.$vulnerability->name().'</comment> ('.$vulnerability->prettyInstalledVersion().')');
        }

        $io->writeError($force
            ? '<warning>[composer-fix] Some safe versions are held back by a pool filter or need manual changes — see above.</warning>'
            : '<warning>[composer-fix] Safe versions are out of range or held back. '
                .'Try `composer fix --force` (bump constraints) or `composer fix -W` (also update dependencies).</warning>');
    }
}
