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
