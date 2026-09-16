# Wepwawet Roadmap

## MVP (done)

- [x] Database schema (groups, members, positions, guests)
- [x] All 6 API routes (page, join, accept, positions, position update, guest update)
- [x] Authenticated Vue app with MapLibre GL map
- [x] Member markers with avatars/initials, auto-fit bounds
- [x] Real-time geolocation sharing via `watchPosition()`
- [x] 15-second polling of group member positions
- [x] Guest join page (invite token, display name, duration selection)
- [x] Guest position updates with expiration countdown and auto-stop
- [x] Invite URL copy button
- [x] Brute-force protection on join endpoint

## Near-term

- [ ] **Multi-device support** — add `device_name` to positions so laptop and phone are tracked separately; show device name in marker popup. Lays groundwork for Mode 1.
- [ ] **OpenGraph preview** — generate a static map snapshot when a share link is created so messengers (WhatsApp, Signal, Telegram) show a map thumbnail when the link is pasted.
- [ ] **Background cleanup job** — Nextcloud background job to purge expired guest rows from the database
- [ ] **Group management UI** — Rename group, remove members, regenerate invite token
- [ ] **Rate limiting** — Throttle position update endpoints to prevent abuse

## Testing

- [ ] PHPUnit tests for backend (controllers, mappers)
- [ ] Vue component tests
- [ ] End-to-end tests

## Polish

- [ ] **Internationalization (i18n)** — Extract hardcoded English strings into translation files
- [ ] **Accuracy radius** — Show location accuracy circle on map
- [ ] **Direction indicator** — Show speed/bearing arrow on member markers
- [ ] **Offline detection** — Retry logic when network is unavailable

## Mobile app

The PWA covers basic use cases but has limitations on iOS (no background GPS) and Android (battery optimisation kills the browser tab). A minimal native app is needed for reliable background location sharing.

- [ ] **React Native app (or equivalent)** — minimal: background GPS, POST position to Nextcloud, nothing else. No map, no UI beyond a start/stop button. The Nextcloud web UI remains the viewer.
- [ ] Background location on iOS requires a native app — PWA cannot do this
- [ ] Battery status reporting (feeds into Mode 1 dashboard)

## Release

- [ ] **Nextcloud App Store submission** — requires a signing certificate from Nextcloud. Pipeline is prepared (`.forgejo/workflows/release.yml`) but signing is commented out pending the certificate.
- [ ] **Versioning** — follow semver, tag `v*.*.*` to trigger the release pipeline
- [ ] **Changelog** — keep `CHANGELOG.md` up to date before each release

## Future ideas

- [ ] Multiple groups per user
- [ ] Accept invite as authenticated user from the join page (currently redirects to main app)
- [ ] Push notifications when a member joins or leaves
- [ ] Data retention settings (auto-delete old position data)
- [ ] Nextcloud Activity integration (log sharing events)
