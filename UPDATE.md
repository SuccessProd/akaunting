# Updating Akaunting — Manual Guide

Procedure for updating the Akaunting core in this project via git.
Tested on 2026-08-05 for the 3.1.21 → 3.2.1 update.

**Project:** `po_finance` — working directory
`/Users/ronny/Downloads/Workspaces/Finance/po_finance`

| Setting | Value |
|---|---|
| Branch | `main-po-finance` |
| Database | `po_finance` (user `po_finance`) |
| URL | `https://finance.pujasonline.xyz` |

---

## Before you start

This repo is a **fork** (`SuccessProd/akaunting`) with two remotes:

| Remote | Points to |
|---|---|
| `origin` | `git@github.com:SuccessProd/akaunting.git` (your fork) |
| `upstream` | `https://github.com/akaunting/akaunting.git` (official) |

Updating means pulling an official tag from `upstream` and merging it into your
`main-po-finance` branch.

**Do not use the UI update button.** It overwrites core files directly on disk, pulls the install
out of sync with git, and mixes official changes with your own. The git route is reversible and
clean.

---

## Step 0 — Pre-flight checks

```bash
cd /Users/ronny/Downloads/Workspaces/Finance/po_finance

# Installed version
grep -E "'(major|minor|patch)'" config/version.php

# Working tree must be clean (no output = OK)
git status --short

# Current branch — should be main-po-finance
git branch --show-current
```

If `git status` shows uncommitted changes, deal with them first (`git stash` or commit).

### Does the fork have customizations?

This tells you whether the merge will be clean or conflicted. Replace `3.1.21` with your current
version:

```bash
git diff --stat 3.1.21 HEAD       # no output = fork identical to upstream
git rev-list --count 3.1.21..HEAD # 0 = no commits of your own
```

No output and `0` → the merge will be a conflict-free fast-forward.
If there are differences, expect conflicts and review each one carefully.

---

## Step 1 — Find the latest version

```bash
git fetch upstream --tags
git tag --list | tail -5
```

You can also ask the official API what it offers for your specific environment:

```bash
php artisan cache:clear
php artisan tinker --execute="print_r(App\Utilities\Versions::getUpdates());"
```

An array containing `[core] => ... [latest] => X.Y.Z` confirms an update is available.
An empty array means you're already current **or** the API didn't respond (it fails silently — see
notes at the end).

---

## Step 2 — Backups

Don't skip this: the migration step is the only one git cannot undo.

```bash
# Database (reads credentials from .env, without exposing the password)
set -a && source <(grep -E "^DB_(USERNAME|PASSWORD|DATABASE|HOST)" .env) && set +a
/usr/local/mysql/bin/mysqldump -h"$DB_HOST" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
  "$DB_DATABASE" > ~/backup-po_finance-$(date +%Y%m%d).sql

# Uploads and logs
tar -czf ~/backup-storage-po_finance-$(date +%Y%m%d).tar.gz storage/
```

### Verify the backup is usable

A dump that "finishes fine" can still be incomplete. Compare tables in the file against the live DB:

```bash
grep -c "^CREATE TABLE" ~/backup-po_finance-$(date +%Y%m%d).sql
/usr/local/mysql/bin/mysql -h"$DB_HOST" -u"$DB_USERNAME" -p"$DB_PASSWORD" -N \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE';"
```

Both numbers must match.

> **Note:** `mysqldump` may warn `Access denied ... PROCESS privilege ... tablespaces`. Harmless —
> it only affects InnoDB metadata, not the schema or the data.

---

## Step 3 — Check the new version's compatibility

Before merging, look at what you're getting into. Replace `3.2.1` with your target version:

```bash
# Minimum PHP requirement
git show 3.2.1:composer.json | grep -A3 '"require"'

# New migrations
git diff --name-status 3.1.21 3.2.1 -- database/migrations/

# Does it touch installed modules?
git diff --name-status 3.1.21 3.2.1 -- modules/
```

Confirm your PHP (`php -v`) satisfies the requirement.

---

## Step 4 — Merge into a working branch

Working on a separate branch lets you throw everything away if it goes wrong.

```bash
git checkout -b update-3.2.1
git merge 3.2.1
```

If there are conflicts, resolve them and finish with `git add` + `git commit`.
To abort and back out: `git merge --abort`.

Verify:

```bash
grep -E "'(minor|patch)'" config/version.php   # should show the new version
git status --short                             # no output = clean
```

---

## Step 5 — Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` is mandatory in this environment: `brianium/paratest` doesn't support PHP 8.4 and the
command fails without that flag.

### The output looks alarming but is usually fine

This command spits out hundreds of lines, including long stack traces. **They are not install
failures.** They come from post-install scripts (`ide-helper:generate`, `ide-helper:models`), which
run after the installation has already completed.

What matters is at the top:

```
Package operations: N installs, N updates, N removals
...
Generating optimized autoload files
INFO  Discovering packages.   → all DONE
```

If you see that, it worked. Confirm the exit code:

```bash
composer install --no-dev --optimize-autoloader >/dev/null 2>&1; echo $?   # 0 = OK
```

Expected noise you can ignore:

| Message | Why it shows up |
|---|---|
| `Class "Faker\Factory" not found` / `Could not analyze class App\Models\...` | `fakerphp/faker` is a dev package and `--no-dev` skips it, so `ide-helper:models` can't analyze the factories. Affects IDE autocompletion only, not the app. |
| `Class "Laravel\Passport\Passport" not found` | Same reason. |
| `Deprecation Notice: money(): Implicitly marking parameter $currency as nullable` | `akaunting/laravel-money` on PHP 8.4. Known noise. |
| `Package X is abandoned` (`genealabs/laravel-model-caching`, `hoa/*`, `laravelcollective/html`…) | Upstream Akaunting tech debt, not yours. No action. |
| `DatabaseSeeder ... does not comply with psr-4` | Pre-existing in Akaunting. Harmless. |

**Real failure signals** (these mean stop): `Your lock file does not contain a compatible set of
packages`, `Problem 1 ... requires php ...`, or an exit code other than `0`.

---

## Step 6 — Migrations

**Point of no return.** From here on, backing out requires restoring the backup.

```bash
php artisan migrate --force
```

---

## Step 7 — Clear caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

---

## Step 8 — Verify

```bash
# Version the app reports
php artisan tinker --execute="echo version('short');"

# No migrations should be pending
php artisan migrate:status | grep -i pending

# The app responds
curl -sk -o /dev/null -w "%{http_code}\n" https://finance.pujasonline.xyz/auth/login
```

Expected: the new version, nothing pending, and `200`.

**Then test it by hand in the browser**: login, invoice list, customer list. The automated checks
don't cover this.

---

## Step 9 — Consolidate

Only once you've confirmed it works:

```bash
git checkout main-po-finance
git merge update-3.2.1
git push origin main-po-finance
git branch -d update-3.2.1
```

---

## If something goes wrong

| When | How to back out |
|---|---|
| Before step 6 (migrations) | `git checkout main-po-finance` and delete the branch |
| After step 6 | Restore the DB backup (below), then return to the previous branch |
| After the push | `git revert` the merge, or `git reset --hard` + force push (coordinate if others are working on it) |

Restoring the database:

```bash
set -a && source <(grep -E "^DB_(USERNAME|PASSWORD|DATABASE|HOST)" .env) && set +a
/usr/local/mysql/bin/mysql -h"$DB_HOST" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
  "$DB_DATABASE" < ~/backup-po_finance-YYYYMMDD.sql
```

---

## Notes and known traps

**The UI update button is cached for 6 hours.**
`App\Utilities\Versions::getUpdates()` caches the result. If the button doesn't appear even though a
new version exists, this is almost always why: `php artisan cache:clear`.

**The version check fails silently.**
In [`app/Utilities/Versions.php`](app/Utilities/Versions.php), `getLatestVersion()` returns your
installed version as if it were the latest whenever the akaunting.com API doesn't respond. The UI
will claim you're up to date with no error shown. With `APP_DEBUG=false` you see nothing at all. If
in doubt, query the API directly with the `tinker` command from step 1.

**PHP 8.4 and the test suite.**
`brianium/paratest v7.3.2` only supports up to PHP 8.3. The app runs fine, but you can't run the
test suite locally without updating that package or dropping to PHP 8.3.

**`akaunting/laravel-money` deprecation warnings.**
They appear on PHP 8.4 when running artisan commands. Noise; nothing is blocked.

**What happens on the hosting.**
There you won't have `upstream` configured unless you clone the full repo. Pick one model and stick
to it: either clone from `origin` and follow this same guide, or use the UI button and accept that
the install drifts out of sync with git. Mixing them makes future updates harder.

---

## Sister project

`finance-sp` follows this same procedure with its own parameters (branch `main-finance-sp`, DB
`sp_finance`, URL `https://finance.successprod.xyz`). See `UPDATE.md` in that project.
