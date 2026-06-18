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
     * Lowest known version that escapes every advisory — lowest, not newest, so
     * --force makes the smallest constraint bump that removes the vulnerability.
     * A candidate qualifies when it meets the minimum stability, is outside the
     * affected range, and is not a downgrade. Null when none exists.
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
