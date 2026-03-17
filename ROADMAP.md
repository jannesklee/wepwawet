# LocShare Roadmap

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

## Future ideas

- [ ] Multiple groups per user
- [ ] Accept invite as authenticated user from the join page (currently redirects to main app)
- [ ] Push notifications when a member joins or leaves
- [ ] Data retention settings (auto-delete old position data)
- [ ] Nextcloud Activity integration (log sharing events)
