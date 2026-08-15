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
| `wp-content/plugins/tender-gateway/` | All platform functionality — tender API client, access gate, registration, dashboard, admin settings |
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

## Connecting the live tender API

The plugin never talks to a specific vendor's API. It talks to `TG_API`, which is
configured from **Tender Gateway** in the WordPress admin menu. No code changes are
needed to go live.

Set:

- **Data source** — switch from bundled demonstration tenders to the live API
- **API endpoint** — the URL that returns the tender list
- **API key** and **authentication** — `Authorization: Bearer`, a custom request header, or a query parameter
- **Results path** — dot path to the array inside the response, e.g. `data.tenders`. Leave blank to auto-detect a bare list or a `data` / `results` / `items` wrapper
- **Field mapping** — which key in each API record supplies each field on the gateway. Dot notation is supported, e.g. `authority.name`
- **Cache** — how long a successful response is reused

There is a **Test API connection** button that calls the endpoint with the saved
settings and reports how many tenders came back and how the first record mapped.

If the live API is unreachable the gateway serves the last good response, and then the
bundled sample feed — a demo or a live site never shows an error page because an
upstream portal was down.

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
- No console or PHP errors on any page
- No horizontal overflow at 360 / 390 / 412 / 430 / 540 / 767 px
- Installed from scratch on a clean WordPress to confirm the package is self-contained
