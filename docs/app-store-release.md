# Nextcloud App Store — Release & Signing Guide

## Prerequisites

- `openssl` available on your machine
- GitHub repo secrets configured (see step 5)
- A Nextcloud account on apps.nextcloud.com

---

## One-time setup

### 1. Generate a key pair

```bash
mkdir -p ~/.nextcloud/certificates/
cd ~/.nextcloud/certificates/
openssl req -nodes -newkey rsa:4096 -keyout wepwawet.key -out wepwawet.csr -subj "/CN=wepwawet"
```

Keep `wepwawet.key` secret — never commit it.

### 2. Get the certificate signed by Nextcloud

Submit a pull request to `https://github.com/nextcloud/app-certificate-requests`:

- Create the file `wepwawet/wepwawet.csr` in the PR with the contents of `~/.nextcloud/certificates/wepwawet.csr`
- Include a link to the repo (`https://github.com/jannesklee/wepwawet`) in the PR description

Nextcloud reviewers will sign it and post `wepwawet.crt` back in the PR.
Save the certificate to `~/.nextcloud/certificates/wepwawet.crt`.

### 3. Register the app on apps.nextcloud.com

1. Create an account at `https://apps.nextcloud.com`
2. Go to `https://apps.nextcloud.com/developer/apps/new`
3. Paste the contents of `wepwawet.crt` into the **Certificate** field
4. Generate the ownership proof:
   ```bash
   echo -n "wepwawet" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/wepwawet.key | openssl base64
   ```
5. Paste the output into the **Signature** field and submit

### 4. Get an API token

On apps.nextcloud.com go to **My Account → API Token** and copy it.

### 5. Add secrets

On GitHub, go to repo **Settings → Secrets and variables → Actions** and add:

| Secret | Value |
|---|---|
| `APP_PRIVATE_KEY` | Full contents of `~/.nextcloud/certificates/wepwawet.key` |
| `APP_PUBLIC_CRT` | Full contents of `~/.nextcloud/certificates/wepwawet.crt` |
| `APPSTORE_TOKEN` | The API token from apps.nextcloud.com |

`GITHUB_TOKEN` (used to create the release and upload the tarball) is provided automatically by GitHub Actions — no separate secret needed.

---

## Running the release from GitHub

This repo's CI/release path is GitHub Actions. `.github/workflows/release.yml` builds the frontend, tarballs the app, and creates a GitHub release via `softprops/action-gh-release`. It triggers on a `v*.*.*` tag push.

Nothing needs to be configured beyond the repo secrets in step 5 above — GitHub Actions is enabled by default and runners are hosted, no self-managed runner to register.

The CSR PR to `nextcloud/app-certificate-requests` and registration on apps.nextcloud.com (steps 2–4 above) are unaffected by any of this — those are external processes tied to the Nextcloud project and your apps.nextcloud.com account, not to where this repo's CI runs.

---

## Before the first release

### Fix the licence tag in `appinfo/info.xml`

The App Store validator requires the SPDX identifier for Nextcloud 31+:

```xml
<!-- change: -->
<licence>agpl</licence>
<!-- to: -->
<licence>AGPL-3.0-or-later</licence>
```

### Wire up signing in the release pipeline

The `.github/workflows/release.yml` has the skeleton ready. Once the certificate is in place, uncomment and complete the signing and App Store push steps. The pipeline needs to:

1. Run `occ integrity:sign-app` to produce `appinfo/signature.json` (verified by Nextcloud on install)
2. Package the tarball (excluding dev files via `.nextcloudignore`)
3. Upload the tarball to the GitHub release
4. Push the release to the App Store via the `R0Wi/nextcloud-appstore-push-action` action — handles the tarball signature automatically

---

## Per-release checklist

1. Update `CHANGELOG.md`
2. Bump the version in `appinfo/info.xml`
3. Commit and push
4. Tag: `git tag v1.0.0 && git push origin v1.0.0`
5. The GitHub Actions pipeline triggers automatically on the tag and publishes to the App Store

---

## How signing works (background)

Two independent signatures are involved:

- **`appinfo/signature.json`** — created by `occ integrity:sign-app`. Contains SHA-512 hashes of every file in the app plus a cryptographic signature. Committed to the repo and included in the tarball. Nextcloud verifies this on install.
- **Tarball signature** — a signature over the `.tar.gz` archive itself, submitted to the App Store alongside the download URL. The `R0Wi/nextcloud-appstore-push-action` computes and submits this automatically.
