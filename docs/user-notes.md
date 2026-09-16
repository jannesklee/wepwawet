# Who This Project Is For

Product requirements and working preferences behind Wepwawet, kept alongside the technical notes since they explain *why* certain decisions were made.

## What the maintainer wants

A self-hosted Google Family Sharing / WhatsApp live location replacement, running entirely within their own Nextcloud instance. No third-party services, no separate apps to install.

**Product preferences:**
- Simple UX above all — family members using this are non-technical.
- No PhoneTrack-style complexity (sessions, devices, tokens) exposed to users.
- Smartphone is the primary sharing device — desktop Nextcloud is mainly a viewer.
- Prefers building clean, minimal solutions over reusing complex existing backends.

**Working style:**
- Comfortable with architectural decisions and code review; wants to understand trade-offs (e.g. PWA vs. native, one-sided vs. mutual sharing) rather than have them made silently.
- Handles git commits and privileged/system-level operations (e.g. starting system services) themselves rather than delegating them.
- Prefers durable technical/debugging knowledge to live in versioned project files (like this one and `docs/dev-notes.md`) rather than in tooling-specific state that isn't visible to everyone working on the repo.
