# GASF Events

A lean, native WordPress events plugin for the **German American Society of Tampa** — built to replace **Modern Events Calendar (MEC)** plus its third-party Facebook importer and the stack of must-use (MU) patch modules.

> **Status:** under active construction, built in the background. It is fully **isolated** — own `gasf_event` post type, temporary `/gasf-events/` URL slug, `_gasf_*` meta, `gasf_events_*` options. It does **not** touch MEC, the MU plugins, the theme, or any production data. The live site keeps running unchanged until a single deliberate cutover. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## What it does (target)

- Owns event data as a clean `gasf_event` model (single start/end datetime, status, cover, single venue/organizer — no MEC cruft).
- Renders the four views the club actually uses: **month grid, upcoming list, signage tile, single-event page** — semantic HTML + CSS variables + minimal JS, so it prints and the kiosk can consume it.
- **First-class month printing** (US-Letter landscape), **visitor add-to-calendar** (Google/Outlook/Apple/.ics + kiosk QR), and full **Event JSON-LD** SEO.
- Native **Facebook import** (replacing the 3rd-party importer + MU band-aids) and three-way **FB → WP → Eventbrite** sync, all gated OFF until cutover.
- Recurring events as a lightweight **flat-but-grouped series**, an **All Events** admin list with bulk actions, and per-event **view stats**.

## Public API

A read-only JSON feed of published events, no key required:

```
GET /wp-json/gasf-events/v1/events?limit=5&fields=title,start,image
```

`limit`, `order`, `from`/`to`, `updated_since`, and a `fields` include/exclude
list. See [`docs/REST-API.md`](docs/REST-API.md).

## Phases

See `docs/ARCHITECTURE.md` §11. P1 (this commit) = plugin scaffold, `gasf_event` model, settings, Event Details meta box, and the All Events list.

## License

GPL-2.0-or-later.
