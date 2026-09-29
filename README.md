# VonSEO for MyBB 1.8

VonSEO is a server-rendered SEO subsystem for MyBB, adapted from the URL ownership and crawler-safety concepts used in VonCMS SEO and informed by proven MyBB SEO plugin patterns.

Current stable release: **1.0.0**. Keyword URLs remain opt-in and off by default. Version 1.0.0 combines the 0.6 content coverage with fail-closed IndexNow removal/retry handling, stable calendar/event identity and concurrency-safe bounded 404 admission. It is not a search-ranking or indexing guarantee.

VonSEO is licensed under **LGPL-3.0-only**. See `NOTICE.txt` and `LICENSE.txt`.

## Requirements

- MyBB 1.8.x
- PHP 7.1 or newer for plugin syntax. PHP 8.3–8.5 passed source lint and self-contained tests; the installed local board was exercised on PHP 8.4. MyBB's own PHP minimum is broader than this plugin's.
- MySQL or MariaDB. VonSEO uses MySQL-family upserts plus advisory locking for bounded 404 logging and its durable IndexNow queue.
- No MyBB core edits
- Core metadata and native crawler endpoints are web-server independent. The optional clean and keyword URL rules supplied in `extras/` are written for Apache. Nginx and LiteSpeed need equivalent site-specific rules.

## Package structure

```text
admin/
└── modules/
    └── config/
        └── vonseo.php

inc/
├── plugins/
│   ├── vonseo.php
│   └── vonseo/
│       ├── Core.php
│       ├── Context.php
│       ├── Errors.php
│       ├── IndexNow.php
│       ├── Keyword.php
│       ├── Meta.php
│       ├── Redirects.php
│       ├── Robots.php
│       ├── Schema.php
│       ├── Sitemap.php
│       ├── Social.php
│       ├── State.php
│       ├── Url.php
│       └── Utils.php
├── tasks/
│   └── vonseo_indexnow.php
└── languages/
    └── english/
        ├── vonseo.lang.php
        └── admin/
            └── vonseo.lang.php

extras/
└── htaccess-vonseo.txt
```

## Install

1. Extract the package.
2. Upload only the package's `admin/` and `inc/` folders to the **root of your MyBB installation**. Keep `extras/` outside the public forum; merge the optional rewrite snippet manually only if needed.
3. Confirm `inc/plugins/vonseo.php` exists.
4. Admin CP → Configuration → Plugins.
5. Install & Activate **VonSEO for MyBB**.
6. Admin CP → Configuration → **VonSEO** for the overview, **How to use** guide, redirects and the 404 monitor.
7. Admin CP → Configuration → Settings → **VonSEO** for engine settings.

Uninstalling through MyBB removes VonSEO's settings, redirect/404/IndexNow queue tables, queue task and task logs; deactivation keeps that data. Uninstall does not delete uploaded plugin files or any Apache rewrite rules you copied into `.htaccess`. Back up redirect rules before uninstalling if you may need them again.

## Quick start

The default installation enables the SEO engine, sitemap, robots output, redirect engine, real 404 responses, privacy-aware 404 monitoring and IndexNow. Keyword URLs and member-profile indexing remain off by default.

1. Open Admin CP → Configuration → **VonSEO**. Confirm the database schema is current and there is no **Needs attention** warning.
2. Complete **Site Details**. Board Name, Board URL, Homepage Name and Homepage URL update MyBB's original settings; SEO Description is the only VonSEO-specific identity field.
3. Open one public thread while logged out. View the raw page source and confirm there is one canonical link, one description, social metadata and one VonSEO JSON-LD block.
4. Open `misc.php?action=vonseo_sitemap` and `misc.php?action=vonseo_robots`. The sitemap should contain only guest-visible content.
5. Keep keyword URLs off unless you want them. Basic metadata, schema, sitemap, redirects, 404 handling and IndexNow do not require keyword URLs.
6. Configure the host-root `/robots.txt` separately when MyBB is installed in a subfolder. A rule inside `/forum/.htaccess` cannot serve `/robots.txt` at the domain root.

Recommended starting policy:

- keep **Own SEO Meta Tags** on unless a tested theme or plugin must own those tags;
- leave member-profile indexing off unless public profiles contain useful, non-sensitive content;
- leave external redirect targets off unless they are genuinely required;
- leave legacy SearchAction schema off;
- test AI crawler choices against your site's publishing policy;
- enable keyword URLs only after the direct-route test described below passes.

## Using `extras/htaccess-vonseo.txt`

The file in `extras/` is a collection of optional Apache rewrite snippets. It is not a complete replacement for MyBB's `.htaccess` and must not be uploaded as a second active configuration file.

1. Confirm the server uses Apache with `mod_rewrite`. Nginx and LiteSpeed users must translate these routes into the server's own configuration.
2. Find the active `.htaccess` in the MyBB installation root, beside files such as `index.php`, `showthread.php` and `forumdisplay.php`.
3. Back up that existing `.htaccess`.
4. Open `extras/htaccess-vonseo.txt` from the VonSEO package and copy only the required blocks into the active MyBB-root `.htaccess`.
5. Keep one `RewriteEngine On` directive near the start of the rewrite section. Do not duplicate or replace the rest of MyBB's configuration.
6. Save the file, clear any host/server cache, and test direct URLs while logged out.

The snippet contains three blocks:

### Clean crawler endpoints

These rules provide clean aliases for the dynamic endpoints:

- `sitemap.xml` → `misc.php?action=vonseo_sitemap`
- `robots.txt` → `misc.php?action=vonseo_robots`

They are optional because the native `misc.php?action=...` URLs work without rewrites. For a subfolder installation such as `https://example.com/forum`, the MyBB-root rule creates `/forum/robots.txt`, not the host-root `/robots.txt` requested by crawlers. Configure the host-root file or rewrite separately.

### Keyword thread and forum URLs

Copy all six keyword rules as one block. Place them before the optional VonSEO fallback router. MyBB's existing thread/forum `.html` rules may remain in place.

Test in this order:

1. Leave **VonSEO keyword URLs** off.
2. Visit a known public `t-ID-topic`, `t-ID-topic--post-PID`, `t-ID-topic--lastpost`, paginated thread and `f-ID-forum` directly.
3. Each valid route should return a temporary redirect to the matching native MyBB URL. A 404 means the rewrite block is not active or is in the wrong file/location.
4. After every direct route works, enable **VonSEO keyword URLs** and repeat the tests. The keyword path should then remain canonical.

The ACP may report how many of the six patterns it finds in the root `.htaccess`, but file detection alone does not prove Apache serves them. Direct HTTP visits are the real test.

To roll back, turn the keyword setting off but keep the six rules while old keyword links may still exist. VonSEO will temporarily redirect those paths back to native MyBB URLs. Before deactivating or uninstalling VonSEO, replace any still-shared keyword paths with tested server redirects.

### Optional redirect and 404 fallback router

The final generic rule sends unknown pretty paths to `misc.php?action=vonseo_route`, allowing Redirect Manager and the custom 404 handler to process paths that do not map to a real PHP file.

- Place this block last, after MyBB's normal forum/thread rewrites and the six VonSEO keyword rules.
- Keep the supplied existing-file and existing-directory conditions so real assets and directories are not intercepted.
- This block is optional. Database redirects for requests that already reach MyBB can still work without it, but arbitrary extensionless paths need a server route to enter MyBB.

After merging, the structure should be conceptually similar to this:

```apache
RewriteEngine On

# Existing MyBB rewrite rules
# VonSEO clean crawler aliases, if wanted
# Six VonSEO keyword rules, if wanted
# VonSEO generic fallback router, always last if used
```

Do not add `/forum/` to the beginning of the rule patterns when `.htaccess` is already inside the `/forum` directory. Apache evaluates those patterns relative to that directory.

## Upgrade

1. Back up the MyBB database, `.htaccess` and a VonSEO redirect CSV export.
2. Upload the new package's `admin/` and `inc/` folders over the existing VonSEO files. Do not uninstall the old version first, because uninstall removes VonSEO data.
3. In Admin CP → Configuration → Plugins, deactivate and reactivate VonSEO. Deactivation preserves data; activation runs the idempotent settings, schema and task repair.
4. Open Configuration → VonSEO and confirm the schema is current, the IndexNow task state is healthy, and saved redirects still appear.
5. Recheck one public thread, sitemap, robots output and any enabled keyword routes as a guest.

If an upgrade is interrupted, keep the files and data in place and reactivate VonSEO again. The migration runner is designed to repair missing fields, indexes, settings and the background task without resetting redirect rules.


## SEO engine

VonSEO currently provides:

- dynamic server-rendered titles/descriptions;
- canonical URLs;
- robots directives;
- Open Graph and Twitter metadata;
- JSON-LD;
- public-only sitemap indexes;
- dynamic robots output;
- guest-permission indexing baseline;
- subfolder-safe canonical URL normalization.

Active public MyBB announcements now use the same canonical, board identity and guest-permission engines as forums and threads. VonSEO captures the text MyBB rendered for a guest, then emits a page description, article social tags, breadcrumb-aware `Article` JSON-LD and a paginated announcement sitemap. Global announcements are eligible while active; forum announcements are excluded when the assigned forum is inactive, password-protected or unavailable to Guest. Future and expired announcements are also excluded. This feature does not create announcement keyword URLs or change MyBB's announcement routes.

Guest-visible MyBB monthly calendars and public events now use MyBB's own calendar/event URL helpers. Monthly calendar pages receive canonical metadata and `CollectionPage` JSON-LD. Public event pages receive a guest-rendered description, `Event` JSON-LD with real start/end data when available, and a calendar breadcrumb. Private, unapproved and guest-denied events are excluded from metadata and the event sitemap. Day, week and calendar utility views remain noindex to avoid duplicate archive surfaces. VonSEO does not fabricate event locations, offers or rich-result eligibility.

Keyword URLs for public forums and threads are available as an opt-in setting. They use stable IDs and flat paths, for example `t-42-sample-topic`, `t-42-sample-topic--post-105#pid105`, `t-42-sample-topic--lastpost` and `f-4-general--p2`; flat paths avoid breaking MyBB's relative links on direct visits. Add the optional Apache rules from `extras/htaccess-vonseo.txt` at the MyBB root and verify direct visits **before** enabling the setting. Canonical, schema, sitemap, IndexNow, ordinary forum/thread navigation, page links, exact `tid + pid` post targets and MyBB's stock `lastpost`, `newpost`, `nextnewest` and `nextoldest` actions on reviewed public pages use the keyword family. Post-only URLs without a thread ID, display modes, sort/filter links, archive/print variants and protected content keep their native behavior. Other page types and custom themes may still emit stock links. Extra query parameters on keyword routes are discarded by Apache so they cannot override the path's IDs or action.

### Keyword URL migration and rollback

1. Back up the database, `.htaccess`, and any redirect CSV. On a staging copy, retain MyBB's own `.html` rules and add the six VonSEO keyword rules **before** the generic fallback rule. Directly visit a known public `t-ID-slug`, `t-ID-slug--post-PID`, `t-ID-slug--lastpost` and `f-ID-slug` while the setting is still off; each should temporarily redirect (`302`) to the matching MyBB URL. If any returns 404, fix rewrite placement before enabling the setting.
2. Enable **VonSEO keyword URLs** in Admin CP → Configuration → Settings → VonSEO. Ordinary public MyBB URL aliases temporarily redirect (`302`, `Cache-Control: no-store`) to the keyword canonical. Previously shared `t-ID-old-slug` / `f-ID-old-slug` URLs permanently redirect (`301`) to the current slug, retaining a valid page number. No VonSEO URL database migration is needed because the numeric ID remains stable across title changes. Missing, unapproved, moved and guest-inaccessible entities are not redirected into public URLs.
3. Check forum index, forum list, thread, pagination, sitemap and canonical output as a guest; also check exact post targets, all four stock thread actions, display/filter modes and post-anchor links. Do not run another plugin's URL or canonical module alongside VonSEO. Existing third-party SEO URL schemes **are not automatically imported**: export or map those old paths, inspect collisions and loops, then import exact redirects with VonSEO's Redirect Manager CSV on staging before switching URL ownership.
4. To roll back, switch the keyword setting off while leaving the six Apache rules and VonSEO active. Keyword visits then return a non-cacheable `302` to MyBB's current URL; stock MyBB links/canonicals are restored. If you deactivate/uninstall VonSEO, the rules alone can still serve keyword paths but cannot issue these rollback redirects, so replace them with tested server redirects before removing the plugin. Do not remove the six rules while old keyword links may exist.

## Redirect and error engine

The current release includes a database-backed redirect manager with exact source matching:

- `301 Moved Permanently`;
- `302 Found`;
- `307 Temporary Redirect`;
- `308 Permanent Redirect`;
- `410 Gone`.

Safety behavior:

- 301/302 rules do not run on POST requests;
- 307/308 preserve the request method;
- self-redirects and redirect loops are rejected;
- external targets are disabled by default;
- `javascript:`, `data:`, protocol-relative and CR/LF destinations are rejected;
- error and permission pages emit `noindex` and do not inherit private thread/forum schema;
- 404 monitoring stores only an aggregated URL identity, hit count and timestamps; no visitor IP address. Only route-specific public query identifiers are retained; unknown, tracking and secret-bearing fields are discarded. Low-value rows are pruned in bounded batches after the 10,000-row cap is crossed.

### Using Redirect Manager

1. Open Admin CP → Configuration → VonSEO → **Redirects**.
2. Enter an exact board-relative source such as `/old-thread` or `/old?ref=1`. Query-bearing sources match exactly.
3. Enter an internal destination path or an allowed HTTP/HTTPS URL. Leave the destination empty only for `410 Gone`.
4. Choose the response:
   - `301` for a permanent move of normal GET/HEAD pages;
   - `302` for a temporary move;
   - `307` for a temporary method-preserving redirect;
   - `308` for a permanent method-preserving redirect;
   - `410` when the resource was intentionally removed and has no replacement.
5. Save the rule and test the source in a logged-out browser. Use the hit counter to confirm real requests are matching it.

External destinations are rejected unless **Allow External Redirect Targets** is enabled. VonSEO rejects self-redirects, known loops, protocol-relative targets, unsafe schemes and header-breaking characters.

CSV import/export uses this header:

```csv
source_path,target_url,status_code,enabled
/old-thread,/new-thread,301,1
/retired-page,,410,1
```

Imports are limited to 2 MiB and 5,000 data rows. Export before a bulk change. Use overwrite only when an existing exact source should be replaced.

### Using the 404 Monitor

The 404 Monitor records final public `404 Not Found` responses as normalized URL identities with aggregate hit counts. It does not store visitor IP addresses. Unknown, tracking and common secret-bearing query fields are discarded.

- Repeated hits increase the same row rather than creating visitor records.
- Use **Create Redirect** when a missing URL has a clear replacement.
- Clear one entry after resolving it, or clear all entries after a controlled test.
- Visiting the custom test endpoint `misc.php?action=vonseo_404` deliberately produces a 404 and can therefore appear in the monitor. That is expected; clear the test entry afterward.
- Disable **404 Monitor** if logging is unwanted while keeping **404 Status Guard** enabled for correct HTTP responses.

## IndexNow queue

New public threads are queued immediately. Public replies and edits use a five-minute per-thread delivery cooldown, so a burst of activity collapses to the current canonical URL. Moderated replies do not ping until approved. Approve, unapprove, restore, soft-delete and permanent-delete moderation transitions update the queue; safely remembered old public thread URLs can be recrawled after removal without deriving URLs for private forums.

At delivery time, the MyBB task rechecks guest visibility and resolves the current canonical slug. Each queue update has a generation token to protect fresh concurrent work. Missing/invalid configuration and HTTP 400/403/422 retain the queue without consuming retries; HTTP 429 honours `Retry-After`; timeouts and HTTP 5xx use bounded exponential retries. The ACP reports **Ready**, **Config Error**, **Rate Limited** or **Remote Error**, queue depth, last success and the human-readable last error. A protected POST action can replace the IndexNow key; the dynamic verification endpoint immediately serves only the new key, so there is no old key file to delete.

The Overview also includes one compact **Site Details** form. Board Name, Board URL, Homepage Name and Homepage URL read and write MyBB's original settings directly; VonSEO does not create duplicate identity settings. Homepage Name/URL provide the Organization publisher identity in JSON-LD, with a safe fallback to Board Name/URL. SEO Description remains VonSEO-specific because core MyBB has no standard description field, but it is edited only in Site Details and does not appear again under Advanced Settings. The homepage SEO title always follows MyBB's Board Name. Email, contact, cookie, address and fax settings are intentionally excluded.

### IndexNow setup

1. Open Configuration → VonSEO. A key is generated during installation and the Overview should report **Ready** when IndexNow is enabled.
2. Open the displayed IndexNow key endpoint and confirm it contains only the current key. This verification key is intentionally public and is not a password or private API credential.
3. Confirm the `vonseo_indexnow` task is enabled under Tools & Maintenance → Task Manager. New public thread activity is queued; visitor requests do not wait for the remote service.
4. Publish or update a public test thread and allow the scheduled task to run. Check queue depth, last success and the last error in the Overview.
5. If the key is missing or should be replaced, use **Generate new IndexNow key**. The new key immediately replaces the old verification value while preserving queued URLs.

Worker states:

- **Ready**: configuration is valid and no blocking remote condition is recorded;
- **Config Error**: the key or local configuration needs attention; queued URLs are retained;
- **Rate Limited**: the remote service requested a delay; queued URLs are retained and `Retry-After` is honoured;
- **Remote Error**: a timeout or remote failure will use bounded retry handling.

Localhost can verify queue and key behavior but cannot prove public IndexNow delivery. Final verification requires a publicly reachable board.

## Crawler URLs

Without rewrite rules:

- Sitemap: `misc.php?action=vonseo_sitemap`
- Robots: `misc.php?action=vonseo_robots`
- Custom 404: `misc.php?action=vonseo_404`

For Apache, merge the optional rules from:

`extras/htaccess-vonseo.txt`

The optional fallback router lets rules such as `/old-page` be handled by VonSEO even when that path does not map to a real MyBB PHP file. Put the VonSEO fallback **after MyBB's normal forum/thread rewrite rules**.

For a board installed below the host root, such as `https://example.com/forum`, the rule in the MyBB-root `.htaccess` can serve `/forum/robots.txt` but not the crawler-standard host URL `/robots.txt`. Add a static host-root file or equivalent web-document-root rewrite. The ACP warns about this boundary; the dynamic endpoint remains available for inspection. Custom `Crawl-delay` lines are preserved. AI training/scraper blocking is separate from the optional AI search-crawler toggle.

Submit the sitemap URL that is actually reachable on the public site. With no clean rewrite, submit `https://example.com/forum/misc.php?action=vonseo_sitemap`. With a tested Apache clean route, submit the corresponding `sitemap.xml`. A sitemap helps discovery but does not guarantee indexing.

## Admin CP

`Configuration → VonSEO` provides:

- a short **How to use** guide; advanced settings are optional for most boards;
- a shared MyBB Site Details form plus the VonSEO SEO Description in one place;
- module health/status;
- redirect manager;
- redirect hit counters;
- 404 monitor;
- one-click “Create Redirect” from a recorded 404;
- direct links to crawler endpoints;
- basic `.htaccess` route detection;
- database migration and IndexNow queue diagnostics, with a concise **Needs attention** notice only when action is required.
- protected one-click actions to use MyBB's board identity directly and to replace the IndexNow verification key.

MyBB administrator permissions are split by responsibility. The base **VonSEO** permission opens health, redirects and the 404 monitor. Changing Site Details or rotating the IndexNow key additionally requires **Can change VonSEO Site Details and rotate the IndexNow key?** or MyBB's core settings permission. After upgrading, grant the additional permission only to administrators who should be able to change the board identity and crawler key; super administrators continue to have access automatically.

## Indexing defaults

Indexable by default:

- board homepage;
- public forums;
- public visible threads;
- active global announcements and announcements assigned to guest-visible forums;
- guest-visible monthly calendars and public approved events;
- portal (configurable).

Noindex by default:

- search;
- User CP;
- PM;
- Mod CP;
- login/register/member utility pages;
- print/edit/new reply/new thread utility pages;
- member profiles (configurable);
- private/password/inactive forums;
- non-public threads;
- VonSEO error pages.

## Post-install verification checklist

- [ ] VonSEO Overview reports the current version and schema with no unresolved warning.
- [ ] A logged-out public thread returns HTTP 200 and has one canonical, description and JSON-LD block.
- [ ] A private, password-protected or unapproved page is absent from public metadata and the sitemap.
- [ ] A missing public entity returns HTTP 404 with `noindex` rather than HTTP 200.
- [ ] Sitemap XML opens successfully and lists only guest-visible URLs.
- [ ] The crawler-standard host-root `/robots.txt` is reachable, especially when MyBB is installed in a subfolder.
- [ ] Any enabled keyword thread, post, action, pagination and forum route works by direct visit.
- [ ] Redirect rules return the selected status and do not create a chain or loop.
- [ ] IndexNow reports **Ready** if it is enabled; otherwise disable it intentionally.
- [ ] Canonical host and scheme match MyBB's configured Board URL.

## Troubleshooting

### Keyword URL returns 404

- Confirm `RewriteEngine On` and merge all six keyword rules into the MyBB-root `.htaccess`.
- Keep the rules before the optional generic fallback router and do not remove MyBB's normal `.html` rules.
- With keyword URLs off, directly visit a known `t-ID-slug` and `f-ID-slug`. They should temporarily redirect to MyBB's native URLs. Enable the setting only after this works.
- Nginx and LiteSpeed do not consume the supplied Apache rules directly; translate and test equivalent routes in the server configuration.

### Duplicate canonical or social tags

Keep **Own SEO Meta Tags** enabled so VonSEO removes matching theme/plugin tags before inserting its output. If another extension must own these tags, disable the conflicting module and verify raw HTML rather than the browser inspector alone.

### Sitemap or robots endpoint is forbidden

Confirm VonSEO and the matching sitemap or robots setting are enabled. Test the native `misc.php?action=...` endpoint before debugging clean rewrite rules.

### `/forum/robots.txt` works but `/robots.txt` does not

This is expected for a subfolder installation. Configure a static host-root file or a web-document-root rewrite. VonSEO does not overwrite a parent application's robots file automatically.

### IndexNow queue does not empty

Read the Overview state and last error. Keep the queue intact while fixing a missing key, rate limit or remote failure. Confirm the MyBB task is enabled and has opportunities to run. Do not repeatedly rotate a valid key to clear a remote error.

### 404 Monitor shows a URL that was opened for testing

The monitor records real final 404 responses, including deliberate visits to the custom 404 endpoint. Clear that row after testing. If only logging is unwanted, disable the monitor rather than the HTTP status guard.

### Canonical URL uses the wrong host or subfolder

Correct MyBB's **Board URL** in Site Details. VonSEO deliberately trusts that configured URL instead of the visitor-controlled `Host` header.

## Design principles

- `bburl` is the trusted canonical origin.
- Canonical, Open Graph, JSON-LD and sitemap share one URL engine.
- Guest permissions determine public indexability.
- Metadata is emitted server-side.
- No core edits.
- No JavaScript dependency for SEO output.
- No `Crawl-delay` is emitted by default.
- The plugin does not promise search rankings.
- Optional `SearchAction` markup is retained for compatibility, but is off by default for new installs. Google's Sitelinks Search Box result was retired in 2024.

## 1.0 scope

Version 1.0.0 keeps the broader content coverage from 0.6 and adds fail-closed IndexNow removals, retry boundaries that new content cannot bypass, stable logged-in calendar/event identity, current-month canonical consolidation, trustworthy event sitemap dates, safer clean-route rewrite selectors and serialized admission for new 404 rows. It does not include profile sitemaps or automatic conversion of legacy third-party SEO URL databases. Calendar day/week views intentionally remain noindex. Each site's rewrite stack, theme, other plugins and production crawler behavior still need staging verification.
