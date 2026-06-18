# composer-fix

A Composer plugin that fixes known security vulnerabilities the way `npm audit fix`
does: it audits the installed packages and updates the ones with published
advisories to a version that is no longer affected.

## Installation

```bash
composer require --dev innobrain/composer-fix
```

The plugin registers a single command, `composer fix`.

## Usage

```bash
composer fix
```

This audits the installed packages against the advisories published by your
configured repositories (Packagist by default) and runs a targeted
`composer update` on every affected package — staying within the version
constraints already in your `composer.json`, exactly like a normal update would.

When a package's safe version sits outside its constraint, `composer fix` cannot
reach it without changing `composer.json`, so it reports the package as still
vulnerable and tells you how to proceed.

### Bumping constraints (`--force`)

```bash
composer fix --force
```

`--force` rewrites the affected root constraints to the *lowest* safe version
before updating — the smallest bump that removes the vulnerability. This is the
analogue of `npm audit fix --force` and **can introduce breaking changes**, so
review the result and your `composer.json` diff afterwards.

The constraint chosen is patch-level (e.g. `^5.4.20`) so it also excludes the
lower, vulnerable versions, not just the one that gets installed.

### Dry run

```bash
composer fix --dry-run
```

Shows the plan — which packages would be updated and which constraints `--force`
would bump — without touching `composer.json`, the lock file, or `vendor/`.

### Options

| Option | Description |
| --- | --- |
| `--force` | Bump `composer.json` constraints when the safe version is out of range. |
| `--dry-run` | Preview the plan without changing anything. |
| `--no-dev` | Ignore `require-dev` packages. |
| `-w`, `--with-dependencies` | Also update the dependencies of the affected packages (except root requirements). |
| `-W`, `--with-all-dependencies` | Also update the dependencies of the affected packages, including root requirements. |
| `--ignore-unreachable` | Ignore repositories that are unreachable or return a non-200 status code. |

## Interaction with pool-filtering plugins (e.g. soak-time)

`composer fix` never picks a version another plugin would refuse to install. Both
the update itself and the `--force` version selection go through Composer's normal
pool creation, which fires the `PRE_POOL_CREATE` event. Any plugin that prunes the
pool — such as [soak-time](https://github.com/innobrain/soak-time), which holds
back recently published releases — therefore also prunes the versions `composer fix`
considers.

If the only safe version of a package is still held back by such a filter,
`--force` does **not** bump the constraint to it (that would just fail to resolve).
Instead it reports the version as held back and leaves `composer.json` unchanged, so
you can re-run once the release ages out or override the filter for that package.

## How it works

1. Match the installed packages against advisories via Composer's own advisory
   API (the same data source as `composer audit`).
2. Build the list of affected package names.
3. With `--force`, resolve the lowest published version of each affected root
   requirement that escapes every advisory and rewrite its constraint.
4. Run a targeted `composer update` limited to the affected packages.
5. Re-audit and report anything that is still vulnerable.

## Development

```bash
composer install
composer test
```

## License

MIT
