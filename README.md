# VonSEO for MyBB 1.8

VonSEO is a server-rendered SEO subsystem for MyBB, adapted from the URL ownership and crawler-safety concepts used in VonCMS SEO and informed by proven MyBB SEO plugin patterns.

Current version: **1.0.7**. Keyword URLs remain opt-in and off by default. This update validates resolved redirect destinations before saving or importing rules, including slash-prefixed absolute URLs. The read-only inspector, one-use ACP CSV import report, searchable lists and Nginx rewrite examples remain available. It does not change the database schema or SEO defaults and is not a search-ranking or indexing guarantee.

VonSEO is licensed under **LGPL-3.0-only**. See `NOTICE.txt` and `LICENSE.txt`.

## Requirements

- MyBB 1.8.x
- PHP 7.1 or newer for plugin syntax. PHP 8.3–8.5 passed source lint and self-contained tests; the installed local board was exercised on PHP 8.4. MyBB's own PHP minimum is broader than this plugin's.
- MySQL or MariaDB. VonSEO uses MySQL-family upserts plus advisory locking for bounded 404 logging and its durable IndexNow queue.
- No MyBB core edits
- Core metadata and native crawler endpoints are web-server independent. Optional Apache-style and Nginx rewrite examples are supplied in `extras/`; merge the matching example into your existing server configuration and test it on staging.

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
│       ├── AdminImport.php
│       ├── AdminList.php
│       ├── AdminRedirects.php
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
├── htaccess-vonseo.txt
├── htaccess-vonseo-host-root.txt
├── nginx-vonseo.conf
└── nginx-vonseo-subfolder.conf
```

## Install

1. Extract the package.
2. Upload only the package's `admin/` and `inc/` folders to the **root of your MyBB installation**. If your Admin CP directory has been renamed, upload `admin/modules/config/vonseo.php` into that directory's `modules/config/`, not a new `admin/` directory. Keep `extras/` outside the public forum; merge the optional rewrite snippet manually only if needed.
3. Confirm `inc/plugins/vonseo.php` exists.
4. Admin CP → Configuration → Plugins.
5. Install & Activate **VonSEO for MyBB**.
6. Admin CP → Configuration → **VonSEO** for the overview, **How to use** guide, redirects and the 404 monitor.
7. Admin CP → Configuration → Settings → **VonSEO** for engine settings.

Uninstalling through MyBB removes VonSEO's settings, redirect/404/IndexNow queue tables, queue task and task logs; deactivation keeps that data. Uninstall does not delete uploaded plugin files or server rewrite rules. Back up redirect rules before uninstalling if you may need them again.

## Quick start

The default installation enables the SEO engine, sitemap, robots output, redirect engine, real 404 responses, privacy-aware 404 monitoring and IndexNow. Keyword URLs and member-profile indexing remain off by default.

1. Open Admin CP → Configuration → **VonSEO**. Confirm the database schema is current and review **Needs attention** messages. The subfolder robots reminder can remain after successful setup; verify the actual host-root URL as described below.
2. Complete **Site Details**. Board Name, Board URL, Homepage Name and Homepage URL update MyBB's original settings; SEO Description is the only VonSEO-specific identity field.
3. Open one public thread while logged out. View the raw page source and confirm there is one canonical link, one description, social metadata and one VonSEO JSON-LD block.
4. Open `misc.php?action=vonseo_sitemap` and `misc.php?action=vonseo_robots`. The sitemap should contain only guest-visible content.
5. Keep keyword URLs off unless you want them. Basic metadata, schema, sitemap, redirects, 404 handling and IndexNow do not require keyword URLs.
6. Configure the host-root `/robots.txt` separately when MyBB is installed in a subfolder. Follow [Case B: MyBB in a subfolder](#case-b-mybb-in-a-subfolder). A rule inside `/forum/.htaccess` cannot serve `/robots.txt` at the domain root.

Recommended starting policy:

- keep **Own SEO Meta Tags** on unless a tested theme or plugin must own those tags;
- leave member-profile indexing off unless public profiles contain useful, non-sensitive content;
- leave external redirect targets off unless they are genuinely required;
- leave legacy SearchAction schema off;
- test AI crawler choices against your site's publishing policy;
- enable keyword URLs only after the direct-route test described below passes.

## Apache setup: using the `extras` files

The `.txt` files in `extras/` are instructions and optional rewrite snippets, not complete replacements for your existing `.htaccess`. Uploading the `extras/` directory, or renaming `htaccess-vonseo.txt` to `.htaccess`, does not perform a safe installation. Open the matching example and merge only the blocks you need. VonSEO never writes server configuration automatically.

Choose the location first:

| MyBB address | MyBB `.htaccess` location | Host-root robots setup |
| --- | --- | --- |
| `https://example.com/` | Web-document root, beside MyBB's `index.php` | The robots alias in this same file serves `/robots.txt`. |
| `https://example.com/forum/` | `forum/.htaccess`, beside MyBB's `index.php` | A separate site-owner step is needed at `/robots.txt`. |
| `https://forum.example.com/` | The subdomain's own document root | Treat this as a root installation for `forum.example.com`, not as `/forum/` on the parent domain. |

`public_html` is a common hosting document-root name used in the examples below. Your host may use a different directory; use the directory mapped to the actual domain. These examples assume a normal document-root deployment, not an Apache `Alias` mapping.

### Before editing

1. Confirm Apache handles the requests and has `mod_rewrite` enabled. Nginx does not read `.htaccess`; use [the Nginx examples](#using-the-nginx-examples) instead. For LiteSpeed, ask your host to confirm Apache-style rewrite loading is supported and enabled.
2. The host must permit rewrite directives in `.htaccess`, for example through `AllowOverride FileInfo` or an equivalent allowlist in its server configuration. Do not put `AllowOverride` inside `.htaccess`. See the [Apache documentation](https://httpd.apache.org/docs/2.4/howto/htaccess.html).
3. Locate MyBB's active `.htaccess`, beside `index.php`, `showthread.php` and `forumdisplay.php`. Enable hidden-file display in your file manager/FTP client if necessary. If no `.htaccess` exists but MyBB's `htaccess.txt` is present, copy or rename that MyBB file to `.htaccess` first. Do not replace an existing `.htaccess`.
4. Download backups of every `.htaccess` and any existing host-root `robots.txt` you will edit. Keep backups outside the public web directory.
5. Preserve MyBB's native `.html` rewrites, PHP/security directives and other applications' rules. Reuse the existing `RewriteEngine On` section. Put VonSEO aliases and keyword rules before any generic catch-all rule; the optional VonSEO fallback goes last.

### Case A: MyBB at the domain root

For `https://example.com/`, both the forum and host document root are the same directory:

```text
public_html/
├── .htaccess
├── index.php
├── showthread.php
└── inc/
```

1. Open `extras/htaccess-vonseo.txt` locally.
2. Copy **Block A: Clean crawler endpoints** into `public_html/.htaccess`, inside its rewrite section and before a catch-all rule. If `/robots.txt` already has a file/application owner, omit Block A's robots rule and preserve that owner as described in Case B.
3. Copy **Block B** only if you want keyword thread/forum URLs. **Block C** is a separate optional custom-path router; basic metadata, sitemap and robots output do not need it.
4. Save and test `https://example.com/sitemap.xml` and `https://example.com/robots.txt` as a guest.

Do not use `htaccess-vonseo-host-root.txt` for this case: its `/forum/` mapping is only for a subfolder board. If an existing file or application already owns `/robots.txt`, use the existing-robots procedure in Case B instead of adding a competing robots rewrite.

### Case B: MyBB in a subfolder

For `https://example.com/forum/`, there are two different configuration locations:

```text
public_html/
├── .htaccess          # Optional host-root robots mapping only
└── forum/
    ├── .htaccess      # MyBB and VonSEO forum routes
    ├── index.php
    ├── showthread.php
    └── inc/
```

**Step 1: configure the forum directory.** Merge the required blocks from `extras/htaccess-vonseo.txt` into `public_html/forum/.htaccess`, exactly as in Case A. Do not add `forum/` to the rule patterns or destinations in this file: they are relative to the directory containing `.htaccess`.

This creates `https://example.com/forum/sitemap.xml` and `https://example.com/forum/robots.txt`. It does **not** create `https://example.com/robots.txt`, which is the location requested by crawlers. See [Google's robots.txt location requirements](https://developers.google.com/crawling/docs/robots-txt/robots-txt-spec).

**Step 2: configure the host root.** Choose one of these approaches with the site owner:

- **No existing robots owner:** use `extras/htaccess-vonseo-host-root.txt`. Its rule belongs in `public_html/.htaccess`, not `public_html/forum/.htaccess`. Replace `forum/` in the destination with your actual board directory, for example `community/` or `community/board/`. If a rewrite section already exists, insert just the rule after its `RewriteEngine On` and before the parent site's catch-all. If no root `.htaccess` exists, the minimal block below can be used to create one:

```apache
# Web-document-root .htaccess, not forum/.htaccess
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^robots\.txt$ forum/misc.php?action=vonseo_robots [L]
</IfModule>
```

- **An existing robots file or another CMS owns `/robots.txt`:** keep that owner. Review the text from `/forum/misc.php?action=vonseo_robots`, merge the forum's directives into the appropriate existing user-agent groups, and add the forum sitemap line. Preserve the parent site's rules; do not overwrite its file or add a competing rewrite. If you maintain a static merged file, review it again whenever VonSEO's robots settings change.
- **No access to the host root:** ask the host/site owner to apply the mapping or merge the crawler directives. The native sitemap URL still works, but putting robots text only inside `/forum/` does not solve host-root discovery.

With the first approach, `/robots.txt` serves the current dynamic VonSEO rules internally; visitors do not need to follow a redirect to `/forum/robots.txt`. The mapping only matches `/robots.txt` and does not route other parent-site URLs to MyBB.

### Block A: Clean crawler endpoints

These rules provide aliases for `misc.php?action=vonseo_sitemap` and `misc.php?action=vonseo_robots`. The native endpoints work without these aliases. Use the entire sitemap block, including its conditions, so supported child selectors such as `sitemap.xml?type=threads&page=1` keep working and unrelated query parameters cannot replace the internal action.

Both sitemap and robots modules must be enabled. A robots alias in the forum directory alone is not sufficient for a subfolder installation; complete Case B's host-root step.

### Block B: Keyword thread and forum URLs

Copy all six keyword rules as one block. Place them before the optional VonSEO fallback router. MyBB's existing thread/forum `.html` rules may remain in place.

Test in this order:

1. Leave **VonSEO keyword URLs** off.
2. Visit a known public `t-ID-topic`, `t-ID-topic--post-PID`, `t-ID-topic--lastpost`, paginated thread and `f-ID-forum` directly.
3. Each valid route should return a temporary redirect to the matching native MyBB URL. A 404 means the rewrite block is not active or is in the wrong file/location.
4. After every direct route works, enable **VonSEO keyword URLs** and repeat the tests. The keyword path should then remain canonical.

The ACP may report how many of the six patterns it finds in the root `.htaccess`, but file detection alone does not prove Apache serves them. Direct HTTP visits are the real test.

To roll back, turn the keyword setting off but keep the six rules while old keyword links may still exist. VonSEO will temporarily redirect those paths back to native MyBB URLs. Before deactivating or uninstalling VonSEO, replace any still-shared keyword paths with tested server redirects.

### Block C: Optional redirect and 404 fallback router

The final generic rule sends unknown pretty paths to `misc.php?action=vonseo_route`, allowing Redirect Manager and the custom 404 handler to process paths that do not map to a real PHP file.

- Place this block last, after MyBB's normal forum/thread rewrites and the six VonSEO keyword rules.
- Keep the supplied existing-file and existing-directory conditions so real assets and directories are not intercepted.
- This block is optional. Database redirects for requests that already reach MyBB can still work without it, but arbitrary extensionless paths need a server route to enter MyBB.

After merging, the structure should be conceptually similar to this:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Block A: VonSEO clean crawler aliases, if wanted
    # Block B: six VonSEO keyword rules, if wanted
    # Existing MyBB native rewrite rules remain here
    # Block C: VonSEO generic fallback router, always last if used
</IfModule>
```

Do not add `/forum/` to the beginning of the rule patterns when `.htaccess` is already inside the `/forum` directory. Apache evaluates those patterns relative to that directory.

### Verify and roll back Apache changes

Open the following URLs without logging in; use a browser's Network panel or your host's HTTP checker to confirm the status:

| Check | Root board | Board in `/forum/` | Expected result |
| --- | --- | --- | --- |
| Sitemap | `/sitemap.xml` | `/forum/sitemap.xml` | HTTP 200, valid XML sitemap index |
| Thread sitemap | `/sitemap.xml?type=threads&page=1` | `/forum/sitemap.xml?type=threads&page=1` | HTTP 200, valid XML URL set when this chunk exists |
| Forum robots alias | `/robots.txt` | `/forum/robots.txt` | HTTP 200, plain text, not an HTML error/login page |
| Crawler-standard robots | `/robots.txt` | `/robots.txt` | HTTP 200, appropriate rules for the whole host and the forum sitemap line |

Raw XML and plain text are intentional; these are not normal forum pages. Also open the homepage, a known public thread/forum, calendar, Admin CP and an existing image or JavaScript asset. They must retain their previous behavior. Keep keyword URLs off until the direct-route checks in Block B pass.

The ACP subfolder reminder is static guidance, not an HTTP probe of `/robots.txt`. It may remain visible after a successful host-root setup. Verify the actual URL and content; do not keep replacing a working configuration to make that reminder disappear.

If you get HTTP 500, restore the affected `.htaccess` backup and check the Apache error log or ask your host. If a clean alias returns 404 but the native `misc.php?action=...` endpoint works, check the file location, rewrite placement, `mod_rewrite` and the host's override permissions.

To undo crawler aliases, remove only the blocks you added, or restore a backup that retains all still-needed keyword/MyBB routes. If you created a host-root `.htaccess` containing only the robots block, it can be removed; if the file already had other rules, preserve those and remove only the new block. Restore a backed-up static robots file if you chose that approach. Test the parent site and forum again. For keyword rollback, turn the setting off but keep all six keyword rules while shared URLs still exist; do not uninstall VonSEO as a shortcut.

## Using the Nginx examples

Nginx does not read `.htaccess`. These files are snippets for an existing `server {}` block, not complete server configurations:

- `extras/nginx-vonseo.conf`: MyBB installed at the domain root, for example `https://example.com/`.
- `extras/nginx-vonseo-subfolder.conf`: MyBB installed at `/forum/`, for example `https://example.com/forum/`. Replace **every** `/forum/` in the snippet if your directory has a different name.

1. Back up the current Nginx site configuration. Use only the one example matching your installation.
2. Keep the site's existing PHP/FastCGI handler, TLS configuration, access restrictions and native MyBB friendly-URL rules. The examples assume the Nginx `root` points to the web-document root; they are not designed for an `alias` deployment.
3. Copy the clean sitemap/robots locations and all six keyword rewrites into the board's `server {}` block. The keyword rules must run before any existing catch-all rewrite. Their final `?` is intentional: it discards incoming query parameters that could conflict with the route's IDs or action.
4. If you want arbitrary missing paths to reach Redirect Manager and the 404 handler, merge the supplied `try_files` fallback into the existing `location /` or `location /forum/`. Do not define the same location twice. Preserve any application's existing front-controller routing, and keep native MyBB routes ahead of the fallback.
5. Run `nginx -t`. Only after it succeeds, reload Nginx using your host's service controls or `nginx -s reload`. Managed hosting may require the host to apply these changes.
6. Test native PHP pages and assets, the clean sitemap/robots aliases, thread/post/action/page/forum routes and an unknown path as a guest. Follow the same keyword-off, keyword-on and rollback checks used for Apache above.

The `/forum/` example sends only paths inside that directory to MyBB. It does not take ownership of host-root `/robots.txt`. A separate commented example is provided for the site owner; if another application owns that file, merge the forum's crawler directives into the parent site's file instead.

The ACP chooses guidance from the reported server software. A reverse proxy can obscure the real rewrite server, so that label is guidance, not proof that a route is configured. Direct visits on your deployment remain required.

## Upgrade

1. Back up the MyBB database, server rewrite configuration and a VonSEO redirect CSV export.
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

Keyword URLs for public forums and threads are available as an opt-in setting. They use stable IDs and flat paths, for example `t-42-sample-topic`, `t-42-sample-topic--post-105#pid105`, `t-42-sample-topic--lastpost` and `f-4-general--p2`; flat paths avoid breaking MyBB's relative links on direct visits. Add the matching Apache-style or Nginx rules from `extras/` and verify direct visits **before** enabling the setting. Canonical, schema, sitemap, IndexNow, ordinary forum/thread navigation, page links, exact `tid + pid` post targets and MyBB's stock `lastpost`, `newpost`, `nextnewest` and `nextoldest` actions on reviewed public pages use the keyword family. Post-only URLs without a thread ID, display modes, sort/filter links, archive/print variants and protected content keep their native behavior. Other page types and custom themes may still emit stock links. Extra query parameters on keyword routes are discarded by the supplied server rules so they cannot override the path's IDs or action.

### Keyword URL migration and rollback

1. Back up the database, server rewrite configuration, and any redirect CSV. On a staging copy, retain MyBB's own `.html` rules and add the six VonSEO keyword rules **before** the generic fallback rule. Directly visit a known public `t-ID-slug`, `t-ID-slug--post-PID`, `t-ID-slug--lastpost` and `f-ID-slug` while the setting is still off; each should temporarily redirect (`302`) to the matching MyBB URL. If any returns 404, fix rewrite placement before enabling the setting.
2. Enable **VonSEO keyword URLs** in Admin CP → Configuration → Settings → VonSEO. Ordinary public MyBB URL aliases temporarily redirect (`302`, `Cache-Control: no-store`) to the keyword canonical. Previously shared `t-ID-old-slug` / `f-ID-old-slug` URLs permanently redirect (`301`) to the current slug, retaining a valid page number. No VonSEO URL database migration is needed because the numeric ID remains stable across title changes. Missing, unapproved, moved and guest-inaccessible entities are not redirected into public URLs.
3. Check forum index, forum list, thread, pagination, sitemap and canonical output as a guest; also check exact post targets, all four stock thread actions, display/filter modes and post-anchor links. Do not run another plugin's URL or canonical module alongside VonSEO. Existing third-party SEO URL schemes **are not automatically imported**: export or map those old paths, inspect collisions and loops, then import exact redirects with VonSEO's Redirect Manager CSV on staging before switching URL ownership.
4. To roll back, switch the keyword setting off while leaving the matching server rules and VonSEO active. Keyword visits then return a non-cacheable `302` to MyBB's current URL; stock MyBB links/canonicals are restored. If you deactivate/uninstall VonSEO, the rules alone can still serve keyword paths but cannot issue these rollback redirects, so replace them with tested server redirects before removing the plugin. Do not remove the rules while old keyword links may exist.

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

The list shows 50 rules per page. **Search URL** searches both the source and destination as literal text, so characters such as `%` and `_` are not wildcard commands. Combine it with **Response** and **State**, then press **Apply**. Choose **Sort by** and **Order**, or click a sortable table heading on a wide screen. **Reset** removes filters. Page links preserve your search and sorting; the summary totals above remain board-wide rather than filtered totals.

CSV import/export uses this header:

```csv
source_path,target_url,status_code,enabled
/old-thread,/new-thread,301,1
/retired-page,,410,1
```

Imports are limited to 2 MiB and 5,000 data rows. Export before a bulk change. Use overwrite only when an existing exact source should be replaced.

To import:

1. Open **Redirects → Import CSV**, then upload a UTF-8 CSV file or paste its contents. If a file is selected, the pasted text is ignored.
2. Leave **Overwrite Existing** at **No** to keep existing source paths unchanged, or choose **Yes** deliberately to update matching rules.
3. Submit once. VonSEO redirects back to the import screen and shows **Last import result**, with created, updated and skipped counts. Refreshing the resulting page does not run the import again.
4. Review the warning table. Missing columns, invalid sources and rejected rules include a CSV row number and reason; whole-file errors appear as **File / format**. Numbers count parsed CSV records, including the header and blank records, rather than physical text lines. A quoted multiline record counts once.
5. Correct and re-import only rejected records. Row validation is not an all-or-nothing transaction: other valid rules may already have been saved. Existing sources skipped because overwrite is **No** do not produce a warning.

The result displays the first 50 warnings and the total warning count. Long reasons are shortened. It is scoped to the administrator session that submitted the import, shown once, and expires after 15 minutes; it is not permanent import history. Save the information you need before leaving or refreshing the result screen. The report does not retain the full uploaded CSV.

### Using Redirect Inspector

1. Open **Redirects → Inspect redirects**, or press **Inspect** beside a saved rule.
2. Enter a board-relative source such as `/old-thread?ref=1`, or its full URL inside MyBB's configured **Board URL**. On a `/forum/` installation, `/old-thread` means `https://example.com/forum/old-thread`, not the domain-root path. Ordinary query strings match exactly; fragments do not participate in matching. A query consisting only of `0` (for example `/old-thread?0`) is a legacy normalization exception and is flagged for a live check.
3. Choose **GET** for a normal page visit, **HEAD** for a header request, or another listed request method to check the redirect policy. VonSEO skips 301/302 rules for methods other than GET and HEAD; 307/308 preserve the method.
4. Press **Inspect saved rules**. Review the ordered source, response/state and destination rows. **Review rule** opens the saved rule's edit form; inspection itself does not save changes.

The inspector reads at most 12 exact rule lookups. It reports missing rules, disabled rules, 410 responses, method restrictions, unsafe destinations and loops. A chain longer than the lookup budget is reported as **Inspection limit reached**, not assumed to be a loop. If only permanent redirects lead to an apparent chain end, it can suggest reviewing a shorter mapping; this is advice, not automatic flattening.

This is a saved-configuration diagnostic, not an HTTP crawler. It never visits a URL, increments a hit counter or writes to the redirect table. External destinations and same-origin destinations outside the configured forum path stop the trace. Dot segments or encoded path separators also stop inspection because live routing can normalize them differently. Source URLs with these ambiguous paths are rejected. With either SEO or redirects disabled, a warning identifies the result as a configuration preview.

Repeated board prefixes (for example `https://example.com/forum/forum/old-thread`) and queries consisting only of `0` produce **URL normalization needs a live check**. The existing frontend can strip a board prefix more than once or treat `?0` as an empty query, so the inspector does not claim an exact next match or suggest shortening such a chain. The displayed uncertain URL is retained; no frontend routing rule is changed by this diagnostic.

**No matching saved rule** does not mean HTTP 200, public visibility or no redirects elsewhere. Server rewrites, keyword URL migration, other plugins, guest permissions and the live destination response still need staging checks. Confirm those before manually shortening a chain.

### Using the 404 Monitor

The 404 Monitor records final public `404 Not Found` responses as normalized URL identities with aggregate hit counts. It does not store visitor IP addresses. Unknown, tracking and common secret-bearing query fields are discarded.

- Repeated hits increase the same row rather than creating visitor records.
- Use **Create Redirect** when a missing URL has a clear replacement.
- Clear one entry after resolving it, or clear all entries after a controlled test.
- Visiting the custom test endpoint `misc.php?action=vonseo_404` deliberately produces a 404 and can therefore appear in the monitor. That is expected; clear the test entry afterward.
- Disable **404 Monitor** if logging is unwanted while keeping **404 Status Guard** enabled for correct HTTP responses.

Use **Search URL** to find a missing path, choose hits or first/last-seen sorting, and press **Apply**. Results are paginated in groups of 50 with First/Previous/Next/Last controls. A search with no matches does not mean the log was cleared: press **Reset** to see all entries again. On narrow screens, the sort dropdown remains available when table headings are hidden.

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

For Apache, merge `extras/htaccess-vonseo.txt` at the **MyBB installation root**. A subfolder board may additionally use `extras/htaccess-vonseo-host-root.txt` at the **web-document root**, only when no existing robots owner must be preserved. Follow the separate root/subfolder procedures in [Apache setup](#apache-setup-using-the-extras-files); do not upload either example as a replacement for an existing `.htaccess`.

For Nginx, use `extras/nginx-vonseo.conf` or `extras/nginx-vonseo-subfolder.conf` as explained above.

The optional fallback router lets rules such as `/old-page` be handled by VonSEO even when that path does not map to a real MyBB PHP file. Put the VonSEO fallback **after MyBB's normal forum/thread rewrite rules**.

For a board installed below the host root, such as `https://example.com/forum`, the forum-root rule can serve `/forum/robots.txt` but not the crawler-standard host URL `/robots.txt`. The ACP's subfolder reminder remains even when you have configured the host-root route: it does not probe that URL. Custom `Crawl-delay` lines are preserved. AI training/scraper blocking is separate from the optional AI search-crawler toggle.

Submit the sitemap URL that is actually reachable on the public site. With no clean rewrite, submit `https://example.com/forum/misc.php?action=vonseo_sitemap`. With a tested clean route, submit the corresponding `sitemap.xml`. A sitemap helps discovery but does not guarantee indexing.

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
- database migration and IndexNow queue diagnostics, plus **Needs attention** messages for reported issues and a static host-root robots reminder on subfolder boards;
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

- [ ] VonSEO Overview reports the current version and schema; reported issues are resolved, and any persistent subfolder robots reminder has been verified against the actual host-root URL.
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
- On Nginx, merge the matching root/subfolder snippet and test the configuration before reloading. On LiteSpeed, confirm Apache-style rewrite loading is active. Test direct routes on the actual host in either case.

### Duplicate canonical or social tags

Keep **Own SEO Meta Tags** enabled so VonSEO removes matching theme/plugin tags before inserting its output. If another extension must own these tags, disable the conflicting module and verify raw HTML rather than the browser inspector alone.

### Sitemap or robots endpoint is forbidden

Confirm VonSEO and the matching sitemap or robots setting are enabled. Test the native `misc.php?action=...` endpoint before debugging clean rewrite rules.

### `/forum/robots.txt` works but `/robots.txt` does not

The forum's `.htaccess` does not own the host root. Follow [Case B: MyBB in a subfolder](#case-b-mybb-in-a-subfolder) to merge the dedicated host-root example into the parent `.htaccess`, or preserve and update an existing parent robots file. VonSEO does not perform this step automatically.

### The subfolder robots warning remains after setup

This reminder is based on the configured Board URL, not a live HTTP check. If the host-root `/robots.txt` returns HTTP 200 and contains the appropriate parent/forum rules and sitemap line, the reminder alone does not mean routing is broken.

### Clean sitemap or robots alias returns 404 or 500

Test the native `misc.php?action=...` endpoint first. If it works, a clean-alias 404 points to server routing: check the active `.htaccess` location, missing blocks, catch-all order and host override permissions. For HTTP 500, restore the affected backup and inspect the Apache error log before trying again. Do not uninstall the plugin to repair a server rule.

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

## Contributor checks

The source repository includes tests and build tools; the upload ZIP deliberately excludes them. With PHP installed, run `php scripts/check.php` from the repository root for syntax checks, the main regression suite, redirect target/save/import policy checks, ACP list/import-report/redirect-inspector tests and the ACP request matrix. These tests use disposable mocks and do not need an installed forum.

`php tests/acp_lists_mysql.php` additionally checks list queries on a real MySQL/MariaDB database using connection-local temporary tables. Supply `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` and `DB_DATABASE` in the environment. It does not create persistent forum tables. GitHub Actions defines PHP 7.1-8.5, MySQL/MariaDB and upload-package jobs; a configured workflow is not evidence that every matrix job has run successfully.

On Windows with Nginx and PHP-CGI installed, `tests/nginx_routes.ps1 -Nginx <nginx.exe> -PhpCgi <php-cgi.exe>` checks both supplied snippets against isolated route fixtures. It uses loopback ports 19081-19083 by default and stops only the helper processes it created. This validates server routing, not a complete installed MyBB deployment.
