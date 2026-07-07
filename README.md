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
within your existing `composer.json` constraints. A package whose safe version
is out of range is reported as still vulnerable rather than changed.

Exits `0` when every advisory is resolved and `1` when packages remain
vulnerable after the update, so CI pipelines fail on unfixed advisories.

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

### Options

| Option | Description |
| --- | --- |
| `--force` | Bump constraints when the safe version is out of range. |
| `--dry-run` | Preview the plan without changing anything. |
| `--no-dev` | Ignore `require-dev` packages in the audit. Never installs or removes dev packages either way — vendor keeps its current dev/no-dev state. |
| `-w`, `--with-dependencies` | Also update dependencies of affected packages (except root requirements). |
| `-W`, `--with-all-dependencies` | Also update dependencies of affected packages, including root requirements. |
| `--ignore-unreachable` | Ignore repositories that are unreachable or return a non-200. |

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
2. Build the list of affected packages.
3. With `--force`, resolve the lowest safe version of each affected root
   requirement and rewrite its constraint.
4. Run a targeted `composer update` on the affected packages.
5. Re-audit and report anything still vulnerable.

## Development

```bash
composer install
composer test
```

## License

MIT
