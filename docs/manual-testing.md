# Manual Testing Guide

Step-by-step environment setup and manual test workflow for both the web app and the companion (React Native) app. See `docs/dev-notes.md` for the reasoning behind the less obvious steps below (each is cross-referenced).

## 1. Start the web app + Nextcloud

```bash
docker compose -f docker-compose.dev.yml up -d
docker exec --user www-data locshare-dev php occ app:enable locshare
```

Access at http://localhost:8080 (admin / admin123).

Build the frontend after any JS change (PHP changes take effect immediately, modulo opcache — see §5):

```bash
export PATH="$HOME/.nvm/versions/node/v22.11.0/bin:$PATH"
npm run dev     # watch mode
# or
npm run build   # one-off production build
```

After changing `appinfo/routes.php` or adding a migration:

```bash
docker exec --user www-data locshare-dev php occ maintenance:repair
```

**Web app manual test checklist:**
- Open http://localhost:8080/apps/locshare — auto-creates a group on first visit, shows the map.
- Tap "Share my location", confirm your own marker appears.
- Copy the invite link, open it in a private/incognito window, join as a guest, confirm both markers show.
- Create a timed share link, open it in another browser/private window, confirm the public viewer works and expires correctly.

## 2. Start the companion app + Android emulator

```bash
# Emulator, with snapshot-save disabled (see dev-notes.md #1)
/home/jklee/Android/Sdk/emulator/emulator -avd Pixel_4 -netdelay none -netspeed full -no-snapshot-save -no-snapshot-load &

# Wait for full boot
adb wait-for-device shell 'while [[ -z $(getprop sys.boot_completed 2>/dev/null) ]]; do sleep 1; done'

cd companion
npx expo run:android
```

If this is a from-scratch/clean native build (after `expo prebuild --clean` or deleting `android/`), the first build may fail with `configureCMakeDebug[arm64-v8a]` / `WARNING: A restricted method in java.lang.System has been called` — re-run with:

```bash
JAVA_TOOL_OPTIONS="--enable-native-access=ALL-UNNAMED" npx expo run:android
```

(see `docs/dev-notes.md`). Ordinary rebuilds don't need this.

### Reconnecting the dev client

The install step's deep link points at the host's LAN IP, which the emulator's NAT can't reach (see `dev-notes.md` #2). Every time you reinstall, or restart the emulator, or the app ends up stuck on the Dev Launcher home screen:

```bash
adb reverse tcp:8081 tcp:8081
adb shell am start -a android.intent.action.VIEW \
  -d "exp+locshare-companion://expo-development-client/?url=http%3A%2F%2F127.0.0.1%3A8081"
```

`adb reverse` mappings don't survive an emulator restart or a long idle period — check with `adb reverse --list` if the app seems stuck.

## 3. Make the local Nextcloud reachable from the emulator

The emulator reaches the host via the special address `10.0.2.2` (not `localhost`). Two pieces of one-time Nextcloud config are needed for the companion app to actually work against it — both are container state, so redo them after recreating the container:

```bash
docker exec --user www-data locshare-dev php occ config:system:set trusted_domains 2 --value="10.0.2.2"
docker exec --user www-data locshare-dev php occ config:system:set overwritehost --value="localhost:8080"
docker exec --user www-data locshare-dev php occ config:system:set overwriteprotocol --value="http"
```

The first makes Nextcloud accept requests via `10.0.2.2` at all; the other two force every generated absolute URL (share links, etc.) to say `localhost:8080` instead of whatever host the request came in on, so links created from the emulator are still openable from a browser on the host (see `dev-notes.md` #4 and #6).

## 4. Test the companion app — Nextcloud login mode

1. Get an admin app password: Nextcloud → Settings → Security → App passwords (or just use the account password in this dev setup).
2. In the app's setup screen, "Nextcloud login" tab:
   - Server URL: `http://10.0.2.2:8080`
   - Username: `admin`
   - App password: `admin123` (or a real app password)
3. Save & start sharing. Group members list should populate; "Create share link" should work and the returned link should open fine in a host browser (per §3's `overwritehost` fix).

To get a real invite token/URL for guest testing (§5) or to double check group state:

```bash
curl -sS -u admin:admin123 http://localhost:8080/apps/locshare/api/me -H "OCS-APIRequest: true"
```

## 5. Test the companion app — guest / invite-link mode

1. Get the group token from the `api/me` call above (or from the "Invite link" the web app shows).
2. Reset the app to the setup screen. The in-app gear icon overlaps the Expo dev client's floating "Tools" bubble and taps land on the wrong one — instead of fighting that overlap, just clear the app's stored config directly:
   ```bash
   adb shell pm clear com.locshare.companion
   ```
   (this also resets granted permissions — you'll need to re-grant location access, see §6)
3. Relaunch (see §2's reconnect snippet), "Invite link" tab:
   - Invite URL: `http://10.0.2.2:8080/apps/locshare/join/{token}` — use `10.0.2.2`, not whatever host the real invite link shows, since that's what's reachable from inside the emulator.
   - Display name: anything (e.g. `TestGuest`)
   - Share for: any duration
4. Save & start sharing.
5. Verify from the host:
   ```bash
   curl -sS -u admin:admin123 http://localhost:8080/apps/locshare/group/1/positions -H "OCS-APIRequest: true"
   ```
   Should show a `"type":"guest"` entry with a fresh `updatedAt`. Toggling sharing off in the app should make that entry disappear from the response (`stop=1` flow).

## 6. Test background location sharing

1. Grant permissions when prompted: foreground first ("While using the app" / "Precise"), then Android redirects to a system settings page for background access — select **"Allow all the time"** and navigate back.
2. Set a fake GPS fix (the emulator has no real GPS):
   ```bash
   adb emu geo fix <lon> <lat>
   # e.g. adb emu geo fix 13.405 52.520
   ```
3. Toggle sharing on. The card should read "Sharing location" with a green dot and "Updating every ~5 s in background".
4. Send the app to background (`adb shell input keyevent KEYCODE_HOME`) and confirm updates keep arriving — poll the positions endpoint (§5 step 5) and check `updatedAt` keeps advancing.
5. To inspect the location task itself (it runs in a separate OS process, `LocationTaskService`) for crashes:
   ```bash
   adb logcat -d | grep -iE "LocShare|LocationTaskService|FATAL"
   ```

## 7. General debugging tools

- Screenshot: `adb exec-out screencap -p > screen.png`
- Inspect the UI tree for exact tap coordinates: `adb shell uiautomator dump /sdcard/dump.xml && adb shell cat /sdcard/dump.xml`
- JS-side errors are logged via `console.error` in `companion/src/api.ts` and `src/locationTask.ts` — check with `adb logcat -d | grep "ReactNativeJS.*LocShare"`.
- If the whole machine feels slow while the emulator is running, check `vmstat 1 3` for I/O wait (`wa` column) before assuming it's CPU-bound — see `dev-notes.md` #1.
