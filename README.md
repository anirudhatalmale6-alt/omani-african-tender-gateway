# Omani-African Tender Gateway — Phase 1 Ministry Prototype

A working WordPress demo of a platform that connects Omani suppliers with African
government tender opportunities. Built for presentation to Ministry stakeholders.

It demonstrates the full user journey end to end:

1. **Public homepage** — the concept, the sectors, the markets, a live tender feed
2. **Tender listing** — filter by country, sector and closing window; sort by deadline, date or value
3. **The gate** — clicking a tender checks whether the visitor is signed in
4. **Register / sign in** — front-end supplier registration with company and sector details
5. **Full tender file** — reference number, scope of works, eligibility criteria, buyer contacts, documents
6. **Supplier dashboard** — tenders matched to declared sectors, a watchlist, and the company profile

## What is in this repository

| Path | What it is |
|---|---|
| `wp-content/plugins/tender-gateway/` | All platform functionality — API sync, tender store, access gate, registration, dashboard, admin screens |
| `wp-content/themes/tender-gateway/` | Government-style theme — header, footer, homepage, page templates |

Everything else is standard WordPress and is not included.

## Installing on your own hosting

1. Install WordPress (6.0 or newer, PHP 7.4+) on the target domain.
2. Copy `tender-gateway` into `wp-content/themes/` and `wp-content/plugins/`.
3. In **Appearance → Themes**, activate **Tender Gateway**.
4. In **Plugins**, activate **Tender Gateway**.

That is the whole install. Activation is self-configuring and idempotent — it creates
the pages, sets the static front page, switches on pretty permalinks, builds the primary
menu and creates a demonstration supplier account. Re-activating never overwrites
anything you have since edited.

### Demonstration account

```
supplier@demo.om  /  demo1234
```

## The TendersOnTime integration

### Why it syncs rather than queries

TendersOnTime accepts exactly one request-level filter — the tender posting date.
Keywords, regions, categories and organisations are **not** request parameters; they are
pre-configured on the account at their end. Their guidance is to pull on a schedule,
store the records yourself, and filter in your own application. Their backend refreshes
every 3 hours.

So the gateway does that:

```
TendersOnTime API  ──►  TG_Sync (every 3 hours, one request per posting date)
                            │  normalise + map fields
                            ▼
                   {prefix}tg_tenders  (this site's own database)
                            │
                            ▼
                   TG_API  ──►  listing, filters, search, tender pages
```

Two consequences that matter:

- **Filtering and search are instant**, because they run against a local indexed table
  rather than a third-party API on every click.
- **Rendering a page never depends on TendersOnTime being reachable.** If their API is
  slow or down mid-presentation, every already-synced tender still displays. A failed
  sync is logged and the previous records stay put.

Records are keyed on `(source, external_id)`, so a tender re-published with an amended
deadline updates in place instead of appearing twice. Tenders whose deadline passed more
than 60 days ago are pruned automatically.

### Configuring it

Everything is on **Tender Gateway → Settings**. No code changes are needed to go live.

- **Data source** — bundled demonstration tenders, or the live API
- **API endpoint** — the tender list URL. Put `{date}` in it if the posting date belongs
  in the path; otherwise it is appended as a query parameter
- **API key** and **authentication** — query parameter, `Authorization: Bearer`, or a custom header
- **Response format** — JSON, XML, or detect automatically
- **Results path** — dot path to the array inside the response, e.g. `data.tenders`.
  Blank auto-detects a bare list or a `tenders` / `data` / `results` / `items` wrapper
- **Date parameter / date format / posting dates per sync**
- **Field mapping** — which key in each API record supplies each gateway field.
  Dot notation is supported, e.g. `authority.name`

**Apply TendersOnTime defaults** fills the sync and mapping fields with sensible
TendersOnTime names as a starting point.

Contract values arrive from vendor feeds as strings — `"3,120,000,000"`,
`"1.234.567,89"`, `"USD 48.5 million"` — so they are parsed rather than cast. Dates are
normalised from whatever format the feed uses.

### Demonstrating it

**Tender Gateway → API Data Flow** shows what actually happened on the last sync, in
five steps: the request per posting date with status, timing and size; the raw record
exactly as it arrived; a field-by-field mapping table flagging anything that came out
empty; what was inserted, updated and stored; and a link through to the live listing.
It is built to be walked through in front of stakeholders. The API key is masked
everywhere it would otherwise be printed.

The tender listing itself carries a provenance line — the source, the record count and
how long ago the feed last updated.

### Scheduling

The sync runs on WP-Cron every 3 hours. WP-Cron fires on page loads, so on a quiet site
it can drift. For production, disable it and use a real server cron instead:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```
0 */3 * * * cd /path/to/site && php wp-cron.php > /dev/null 2>&1
```

## Access model

The split between public and members-only is enforced server side, in
`TG_Shortcodes::tenders()`. A signed-out visitor is served a different template
(`tender-locked.php`) that never renders the restricted values at all — the blurred
teaser behind the gate is redaction bars, not the real content, so nothing restricted
reaches the page source.

**Public:** title, country, contracting authority, sector, estimated value, publication
date, submission deadline.

**Registered suppliers only:** tender reference, procurement method, full scope of
works, eligibility and qualification criteria, buyer contact details, tender documents.

## Notes on the sample data

The 24 bundled tenders are illustrative and cover 15 African markets and 10 sectors.
Deadlines are stored as offsets from the current date, so the demo does not go stale
while it is being shown around. All contact addresses use `.example` domains so nothing
can be sent to a real inbox by accident.

## Tested

- Full journey driven in a real browser: browse → gate → register → unlocked tender →
  watchlist → dashboard → sign out → sign in → unlocked tender
- Sync run end to end against a TendersOnTime-shaped feed: 3 posting dates, 13 records
  received, deduplicated to 7 tenders, re-syncing the same dates updates in place
- Failure modes: a rejected API key and an unreachable host both log the error and leave
  every previously synced tender on the site
- Store round-tripped: insert, update-in-place, nested contact/document/eligibility data,
  numeric and date normalisation, prune and clear
- Value parser checked against `3,120,000,000`, `1.234.567,89`, `1,234,567.89`,
  `USD 48.5 million`, `1,5`, `1,500` and non-numeric input
- No console or PHP errors on any page
- No horizontal overflow at 360 / 390 / 412 / 430 / 540 / 767 px
- Installed from scratch on a clean WordPress to confirm the package is self-contained
