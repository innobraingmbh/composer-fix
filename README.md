[![Latest Version on Packagist](https://img.shields.io/packagist/v/innobrain/composer-fix.svg?style=flat-square)](https://packagist.org/packages/innobrain/composer-fix)
[![Total Downloads](https://img.shields.io/packagist/dt/innobrain/composer-fix.svg?style=flat-square)](https://packagist.org/packages/innobrain/composer-fix)


# composer-fix

A Composer plugin that fixes known vulnerabilities like `npm audit fix`: it
audits installed packages and updates the ones with published advisories to a
version that is no longer affected.

## Installation

Install it globally so `composer fix` is available in every project:

```bash
composer global require innobrain/composer-fix
```

Composer will ask to allow the plugin the first time — confirm, or add it to
`allow-plugins` in your global `composer.json`. Registers a single command,
`composer fix`.

## Usage

```bash
composer fix
```

Audits installed packages against your repositories' advisories (Packagist by
default) and runs a targeted `composer update` on the affected ones, staying
within your existing `composer.json` constraints.

Packages without a reachable fix are skipped — kept off the update list — so
one unfixable package (an EOL major, a fix only published in the next major)
cannot fail the whole solve under Composer's advisory policy and throw away
the fixes that are reachable. Each skip is reported with its reason:

- `out-of-range` — the safe version is outside the root constraint; `--force` can bump it.
- `transitive` — the constraints of installed dependents exclude the safe version;
  update the dependents or require the package directly.
- `held-back` — a pool filter (e.g. soak-time) hides the safe version.
- `dev-only` — only a branch head escapes the advisory. A dev build is never
  treated as a fix: on an EOL major with `minimum-stability: dev` the solver
  would otherwise land on e.g. `10.x-dev`, which only hides the advisory.
- `unfixable` — no published version escapes the advisory.

After updating, any package whose `php` requirement exceeds the project's php
floor (`config.platform.php`, or the lower bound of `require.php`) is reported
as a warning — the lock may not install on the oldest php the project claims
to support. This never fails the run.

If `vendor/` is not installed (e.g. a fresh clone), the audit falls back to
`composer.lock`, like `composer audit --locked`. With neither `vendor/` nor a
lock file there is nothing to audit, so the command errors with exit `1` —
`--no-fail` does not cover this case.

Exits `0` when every advisory is resolved and `1` when packages remain
vulnerable after the update, so CI pipelines fail on unfixed advisories. Pass
`--no-fail` to exit `0` in that case too — useful when a wrapper treats any
non-zero exit as a failed run and would discard the fixes that did land.

Requires Composer 2.9 or newer.

### Bumping constraints (`--force`)

```bash
composer fix --force
```

Rewrites affected root constraints to the *lowest* safe version before updating
— the smallest bump that removes the vulnerability, like `npm audit fix --force`.
**Can introduce breaking changes**, so review the `composer.json` diff. The
constraint is patch-level (e.g. `^5.4.20`) so it also excludes the vulnerable
lower versions.

### Dry run

```bash
composer fix --dry-run
```

Shows the plan without touching `composer.json`, the lock file, or `vendor/`.

### Machine-readable output (`--json`)

```bash
composer fix --json
```

Moves all human-readable messages to stderr and prints a JSON document as the
**last line of stdout** (update scripts such as `artisan package:discover` may
write to stdout before it, so parse the last line):

```json
{
  "advisories": [{"package": "...", "installed": "...", "severity": "high", "advisories": [{"title": "...", "cve": "...", "link": "..."}]}],
  "planned": ["packages/passed-to-the-updater"],
  "bumped": [{"package": "...", "requireKey": "require", "from": "^1.0", "to": "^2.1.1", "safeVersion": "2.1.1"}],
  "skipped": [{"package": "...", "reason": "out-of-range", "safeVersion": "2.1.1"}],
  "updated": [{"package": "...", "from": "1.5.0", "to": "1.8.2"}],
  "stillVulnerable": [{"package": "...", "installed": "1.8.0"}],
  "platformWarnings": [{"package": "...", "requiresPhp": ">=8.2", "platformPhp": "^8.1 (require.php)"}]
}
```

`stillVulnerable` is `null` when the post-update state is unknown (dry run or a
failed update). The exit code keeps its usual meaning.

### Options

| Option | Description |
| --- | --- |
| `--force` | Bump constraints when the safe version is out of range. |
| `--dry-run` | Preview the plan without changing anything. |
| `--no-dev` | Ignore `require-dev` packages in the audit. Never installs or removes dev packages either way — vendor keeps its current dev/no-dev state. |
| `-w`, `--with-dependencies` | Also update dependencies of affected packages (except root requirements). |
| `-W`, `--with-all-dependencies` | Also update dependencies of affected packages, including root requirements. |
| `--ignore-unreachable` | Ignore repositories that are unreachable or return a non-200. |
| `--no-fail` | Exit `0` even when packages remain vulnerable after the update. |
| `--json` | Print a machine-readable result as the last line of stdout; messages move to stderr. |

## Pool-filtering plugins (e.g. soak-time)

`composer fix` never picks a version another plugin would refuse to install.
Both the update and `--force` selection go through Composer's normal pool
creation (`PRE_POOL_CREATE`), so a plugin that prunes the pool — such as
[soak-time](https://github.com/innobrain/soak-time) — also prunes what
`composer fix` considers. If the only safe version is held back, `--force`
reports it and leaves `composer.json` unchanged instead of bumping to a version
that won't resolve.

## How it works

1. Match installed packages against advisories via Composer's advisory API.
2. Split the affected packages into fixable and skipped: a package is fixable
   when a safe, non-dev version survives the pool build and fits the root
   constraint plus the constraints of installed dependents (which stay locked
   during a targeted update).
3. With `--force`, resolve the lowest safe version of each affected root
   requirement and rewrite its constraint.
4. Run a targeted `composer update` on the fixable packages only.
5. Re-audit, warn about packages requiring php above the project floor, and
   report anything still vulnerable.

## Development

```bash
composer install
composer test
```

## License

MIT
