<?php

namespace Innobrain\ComposerFix;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Semver\Comparator;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

final class SafeVersionResolver
{
    /**
     * Pick the lowest known version of a package that escapes every advisory.
     *
     * The lowest qualifying version is preferred over the newest so that --force
     * makes the smallest constraint bump that still removes the vulnerability,
     * keeping the blast radius of breaking changes as small as possible. A
     * candidate qualifies when it is stable enough for the root minimum
     * stability, is not covered by the affected range, and is not a downgrade.
     *
     * Returns null when no such version exists — the caller reports the package
     * as unfixable.
     *
     * @param  iterable<PackageInterface>  $candidates  every known version of the package
     */
    public function lowestSafeVersion(
        iterable $candidates,
        ConstraintInterface $affected,
        string $installedVersion,
        string $minimumStability = 'stable',
    ): ?PackageInterface {
        $maxStability = BasePackage::$stabilities[$minimumStability] ?? BasePackage::$stabilities['stable'];

        $safest = null;

        foreach ($candidates as $candidate) {
            if ($candidate instanceof AliasPackage) {
                continue;
            }

            if ((BasePackage::$stabilities[$candidate->getStability()] ?? 0) > $maxStability) {
                continue;
            }

            if ($affected->matches(new Constraint('==', $candidate->getVersion()))) {
                continue;
            }

            if (Comparator::lessThan($candidate->getVersion(), $installedVersion)) {
                continue;
            }

            if ($safest === null || Comparator::lessThan($candidate->getVersion(), $safest->getVersion())) {
                $safest = $candidate;
            }
        }

        return $safest;
    }
}
