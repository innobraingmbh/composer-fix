<?php

namespace Innobrain\ComposerFix;

use Composer\Package\PackageInterface;
use Composer\Repository\RepositorySet;

final class AdvisoryScanner
{
    /**
     * Match installed packages against published advisories. Composer only
     * returns advisories covering the installed version, so every result is
     * actionable.
     *
     * @param  list<PackageInterface>  $installed
     */
    public function scan(RepositorySet $repoSet, array $installed, bool $ignoreUnreachable = false): ScanResult
    {
        $result = $repoSet->getMatchingSecurityAdvisories($installed, false, $ignoreUnreachable);

        $packagesByName = [];

        foreach ($installed as $package) {
            $packagesByName[$package->getName()] = $package;
        }

        $vulnerabilities = [];

        foreach ($result['advisories'] as $name => $advisories) {
            if ($advisories === [] || ! isset($packagesByName[$name])) {
                continue;
            }

            $vulnerabilities[] = new Vulnerability($packagesByName[$name], array_values($advisories));
        }

        return new ScanResult($vulnerabilities, $result['unreachableRepos']);
    }
}
