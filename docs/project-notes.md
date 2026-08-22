# Project Notes

Origin, product vision, and current state of LocShare — background that doesn't fit neatly into `CLAUDE.md`'s build/architecture reference.

## Origin

LocShare is a self-hosted live location sharing app for Nextcloud — the alternative to WhatsApp live location / Google Family Sharing. Built from scratch, no dependency on PhoneTrack or any other Nextcloud app.

The project started by extending PhoneTrack, but its sessions/devices/points data model was a burden for the simple "where is everyone right now" use case, so it was rebuilt as a clean, minimal app instead.

## Tech stack

- Backend: PHP, Nextcloud ORM (QBMapper), DB tables
- Frontend: Vue 3 + MapLibre GL + `@nextcloud/vue`, built with Vite (`@nextcloud/vite-config`)
- Three JS entry points: `main` (app), `join` (guest join page), `viewer` (public live viewer)
- Companion mobile app: React Native / Expo, in `companion/` (see `docs/dev-notes.md` for its Android dev workflow and the bugs that had to be fixed to get background location sharing working)

## Agreed product vision (two modes)

### Mode 2 — Timed share link (WhatsApp Live Location style) — ✅ built
- Phone owner creates a timed link (15 min / 1 hr / 4 hr / no expiry).
- Anyone with the link can watch in a browser — no Nextcloud account needed.
- Link expires automatically; owner can revoke early.
- Viewer page: header with owner avatar + name + expiry countdown, full-screen map, 10s polling.
- Viewer grays out avatar/marker after 1 min without a position update.
- Clicking the marker shows a popup with name + last update time.

### Mode 1 — Permanent sharing (Google Maps style) — not built yet
- One-sided: User A shares with User B. B sees A's position without needing to reciprocate.
- Nextcloud accounts required on both sides.
- Always-on while the mobile app is active.
- Dashboard shows who is sharing with you and who you share with, device name, last-seen, battery %.
- Needs a device registry + sharing-relationship data model.

## Mobile strategy

- **Phase 1**: PWA (installable web app) — done, manifest + service worker added.
- **Phase 2**: React Native companion app — needed for background GPS on iOS and battery status. Built and verified working end-to-end (background location sharing confirmed on the Android emulator — see `docs/dev-notes.md`). iOS side is unbuilt/untested.

## Current DB schema (5 tables)

| Table | Purpose |
|---|---|
| `locshare_groups` | Sharing group owned by a user, has invite token |
| `locshare_group_members` | Nextcloud users who joined a group |
| `locshare_positions` | One row per Nextcloud user, upserted on each GPS update |
| `locshare_guests` | Guests identified by (group_id, name), optional expiry |
| `locshare_shares` | Timed share links: owner_user_id, token, expires_at, created_at |

## Current file structure (key files not already covered in CLAUDE.md)

**Backend:**
- `lib/Controller/ShareController.php` — create(), revoke(), list(), position() for timed share links
- `lib/Db/Share.php` + `ShareMapper.php` — timed share links
- `lib/Db/Guest.php` + `GuestMapper.php` — guests (deleteByGroupAndName, deleteExpired)
- `lib/BackgroundJob/CleanupExpiredGuests.php` — hourly cleanup of expired guests + shares
- `lib/Migration/Version000000Date20260315000000.php` — original 4 tables
- `lib/Migration/Version000000Date20260317000000.php` — locshare_shares table

**Frontend:**
- `src/viewer.js` → `src/Viewer.vue` — public viewer page for timed share links
- `src/utils/stale.js` — shared stale detection (STALE_SECONDS=60, isStale())
- `src/utils/registerSW.js` — shared service worker registration
- `templates/viewer.php`, `templates/sw.php`
- `vite.config.ts` — three entry points: main, join, viewer

**PWA assets:**
- `img/app.svg` — app icon (source)
- `img/icon-192.png`, `img/icon-512.png` — PWA icons (generated from SVG)
- `img/apple-touch-icon.png` — iOS home screen icon (180px)

**Release pipeline:**
- `.github/workflows/release.yml` — triggers on `v*.*.*` tag, builds JS, creates tarball, GitHub Release
- `.nextcloudignore` — excludes dev files from release tarball
- `CHANGELOG.md` — keep a changelog format
- Signing certificate not yet obtained — commented out in workflow, add when ready (see `docs/app-store-release.md` for the full signing/release walkthrough)

## What's working

- Map with OSM tiles, marker popups (avatar + name + updated time + accuracy)
- Sidebar: duration picker, "Create share link" button, active links list with copy+revoke
- Member list in sidebar with green/grey activity dots (grays out + shows time when stale >1min)
- Markers gray out (grayscale filter) when position is stale >1min
- Public viewer page at `/apps/locshare/view/{token}` — polls position every 10s, expiry overlay
- Background job cleans up expired guests and shares hourly
- PWA manifest (3 endpoints) + minimal pass-through service worker
- `apple-touch-icon`, `theme-color` meta tags on all pages
- Companion React Native app — login, group members list, timed share link creation, and background location sharing all confirmed working on Android

## Key CSS lessons learned

- **Regular app pages** (`TemplateResponse` + `NcContent`/`NcAppContent`): add `#locshare-app { height: 100% }` to complete the height chain.
- **Public pages** (`PublicTemplateResponse`): use `position: fixed; top: var(--header-height, 50px); left:0; right:0; bottom:0` on the wrap element — public page layout has `height: auto` containers, so height inheritance never works. Don't try to override `#content`/`#content-wrapper`.
- **CSS entry points**: each page template must use `addStyle($appId, $appId . '-{entryname}')` matching its own Vite entry point, not another page's.

## What's next (agreed order)

1. ~~PWA manifest~~ — done
2. ~~Deploy to production Nextcloud~~ — done (manual: copy to `apps/`, `occ app:enable`, `occ maintenance:repair`)
3. **Multi-device support** — `device_name` in positions, show in popup, groundwork for Mode 1
4. **OpenGraph preview** — static map snapshot on share link creation, for messenger previews
5. **Mode 1** — device registry, one-sided sharing relationships, management UI
6. ~~React Native app~~ — built and verified working on Android emulator (background location sharing confirmed end-to-end); see `docs/dev-notes.md` for the bugs that had to be fixed first. iOS side untested.
7. **Nextcloud App Store** — signing certificate + submission (pipeline ready, signing commented out; see `docs/app-store-release.md`)
