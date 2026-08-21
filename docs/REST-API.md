# Public REST API

Read-only JSON feed of published events. No key, no auth, no write routes — the
only verb registered is `GET`, and only `publish`-status events are ever queried.

Base: `https://germantampabay.com/wp-json/gasf-events/v1`

---

## `GET /events`

Returns an array of events. With no parameters: **the next 100 upcoming events,
soonest first, with every field**.

```
https://germantampabay.com/wp-json/gasf-events/v1/events
```

### Parameters

| Param | Default | Notes |
|---|---|---|
| `limit` | `100` | 1–500. Values outside the range clamp rather than error. |
| `order` | `asc` | `asc` = soonest first, `desc` = latest first. Case-insensitive; anything else is a 400. |
| `fields` | *(all)* | Which keys to return — see [Choosing fields](#choosing-fields). |
| `from` | *(now)* | Start of the window. `YYYY-MM-DD`, an ISO datetime, or a unix timestamp. |
| `to` | — | End of the window, same formats. |
| `updated_since` | — | Only events modified after this. For incremental sync. |

Omitting both `from` and `to` gives upcoming events only (anything that has not
finished yet). Supplying either one switches to an explicit window, so
`?from=2026-01-01&to=2026-12-31` includes past events in that range.

### Response headers

- `X-GASF-Count` — number of events in the body.
- `Cache-Control: public, max-age=120`.

---

## Choosing fields

By default every event carries all 15 fields. `fields` takes **either** a list to
include **or** a list of `-` prefixed names to exclude — not both in one request.

**Include only what you name:**

```
/events?limit=5&fields=title,start,image
```
```json
[
  { "title": "Dinner Night", "start": "2026-09-04T19:00:00-04:00", "image": "https://…/dinner.jpg" }
]
```

**Or drop just the heavy parts:**

```
/events?fields=-description,-venue
```

Two rules worth knowing:

- **You get exactly what you name.** Nothing is force-included — `fields=title`
  returns objects with only a `title` key, no `id`. Ask for `id` if you need it.
- **Typos are errors, not silence.** An unknown name returns `400` and lists the
  valid ones, so a misspelled field can't look like an empty field.

Key order in the response always follows the canonical order below, regardless of
the order you list them in.

### Available fields

| Field | Type | Notes |
|---|---|---|
| `id` | int | WordPress post ID. |
| `title` | string | |
| `slug` | string | |
| `url` | string | Permalink to the event page. |
| `start` | string | ISO 8601 with site offset. `""` if unset. |
| `end` | string | ISO 8601, `""` when the event has no end time. |
| `all_day` | bool | |
| `status` | string | `scheduled`, `cancelled`, `postponed`, `sold_out`, `online_only`. Never empty — an unset status reads as `scheduled`. |
| `image` | string | Cover URL (large). Falls back to the site default image, so never empty. |
| `description` | string | Plain text, tags stripped. |
| `organizer` | object | `{ name, url }` |
| `venue` | object | `{ name, street, city, state, zip, country, lat, lng }` |
| `series_id` | string | Groups occurrences of a recurring event; `""` when standalone. |
| `source` | string | `manual` (the default), `facebook`, or another feed identifier. |
| `modified` | string | ISO 8601 UTC — pair with `updated_since`. |

`image` and `description` are the two expensive fields: `description` expands
shortcodes and `image` hits the attachment tables. Leaving them out of `fields`
skips that work entirely rather than computing and discarding it, so a lean
request is genuinely cheaper, not just smaller.

---

## `GET /events/{id}`

One event as a single object (not an array). Accepts `fields` with the same
rules. Returns `404` with `{"error":"not_found"}` if the ID is unknown or the
event is not published.

```
/events/1234?fields=title,start,image
```

---

## Errors

`400` responses use the standard WordPress REST error shape:

```json
{
  "code": "gasf_fields_unknown",
  "message": "Unknown field(s): titel. Available fields: id, title, slug, …",
  "data": { "status": 400, "unknown": ["titel"], "available": ["id", "title", "…"] }
}
```

| Code | Cause |
|---|---|
| `gasf_fields_unknown` | A name in `fields` isn't a real field. |
| `gasf_fields_mixed` | `fields` mixed plain and `-` prefixed names. |
| `gasf_order_invalid` | `order` was neither `asc` nor `desc`. |

---

## Recipes

The next event, minimal payload — for a "coming up next" widget:

```
/events?limit=1&fields=title,start,url
```

Five upcoming events with art, for a card grid:

```
/events?limit=5&fields=id,title,start,url,image
```

Everything in one month, no bulky text:

```
/events?from=2026-10-01&to=2026-10-31&fields=-description
```

Incremental sync — poll with the newest `modified` you have stored:

```
/events?updated_since=2026-08-01T12:00:00Z&limit=500
```

This month's events, most recent first:

```
/events?from=2026-08-01&to=2026-08-31&order=desc
```

---

## Stability

The no-`fields` payload is a **frozen contract** (ARCHITECTURE §7) — the hallway
kiosk is being built against it. New fields may be appended to the end of the
list; existing keys will not be renamed, reordered, or removed. If you depend on
a specific shape, name it explicitly with `fields` and you are insulated from
additions entirely.
