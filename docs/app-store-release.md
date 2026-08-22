# Nextcloud App Store — Release & Signing Guide

## Prerequisites

- `openssl` available on your machine
- GitHub repo secrets configured (see step 4)
- A Nextcloud account on apps.nextcloud.com

---

## One-time setup

### 1. Generate a key pair

```bash
mkdir -p ~/.nextcloud/certificates/
cd ~/.nextcloud/certificates/
openssl req -nodes -newkey rsa:4096 -keyout locshare.key -out locshare.csr -subj "/CN=locshare"
```

Keep `locshare.key` secret — never commit it.

### 2. Get the certificate signed by Nextcloud

Submit a pull request to `https://github.com/nextcloud/app-certificate-requests`:

- Create the file `locshare/locshare.csr` in the PR with the contents of `~/.nextcloud/certificates/locshare.csr`
- Include a link to the GitHub repo in the PR description

Nextcloud reviewers will sign it and post `locshare.crt` back in the PR.
Save the certificate to `~/.nextcloud/certificates/locshare.crt`.

### 3. Register the app on apps.nextcloud.com

1. Create an account at `https://apps.nextcloud.com`
2. Go to `https://apps.nextcloud.com/developer/apps/new`
3. Paste the contents of `locshare.crt` into the **Certificate** field
4. Generate the ownership proof:
   ```bash
   echo -n "locshare" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/locshare.key | openssl base64
   ```
5. Paste the output into the **Signature** field and submit

### 4. Get an API token

On apps.nextcloud.com go to **My Account → API Token** and copy it.

### 5. Add GitHub secrets

In the GitHub repo go to **Settings → Environments → release → Secrets** and add:

| Secret | Value |
|---|---|
| `APP_PRIVATE_KEY` | Full contents of `~/.nextcloud/certificates/locshare.key` |
| `APP_PUBLIC_CRT` | Full contents of `~/.nextcloud/certificates/locshare.crt` |
| `APPSTORE_TOKEN` | The API token from apps.nextcloud.com |

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
3. Upload the tarball to the GitHub Release
4. Push the release to the App Store via `R0Wi/nextcloud-appstore-push-action` (handles the tarball signature automatically)

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
