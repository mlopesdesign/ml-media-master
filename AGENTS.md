# ML Media Master — Permanent Agent Rules

## Environment

This project does NOT use Champ.

Never search for Champ.
Never inspect Champ configuration.
Never ask about Champ.
Never attempt to run Champ.
Never use Champ as part of build, validation, packaging, tests, or deployment.

This project uses Docker for safe build/validation/packaging when execution is required.

## Execution Rules

* Use Docker-based execution only when commands are needed.
* Do not use local PowerShell, CMD, bash/sh, wp-cli, or host-level package tools.
* Do not assume Champ exists.
* Do not waste time or tokens looking for Champ.
* If a build/test/package step is needed, use Docker or the existing repository workflow only.

## WordPress Plugin Rules

* Work only on the existing plugin root.
* Keep slug unchanged: `ml-media-master`
* Keep root folder unchanged: `ml-media-master/`
* Keep main file unchanged: `ml-media-master/ml-media-master.php`
* Final ZIP must install as UPDATE over the existing plugin.
* Never create a parallel plugin.
* Never rename the root folder.
* Never package from a temporary renamed folder.

## Versioning Rules

Synchronize version in:

* plugin header (`Version:`)
* `MLMM_VERSION` constant
* `readme.txt` Stable tag
* changelog (top entry)

## Scope Control

Only modify files required by the task.
Do not inspect unrelated systems unless needed.
Do not refactor unrelated code.
Do not touch the WebP conversion engine, ML Gallery Pro integration, NGG repair, or admin UI unless the task explicitly requires it.

---

## Release Workflow

Every change that ships a new version follows the same flow. **No exceptions.**

### 1. Backup first (local)

```powershell
cd scripts
.\backup.ps1 -Reason "pre-release-vX.Y.Z"
```

### 2. Update version in **all** sync points

| Location                                       | Field                       |
|------------------------------------------------|-----------------------------|
| `ml-media-master/ml-media-master.php`         | Plugin header `Version:`    |
| `ml-media-master/ml-media-master.php`         | `define('MLMM_VERSION', ...)` |
| `ml-media-master/readme.txt`                  | `Stable tag:`               |
| Git tag                                        | `vX.Y.Z`                    |
| Release asset (ZIP)                            | `ml-media-master-vX.Y.Z.zip` |

Validate:

```powershell
.\sync-version.ps1
```

### 3. Build the ZIP

```powershell
.\package.ps1 -Version X.Y.Z
```

ZIP MUST have `ml-media-master/` as the **root** folder.

### 4. Commit, tag, push

```powershell
git add -A
git -c user.name=mlopesdesign -c user.email=mlopesdesign@gmail.com commit -m "release: vX.Y.Z"
git -c user.name=mlopesdesign -c user.email=mlopesdesign@gmail.com tag -a vX.Y.Z -m "ML Media Master vX.Y.Z"
git push origin main --follow-tags
```

### 5. CI auto-publishes the Release

`.github/workflows/release.yml` listens for tag pushes matching `v*`, runs `php -l` + `node --check`, re-runs `package.sh`, computes SHA-256 and creates a GitHub Release with the ZIP as the only asset.

### Auto-update on customer sites

`includes/class-mlmm-updater.php` (do not touch without explicit request) calls
`https://api.github.com/repos/mlopesdesign/ml-media-master/releases/latest`
and exposes the asset as a native WordPress update.

---

## What this agent should NOT do

- Don't rename `ml-media-master/`, the main file, or the slug.
- Don't touch the WebP conversion engine, ML Gallery Pro integration, NGG repair, license manager, or updater unless the task explicitly says so.
- Don't commit `*.zip`, `backups/`, `dist/`, or scratch folders.
- Don't rebase or force-push `main` (unless rebuilding history explicitly approved).