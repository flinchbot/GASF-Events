# Google Business Profile — event-driven hours sync

Status doc for the integration that sets the GASF listing's **special hours** from
`gasf_event` calendar entries. Written 2026-07-19.

---

## 1. Goal

Our hall's opening hours vary with the event schedule (evening concerts, Friday
dinners, seasonal festivals). Today those are updated by hand in the Business
Profile UI and routinely go stale, so visitors arrive on wrong information.

Target: a scheduled sync that rebuilds the listing's `specialHours` from the
`gasf_event` CPT.

## 2. Which API (this is the part that trips people up)

**Not the Places API.** Places is read-only — it can report `opening_hours` but
there is no write path, with any key or scope.

Hours are written through the **Google Business Profile API**:

| Purpose | Service |
|---|---|
| Resolve account + location IDs | `mybusinessaccountmanagement.googleapis.com` |
| Read/write hours | `mybusinessbusinessinformation.googleapis.com` |

```
PATCH https://mybusinessbusinessinformation.googleapis.com/v1/locations/{locationId}
      ?updateMask=specialHours
```

Use `specialHours` (dated overrides that expire on their own), **not**
`regularHours`. Overwriting `regularHours` per event means tracking and
restoring a baseline, which will drift.

### ⚠️ `specialHours` is a full replace, not an append

Every PATCH overwrites the entire set of special-hour periods. The sync cannot
push "the next event" incrementally — each run must read the calendar, compute
the **complete** desired list for a rolling window (~90 days), and write all of
it in one call.

Build it as "rebuild the whole window from the calendar." That is also
self-healing: a failed run is corrected by the next one. Get this wrong and each
sync silently wipes the previous events' hours.

## 3. Auth — service accounts do NOT work here

`includes/class-google-calendar.php` is the right structural model (transient-cached
token, `wp_remote_post` to the token endpoint, `WP_Error` handling) but its
**auth model does not carry over**. It uses a service account (RS256 JWT). GBP
locations are owned by a human Google account and cannot be shared with a
service account.

| | Calendar (existing) | Business Profile (new) |
|---|---|---|
| Identity | Service account | Google account managing the listing |
| Grant | `jwt-bearer` | `refresh_token` |
| Credentials | Key JSON | client_id + client_secret + refresh token |

Scope: `https://www.googleapis.com/auth/business.manage`

### Consent screen must stay "In production"

External + **Testing** status makes Google revoke refresh tokens after exactly
**7 days**, which would break the sync weekly. It is currently External +
In production. Do not click "Back to testing."

Unverified-app warning during consent is expected (sensitive scope, verification
skipped) — Advanced → "Go to … (unsafe)". Fine at one-user scale; 100-login cap.

## 4. Current state — credentials DONE, quota BLOCKED

**GCP project:** GASF-Places / `gasf-places` / number `656232842481`

| Piece | Status |
|---|---|
| Both APIs enabled | ✅ |
| Consent screen (External, In production) | ✅ |
| Desktop OAuth client | ✅ `656232842481-trh31ifnls2fsfrrtnpgjbe81838h28j.apps.googleusercontent.com` |
| Key file on server | ✅ `/home4/germanta/gasf-gbp-key.json`, perms `600`, above docroot |
| refresh → access token | ✅ verified HTTP 200 |
| **GBP quota** | ❌ **0 QPM — sole blocker** |

Key file shape:

```json
{ "client_id": "...", "client_secret": "...", "refresh_token": "..." }
```

Read it as `dirname( ABSPATH ) . '/gasf-gbp-key.json'` with a `GASF_GBP_KEY`
constant override — mirroring `Google_Calendar::key_path()`.

### The blocker

Approval email received **2026-07-14**, but quota was never provisioned. Live
calls return:

```
HTTP 429 RESOURCE_EXHAUSTED
"Quota exceeded ... 'Requests per minute' of service
 'mybusinessaccountmanagement.googleapis.com' for consumer
 'project_number:656232842481'"
quota_limit_value: "0"
```

Escalated on support case **`6-9389000041422`**.

Self-service quota increase is **not** available: that form requires confirming
"my quota is not currently set to zero," and it is zero. The 0 → 300 step only
happens through allowlist provisioning.

**Check for resolution:** Cloud Console → the API → Quotas → `Requests per minute`.
`0` = still blocked, `300` = live. Check **both** APIs — Account Management is
known to lag behind Business Information.

Interim: special hours can be set by hand in the Business Profile UI. The API is
an efficiency win, not the only route to correct hours.

## 5. Server facts

- Bluehost shared hosting (cPanel), user `germanta`, **SSH port 2222**
- WP root: `/home4/germanta/public_html` → `germantampabay.com`
- Plugin: `/home4/germanta/public_html/wp-content/plugins/gasf-events`
- `dirname( ABSPATH )` = `/home4/germanta` — above docroot, not web-reachable
- Existing service-account key for Calendar: `/home4/germanta/gasf-calsync-key.json` (600)
- Three other WP installs on the account (`/foundation`, `/dancers`, `/krampus`) — unrelated

Never place credentials under `public_html`; it is publicly served.

## 6. Next steps

1. Wait on / chase case `6-9389000041422` until `Requests per minute` = 300.
2. Build `includes/class-google-business-profile.php` — mirror `Google_Calendar`
   structure, `refresh_token` grant, transient-cached access token.
3. Discover and store the location ID once:
   `GET .../v1/accounts` then `GET .../v1/accounts/{id}/locations?readMask=name,title`
   → save `locations/{locationId}` in plugin settings; it never changes.
4. Implement the rolling-window rebuild of `specialHours`.

## 7. Unrelated bug spotted

`gasf-events.php` header declares `Version: 0.13.0` but `GASF_EVENTS_VERSION` is
defined as `'0.12.0'` immediately below. Anything keyed off the constant (updater,
cache-busting) is a version behind.
