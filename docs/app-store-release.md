# Nextcloud App Store — Release & Signing Guide

## Prerequisites

- `openssl` available on your machine
- Forgejo repo secrets configured (see step 5)
- A Nextcloud account on apps.nextcloud.com

---

## One-time setup

### 1. Generate a key pair

```bash
mkdir -p ~/.nextcloud/certificates/
cd ~/.nextcloud/certificates/
openssl req -nodes -newkey rsa:4096 -keyout sopdet.key -out sopdet.csr -subj "/CN=sopdet"
```

Keep `sopdet.key` secret — never commit it.

### 2. Get the certificate signed by Nextcloud

Submit a pull request to `https://github.com/nextcloud/app-certificate-requests`:

- Create the file `sopdet/sopdet.csr` in the PR with the contents of `~/.nextcloud/certificates/sopdet.csr`
- Include a link to the repo (`https://forgejo.jannesklee.de/jklee/Sopdet`) in the PR description

Nextcloud reviewers will sign it and post `sopdet.crt` back in the PR.
Save the certificate to `~/.nextcloud/certificates/sopdet.crt`.

### 3. Register the app on apps.nextcloud.com

1. Create an account at `https://apps.nextcloud.com`
2. Go to `https://apps.nextcloud.com/developer/apps/new`
3. Paste the contents of `sopdet.crt` into the **Certificate** field
4. Generate the ownership proof:
   ```bash
   echo -n "sopdet" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/sopdet.key | openssl base64
   ```
5. Paste the output into the **Signature** field and submit

### 4. Get an API token

On apps.nextcloud.com go to **My Account → API Token** and copy it.

### 5. Add secrets

On `forgejo.jannesklee.de`, repo go to **Settings → Actions → Secrets** and add:

| Secret | Value |
|---|---|
| `APP_PRIVATE_KEY` | Full contents of `~/.nextcloud/certificates/sopdet.key` |
| `APP_PUBLIC_CRT` | Full contents of `~/.nextcloud/certificates/sopdet.crt` |
| `APPSTORE_TOKEN` | The API token from apps.nextcloud.com |
| `FORGEJO_TOKEN` | A Forgejo access token (`write:repository` scope) — needed to create the release and upload the tarball via the API, since Forgejo has no built-in release-action equivalent to GitHub's `softprops/action-gh-release` |

---

## Running the release from Forgejo

This repo's only CI/release path is Forgejo Actions — no GitHub involved. `.forgejo/workflows/release.yml` builds the frontend, tarballs the app, and creates a Forgejo release via the REST API. It triggers on a `v*.*.*` tag push to this repo on `forgejo.jannesklee.de`.

Before it'll actually run, confirm on `forgejo.jannesklee.de` (none of this can be checked or done from the repo itself):

1. **Actions is enabled** for the repo — Settings → Units → Actions.
2. **A runner is registered** against the instance (Site Admin → Actions → Runners), and the `runs-on: docker` label in the workflow matches a label that runner actually advertises — change it if not.
3. **Outbound access to github.com is allowed** for the runner — the workflow pulls `actions/checkout` directly from a full GitHub URL (`uses: https://github.com/actions/checkout@v4`), which Forgejo Actions supports natively but only if the instance's `ACTIONS_HOSTS` config / runner network policy permits it.
4. **Release assets are publicly downloadable** — only matters once the Nextcloud App Store push step is uncommented, since `apps.nextcloud.com` needs to fetch the tarball from a `download-url` pointing at this Forgejo instance.

The CSR PR to `nextcloud/app-certificate-requests` and registration on apps.nextcloud.com (steps 2–4 above) are unaffected either way — those are external processes tied to the Nextcloud project and your apps.nextcloud.com account, not to where this repo's CI runs.

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

The `.forgejo/workflows/release.yml` has the skeleton ready. Once the certificate is in place, uncomment and complete the signing and App Store push steps. The pipeline needs to:

1. Run `occ integrity:sign-app` to produce `appinfo/signature.json` (verified by Nextcloud on install)
2. Package the tarball (excluding dev files via `.nextcloudignore`)
3. Upload the tarball to the Forgejo release
4. Push the release to the App Store via the `R0Wi/nextcloud-appstore-push-action` action (pulled directly from GitHub — see the workflow file's header comment; this is just reusing a published action, unrelated to where the repo itself lives) — handles the tarball signature automatically

---

## Per-release checklist

1. Update `CHANGELOG.md`
2. Bump the version in `appinfo/info.xml`
3. Commit and push
4. Tag: `git tag v1.0.0 && git push origin v1.0.0` (adjust the remote name to whatever this repo's Forgejo remote is called)
5. The Forgejo Actions pipeline triggers automatically on the tag and publishes to the App Store

---

## How signing works (background)

Two independent signatures are involved:

- **`appinfo/signature.json`** — created by `occ integrity:sign-app`. Contains SHA-512 hashes of every file in the app plus a cryptographic signature. Committed to the repo and included in the tarball. Nextcloud verifies this on install.
- **Tarball signature** — a signature over the `.tar.gz` archive itself, submitted to the App Store alongside the download URL. The `R0Wi/nextcloud-appstore-push-action` computes and submits this automatically.
