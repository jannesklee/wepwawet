# LocShare

A minimal Nextcloud app for live location sharing — the self-hosted alternative to WhatsApp Live Location / Google Family Sharing.

## Screenshots

![Share your location](img/screenshot_share.png)
![Live viewer](img/screenshot_view.png)

## Features

- **Timed share links** — create a link, share it with anyone. They open it in a browser, no account needed, and see your position on a live map.
- **No third-party services** — map tiles from OpenStreetMap, everything else stays on your Nextcloud.

## Requirements

- Nextcloud 30–34

## Installation

Place the `locshare` folder in your Nextcloud `custom_apps/` directory and enable it:

```bash
php occ app:enable locshare
```

## Development

```bash
npm ci
npm run dev        # watch mode
npm run build      # production build
```

Docker dev environment:

```bash
docker compose -f docker-compose.dev.yml up -d
docker exec --user www-data locshare-dev php occ app:enable locshare
```

Access at http://localhost:8080 (admin / admin123).

## License

[AGPL-3.0](LICENSE) — the same license used by Nextcloud itself.
