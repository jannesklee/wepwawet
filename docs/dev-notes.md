# Dev Notes: Known Issues & Fixes

Debugging notes that aren't obvious from the code itself, kept here so they don't have to be re-discovered. Add to this file as new non-obvious issues get root-caused. See `docs/manual-testing.md` for the step-by-step setup and test workflow these notes support.

## Background job registration silently failed on every request, occasionally as a hard 503

`lib/AppInfo/Application.php`'s `register()` called `$context->registerBackgroundJob(CleanupExpiredGuests::class)` — but `IRegistrationContext` in this Nextcloud version (33.0.0.16, and per the API surface checked in `lib/public/AppFramework/Bootstrap/IRegistrationContext.php`) has no `registerBackgroundJob()` method at all. Background jobs in this version are registered **declaratively in `appinfo/info.xml`** instead (confirmed against core's `apps/files/appinfo/info.xml`, which uses a `<background-jobs><job>...</job></background-jobs>` block), not imperatively in the bootstrap class.

Effect: every single request to any locshare route threw `Error during app service registration: Call to undefined method ...::registerBackgroundJob()` — logged at error level on every page load (visible via `occ background-job:list` never showing `CleanupExpiredGuests` at all, and via a constant stream of identical entries in `data/nextcloud.log`). Nextcloud's `Coordinator` catches per-app registration errors so most requests still succeeded despite the log spam, but the failure was non-deterministic enough to occasionally surface as a genuine `503` on an otherwise-unrelated request (seen while testing the group-removal feature — the request itself was fine, the concurrent registration crash was not). The practical side effect: `CleanupExpiredGuests` (hourly cleanup of expired guests + share links) **never actually ran**, silently, since the app was first built.

Fix: removed the `registerBackgroundJob()` call from `Application.php` and added
```xml
<background-jobs>
    <job>OCA\LocShare\BackgroundJob\CleanupExpiredGuests</job>
</background-jobs>
```
to `appinfo/info.xml`. Verified via `occ app:disable locshare && occ app:enable locshare && occ maintenance:repair`, then confirming (a) `occ background-job:list | grep LocShare` shows the job, and (b) no new `nextcloud.log` entries appear across repeated requests.

If you see `Call to undefined method ...IRegistrationContext@anonymous::registerXxx()` for some *other* registration call in the future, the fix is the same shape: check whether that particular `registerXxx` actually exists on `IRegistrationContext` in the target Nextcloud version before assuming it's the right API — several registration types (background jobs among them) are declared in `info.xml` instead, depending on version.

## Actual Nextcloud theme CSS variable values (for styling standalone public pages like `templates/join.php`)

`grep`-ing static core CSS for `--border-radius`/`--font-size` definitions turns up nothing — they're generated server-side per request, not in a static file. To find real values, fetch the live theming endpoint instead: `curl http://localhost:8080/apps/theming/theme/default.css?plain=1`. On this dev instance (NC 33.0.0.16, default theme) the values that matter for `templates/join.php`/`src/Viewer.vue`-style standalone pages are:

- `--border-radius-large` is an alias for `--border-radius-element`, which is **8px**, not the `12px` fallback `templates/join.php` used to assume before the 2026-09-09 restyle. The fallback only matters if the page renders with no NC theme CSS loaded at all (shouldn't happen for `PublicTemplateResponse` pages), but keep fallbacks honest anyway.
- `--border-radius-container-large` is **16px** — this is what `src/Viewer.vue`'s hardcoded `border-radius: 16px` on `.ls-viewer-overlay-card` actually matches; use the var (with `16px` fallback) instead of hardcoding it in new code.
- `--font-size-small` is **13px**. There's no generic `--font-size-normal`; the app's own convention (see `App.vue`, `Viewer.vue`) is to hardcode body/label text in the 13-15px range in px, not rem — `rem`-based sizing in `join.php` was inconsistent with this and got converted.
- `--clickable-area-large` is **48px**, `--default-clickable-area` (what unstyled `NcButton` uses) is **34px**. A full-width `padding: 13px 16px` at `font-size: 15px` lands close to the 48px target, appropriate for a mobile-first guest CTA even though it's taller than the app's default in-app buttons.
- `--color-box-shadow` is `rgba(77, 77, 77, 0.5)` (no dedicated shadow-strength var) — used at reduced opacity via `box-shadow: 0 1px 10px var(--color-box-shadow, rgba(0,0,0,.1))` for the join-page card lift.

## Guest position updates intermittently 500'd on a guest's first-ever fix (`lib/Db/GuestMapper.php`)

`GuestMapper::upsert()` used the classic check-then-act pattern: `findByGroupAndName()` to look for an existing row, insert if `DoesNotExistException`, otherwise update. When a guest starts sharing for the first time, the browser's `navigator.geolocation.watchPosition` can fire two position callbacks in quick succession (common right after permission is granted — an initial low-accuracy fix immediately followed by a better one), and each one is a separate HTTP request to `PositionController::guestUpdate()`. If both requests' `findByGroupAndName()` calls run before either has inserted, both see no existing row and both call `insert()` — the second hits `oc_locshare_guests`' `(group_id, name)` unique constraint and the request 500s, visible in `nextcloud.log` as `SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: oc_locshare_guests.group_id, oc_locshare_guests.name`. Found live (not in testing) via a guest named "Jannes" hitting it repeatedly in the log.

Fix: catch `OCP\DB\Exception` around the `insert()` call, check `$e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION`, and on that specific reason retry as an update (the racing request's insert has landed by the time this one's insert fails, so the retry's `findByGroupAndName()` now succeeds). Re-throw anything else unchanged. Verified by firing 5 concurrent `POST /guest/{token}` requests for a brand-new guest name — all returned `200` after the fix (all 500'd before it).

Reminder while testing PHP changes against the dev container: opcache's `revalidate_freq` is 60s (see above), so a fresh edit can appear not to have taken effect for up to a minute. `docker exec locshare-dev apache2ctl graceful` forces an immediate reload instead of waiting.

## `templates/join.php` never loaded its own CSS bundle - `\OCP\Util::addStyle()` was missing

`templates/main.php` and `templates/viewer.php` both call `\OCP\Util::addScript($appId, '...')` *and* `\OCP\Util::addStyle($appId, '...')`. `templates/join.php` only ever called `addScript`. This went unnoticed for a long time because the join page's own visual styling all lives in an inline `<style>` block in the template itself, so nothing *looked* broken - but it meant the Vite-built `css/locshare-join.css` (which is just `@import './join-<hash>.chunk.css'` - see `@nextcloud/vite-config`'s "css-entry-points-plugin") was never linked into the page at all, and any real CSS imported from `join.js` silently never took effect.

This surfaced when a MapLibre map was added to the guest join view (2026-09): `join.js` gained `import 'maplibre-gl/dist/maplibre-gl.css'`, and the symptom was "the map tiles show up but the person markers don't" - the base map still renders because MapLibre positions the canvas via inline styles, but marker elements depend on `.maplibregl-marker { position: absolute; ... }` from that external stylesheet, which was never loaded.

General lesson: whenever a `templates/*.php` page's JS gains a real CSS dependency (a library import, not just inline `<style>` in the template), check that its template calls both `Util::addScript()` **and** `Util::addStyle()` with the same basename - confirm by checking the rendered page's `<head>` for a `<link rel="stylesheet" href=".../css/<name>.css">`, not just that the script tag is present.

## Companion app (React Native) — Android background location

The companion app (`companion/`, added in commit b6d14d0) had several stacked bugs blocking end-to-end testing on the Android emulator, on top of each other, each failing silently or with a misleading symptom. All are now fixed.

### The bugs, in the order they were found

1. **Emulator disk I/O**: default Quick Boot snapshot-save on an AVD can write hundreds of MB/s to disk continuously (worse on an encrypted root fs), causing system-wide slowness with low reported CPU usage (`vmstat` shows high `wa`, not obvious from a CPU-centric view like htop). Fix: launch the emulator with `-no-snapshot-save -no-snapshot-load`.
2. **Metro/dev-client connection**: `expo run:android`'s deep link points the dev client at the host's LAN IP (e.g. `192.168.2.174:8081`), which the emulator's NAT network can't hairpin back to. The app silently falls back to the Dev Launcher home screen with no error. Fix: `adb reverse tcp:8081 tcp:8081` + open `exp+locshare-companion://expo-development-client/?url=http%3A%2F%2F127.0.0.1%3A8081` instead. Note: the `adb reverse` mapping does not survive an emulator restart or the session being idle for a while — it silently drops and needs to be redone (check with `adb reverse --list`).
3. **Cleartext HTTP blocked**: no network security config existed, so Android's default policy blocked all plain-HTTP requests to the local dev Nextcloud (`http://10.0.2.2:8080`). Every function in `companion/src/api.ts` had an empty `catch` with no logging, so this failure was completely invisible (fixed permanently — see below).
4. **Nextcloud `trusted_domains`**: the dev container's `config.php` only trusted `localhost`, so even with cleartext allowed, Nextcloud served its "untrusted domain" HTML warning page instead of JSON. Fix: `docker exec --user www-data locshare-dev php occ config:system:set trusted_domains 2 --value="10.0.2.2"`. This is container state, not a code fix — needed again on a fresh container.
5. **The actual background-location bug**: `expo-location`'s Android background task schedules a *persisted* `JobScheduler` job (so it survives reboots), which throws `IllegalArgumentException: Requested job cannot be persisted without holding android.permission.RECEIVE_BOOT_COMPLETED permission` if that permission isn't declared. This crashed the location task's separate `LocationTaskService` process every time sharing was toggled on, invisibly (it's a different OS process from the main UI, so the toggle just showed "Sharing location" as if nothing were wrong). This was the real reason background sharing didn't work.
6. **Share links generated via the emulator were unreachable from the host browser**: Nextcloud's `IURLGenerator::getAbsoluteURL()` (used by `ShareController::create()`) builds absolute URLs from the Host header of whatever request created them — by design, for multi-domain/reverse-proxy setups. Since the companion app talks to Nextcloud via `10.0.2.2:8080` (emulator-only address), share links came back as `http://10.0.2.2:8080/apps/locshare/view/{token}`, which means nothing outside the emulator's network namespace. Not a LocShare bug — this ambiguity only exists because dev has two valid network paths to one server; production only ever has one hostname. Fix (container state, re-apply after a fresh container):
   ```
   docker exec --user www-data locshare-dev php occ config:system:set overwritehost --value="localhost:8080"
   docker exec --user www-data locshare-dev php occ config:system:set overwriteprotocol --value="http"
   ```

### Permanent fixes now in place

- `companion/app.json` → `android.permissions` includes `android.permission.RECEIVE_BOOT_COMPLETED`.
- `companion/plugins/withNetworkSecurityConfig.js` — local Expo config plugin (registered in `app.json`'s `plugins` array) that writes `network_security_config.xml` and wires `android:networkSecurityConfig` into the manifest on every `expo prebuild`. Cleartext is permitted only for `10.0.2.2`, `localhost`, `127.0.0.1` — scoped narrowly enough that it's safe to ship even in release builds, since no real Nextcloud instance resolves to those addresses.
- `companion/src/api.ts` — every API function now logs failures via `console.error` instead of swallowing them silently. Keep this pattern for any new API functions added here.

### The `localhost` vs `10.0.2.2` gotcha also applies to guest links, not just Nextcloud-login mode

Item 3 above documents cleartext-HTTP being blocked, and `manual-testing.md` §4 documents the `localhost`-typo trap for the Nextcloud-login server URL field — but the same trap exists independently for guest-mode invite links, and it fails silently in the exact same way (`console.error`'d `TypeError: Network request failed` on every `sendGuestPosition`/`fetchGroupInfo`/`fetchGuestPositions` call, nothing else visibly wrong in the UI beyond a persistent "Not sharing" status). Root-caused 2026-09-13: a guest link pasted from a URL copied out of a browser tab open to the host (`http://localhost:8080/apps/locshare/join/{token}`) resolves `localhost` to the *emulator's own loopback* once stored and used from inside the emulator — nothing listens there. `adb shell run-as <pkg> cat shared_prefs/SecureStore.xml` won't show the plaintext (it's encrypted at rest), so the fastest way to confirm this diagnosis is a temporary `console.log(JSON.stringify(config.guestLinks))` in `HomeScreen.tsx`, not guessing from the network layer down (raw `nc`/`ping` from `adb shell` to `10.0.2.2:8080` will succeed regardless, since that's testing OS-level routing, not what the app actually has stored).

Fix for a live device: remove the broken link (✕) and re-add it, pasting the `10.0.2.2:8080` form of the invite URL instead of whatever a browser on the host shows. There's no code fix for this one — it's inherent to guests always joining via a URL they were handed, and emulator networking being genuinely different from a real device's.

### Guest mode couldn't tell groups apart, and had no map — both fixed 2026-09-13

Two real gaps found from the user actually using guest mode (not synthetic testing): the "Groups" list in `HomeScreen.tsx` showed only the guest's own chosen display name per link (e.g. "Jane"), never which actual group that was for — confusing with more than one joined group, and indistinguishable from garden-variety broken state when the *group* name is what's actually informative. Separately, guest mode had no map at all; `GroupMap` (added 2026-09 for the companion app) was only ever wired into the Nextcloud-login mode's `GroupDetail`, and the guest-facing `GET /guest/{token}/positions` endpoint (added 2026-09 for the web join page) had no companion-app caller.

Fixed by adding `GroupController::info()` (`GET /join/{token}/info`, public) returning `{name, ownerDisplayName}` for a token — mirrors what `PageController::join` already resolves server-side for the web page, since the companion app never loads that page. `GuestLink` (`config.ts`) gained an optional `groupName` field, populated at join time and backfilled for pre-existing links missing it (a `useEffect` in `HomeScreen.tsx` that calls the new endpoint for any link without one). `api.ts` gained `fetchGroupInfo()` and `fetchGuestPositions()`; a new `GuestGroupDetail` component (guest-mode counterpart to `GroupDetail`) polls the latter every 10s while open and reuses the existing `GroupMap`.

Verified via a full `npx expo prebuild --platform android --clean` followed by `npx expo run:android` — the permission and network config both regenerate correctly with no manual steps.

One unrelated wrinkle turned up during that clean rebuild: **`configureCMakeDebug[arm64-v8a]` fails with `WARNING: A restricted method in java.lang.System has been called`** on a fresh (non-cached) CMake configure, on a JDK 21 host. This is a JDK 21+/AGP-CMake interaction bug (JEP 451 native-access warnings corrupting the CMake-server stdout/stdin protocol), unrelated to LocShare code. Fix: run the build with `JAVA_TOOL_OPTIONS="--enable-native-access=ALL-UNNAMED"` set, e.g. `JAVA_TOOL_OPTIONS="--enable-native-access=ALL-UNNAMED" npx expo run:android`. Only needed after a clean/from-scratch native build; cached rebuilds don't retrigger it.

7. **AVD default RAM (2048MB) is too small — the app gets silently OOM-killed mid-launch.** Symptom looks identical to the Metro/tunnel bug (#2): the deep link fires, the app briefly appears, then you're back on the launcher or the Dev Launcher error screen, with no exception logged for the app. The real cause is Android's `lowmemorykiller`:
   ```
   lowmemorykiller: Kill 'com.locshare.companion' (<pid>), uid 10228, oom_score_adj 0 to free ...; reason: min watermark is breached and swap is low
   ```
   visible via `adb logcat -d | grep -i lowmemorykiller`, timed right around when `libexpo-modules-core.so` loads (`nativeloader: Load ... libexpo-modules-core.so`). Confirm with `adb shell cat /proc/meminfo` showing `MemFree` in the tens-of-MB range. The stock `Pixel_4` AVD (`~/.android/avd/Pixel_4.avd/config.ini`) ships `hw.ramSize=2048`, which isn't enough headroom for the dev client + Hermes + native module init, even though the host itself may have plenty of free RAM — this is an AVD config ceiling, not a host constraint. Fix: bump `hw.ramSize=4096` (or higher) in that `config.ini`, then cold-boot the emulator (cheap with `-no-snapshot-save -no-snapshot-load`, which this project already uses).

   Before chasing the tunnel/Metro setup again on a fresh app-launch failure, check logcat for `lowmemorykiller` first — it's the faster diagnosis.

### Android emulator dev workflow reminders

- Nextcloud dev container: `docker compose -f docker-compose.dev.yml up -d` (see main `CLAUDE.md`).
- Launch the AVD with snapshotting disabled: `emulator -avd Pixel_4 -netdelay none -netspeed full -no-snapshot-save -no-snapshot-load`. Check first that one isn't already running from an earlier session (`pgrep -fl "qemu-system.*Pixel_4"`) — a second launch against the same AVD fails with `FATAL: Running multiple emulators with the same AVD is an experimental feature`. Stop the old one cleanly with `adb emu kill` before relaunching (needed, for example, after editing `config.ini` — the running instance won't pick up the change).
- Fake GPS on the emulator: `adb emu geo fix <lon> <lat>`.
- After every fresh emulator boot or `expo run:android` reinstall, re-run `adb reverse tcp:8081 tcp:8081` before trying to connect the dev client.
- `adb reverse` + the dev-client deep link (`adb shell am start -a android.intent.action.VIEW -d "exp+locshare-companion://..."`) only gets you connected if a Metro bundler is actually running and reachable at `127.0.0.1:8081` inside the emulator. `npx expo run:android` starts one for you; if you're reconnecting to an already-installed build without running that (e.g. after `npx expo start` was never started, or died), the deep link will land on `DevLauncherErrorActivity` and look exactly like a broken tunnel. Check first with `curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/status` on the host (expect `200`); if not, `cd companion && npx expo start` before retrying the deep link.
- Right after a fresh boot, `adb shell am start ...` can fail with `cmd: Can't find service: activity` even though `getprop sys.boot_completed` already reads `1` — the activity-manager service registers a few seconds after that property flips. Not a real error; wait a few seconds and retry. (`adb shell uptime` under ~1 min is a good sign this is what's happening.)
- Rapid `am force-stop` + relaunch cycles (e.g. while scripting the app via `adb`) can trigger transient crashes unrelated to app code: seen once as a `NullPointerException` in `SharedPreferences.getAll()` on `ExpoLocation.startLocationUpdatesAsync`, and once inside React Native's own dev-inspector WebSocket handling (`CxxInspectorPackagerConnection$WebSocketDelegate.didReceiveMessage`). Both self-resolved after one clean force-stop + relaunch with a few seconds to settle — treat as dev-tooling flakiness from reconnecting to Metro too quickly, not a real bug, unless it recurs consistently.

## OpenStreetMap tiles blocked with "Referer is required by tile usage policy"

Nextcloud hardcodes `Referrer-Policy: no-referrer` directly in `lib/base.php` via a raw `header()` call during bootstrap — it's not exposed through the normal per-response header API, so it **can't be overridden from app code** via `Response::addHeader()` (confirmed: adding the header on the `TemplateResponse`/`PublicTemplateResponse` in `PageController` had zero effect, since Nextcloud's own call happens later in the pipeline regardless of where in app code you try to set it).

Effect: the browser sends no `Referer` header at all when fetching OSM tiles, which OpenStreetMap's tile usage policy explicitly rejects (see `osm.wiki/Blocked`). This affects **every** LocShare deployment, not just local dev — any Nextcloud instance has this default.

Fix: a `<meta name="referrer">` element in the page itself *can* relax this for the document's own outgoing requests, even though the HTTP header can't be touched. `lib/Controller/PageController.php` now adds one via `\OCP\Util::addHeader('meta', ['name' => 'referrer', 'content' => 'strict-origin-when-cross-origin'])` alongside the existing `theme-color` meta tag, on all three map-bearing pages (`index`, `join`, `view`). Verified in the rendered HTML output (note: PHP opcache's `revalidate_freq` — 60s in the dev container — means a PHP edit isn't guaranteed to take effect for up to a minute).

The longer-term, more complete fix (already flagged in `CLAUDE.md`'s roadmap) is proxying tiles through Nextcloud instead of hitting `tile.openstreetmap.org` directly from the browser — better for privacy (family members' IPs aren't correlated with location tile requests by a third party) and removes this whole class of issue. Not done yet.
