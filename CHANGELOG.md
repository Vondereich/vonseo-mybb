# Changelog

## 1.0.0 - 2026-09-28

- Promoted the stabilized VonSEO feature set to 1.0: server-rendered canonical/meta/social/schema output, guest-safe forum/thread/announcement/calendar/event discovery, exact redirects, privacy-aware 404 handling, queued IndexNow delivery and opt-in reversible keyword URLs.
- Serialized admission of new 404 identities with a non-blocking MySQL/MariaDB advisory lock. Existing rows retain a lock-free hit-update path; crawler bursts can no longer race past the 10,000-row cap, and busy-lock telemetry is dropped without delaying the error response.
- Retained the 0.7 fail-closed IndexNow removal history, retry boundaries, logged-in public calendar/event identity, current-month canonical consolidation, corrected event sitemap dates and clean-route query allowlists.
- Escaped all five Site Details values before MyBB builds their settings updates, including quote-bearing names and URLs, and escaped every fresh plugin-setting value before insertion. Normal apostrophes now save correctly without becoming SQL syntax.
- Split sensitive ACP authority from redirect and 404 management. Site Details and IndexNow key rotation now require an additional VonSEO permission or MyBB's core settings permission; the server-side action gate and read-only UI state enforce the same boundary.
- Made unsupported database activation fail closed before any VonSEO setting, table, scheduled task or task-cache change. MyBB 1.8 ignores plugin callback return values, so VonSEO now explicitly stops the activation request on non-MySQL/MariaDB drivers.
- Added administrator audit entries for redirect CSV imports and individual 404 clears, removed duplicate Redirects PHPDoc blocks and refreshed the stale database-support comment.
- Removed local SQL/database snapshots and temporary session data from the source tree. The public package remains a fixed 26-file allowlist containing no tests, internal progress documents, local backups or credentials.
- Keyword URLs remain disabled on fresh installs. Existing third-party custom URL schemes require a site-specific migration, and Nginx/LiteSpeed require equivalent server rules.
- Source passes 272/272 self-contained tests, 32/32 ACP method/token/permission cases and lint of all repository PHP files on PHP 8.3-8.5. The local PHP 8.4/MySQL playground retains the earlier 21-case guest HTTP and focused 404 proof; the final lifecycle and quote-bearing Site Details checks are covered by the included regression suite.
- A late manual audit corrected the earlier pre-release conclusion: it found the authenticated ACP settings-write issue, the overly broad permission and the unsupported-database lifecycle defect above. All supplied findings are patched and regression-covered; the earlier whole-tree scan remains historical evidence rather than a claim that these later findings never existed.

## 0.7.0 - 2026-09-27

- Made IndexNow removals fail closed: a hidden or never-public thread can no longer have a slug reconstructed from private content. Only an explicitly captured pre-delete public URL or remembered public history may be submitted for removal.
- Added schema 5 with a separate `retry_not_before` boundary. New edits or replies retain active configuration, rate-limit and transient-failure backoff instead of resetting attempts or immediately resubmitting a hot thread.
- Preserved guest-public calendar and event canonical/schema identity for logged-in visitors without reusing member-rendered event text. Explicit current-month calendar URLs now consolidate onto the stable calendar base URL.
- Removed event sitemap `lastmod` values derived from MyBB's creation timestamp, and hardened clean Apache sitemap/robots rewrites so unrelated query parameters cannot replace their internal action.
- Expanded regression coverage for never-public moderation/removal paths, renamed hidden threads, concurrent failed generations, retry retention, logged-in calendar/event rendering, current-month canonical consolidation and event sitemap dates.
- Source passes 267/267 self-contained tests, 29/29 ACP action/method/token cases and lint of all 45 repository PHP files on PHP 8.3-8.5.
- Six changed plugin files were byte-matched into the local MyBB playground. The 0.6.0 to 0.7.0 activation upgrade, deactivate/reactivate cycle, schema 5/5 diagnostics and ready queue state passed through the real ACP.
- This is an unpackaged development release. The latest verified public ZIP remains 0.6.0; live rewrite, moderation-hook and outbound IndexNow verification are still required before packaging 0.7.0.

## 0.6.0 - 2026-09-27

- Promoted the broader-content release after source and focused installed checks for announcements, guest-visible monthly calendars and public events. Canonical, metadata, schema and sitemap output share MyBB's native URL and guest-permission behavior.
- Retained the 0.5 hardening baseline: reversible opt-in keyword URLs, moderation-aware queued IndexNow delivery, exact redirects, bounded privacy-aware 404 logging, protected ACP mutations and simplified Site Details.
- Source passes 256/256 self-contained tests, 29/29 ACP method/token cases and lint of all 45 repository PHP files on PHP 8.3-8.5. The public archive is restricted to the 26-file upload manifest and is verified byte-for-byte during creation.
- This remains pre-1.0 software. Profile sitemaps, automatic legacy custom-scheme migration, broader production theme/permission testing, public Rich Results/Search Console proof and a current whole-tree security scan remain outside this release proof.

## 0.6.0-alpha.2 - 2026-09-27

- Added first-class SEO for guest-visible MyBB monthly calendars and public events using MyBB's native URL helpers and render hooks. Monthly calendars receive canonical/title output and `CollectionPage` schema; public events receive guest-rendered descriptions, bounded `Event` schema and calendar breadcrumbs.
- Added calendar and paginated event sitemap entries. Guest group calendar overrides are respected; private, unapproved and guest-denied events are excluded. Event chunks return 404 before a deep offset query when a requested page is out of range.
- Kept calendar day, week and utility views noindex to avoid duplicate archive surfaces. Event schema uses only real stored times and the visible organizer name when present; it does not fabricate location, offers or Google rich-result eligibility.
- Extended missing-entity 404 handling and privacy-preserving query identity to native calendar/event routes.
- Expanded the self-contained suite to 256 passing cases covering calendar/event URL resolution, guest captures, private/unapproved/denied states, schema, sitemap discovery/filtering, 404 identity and out-of-range chunks. All 45 repository PHP files and the 29-case ACP mutation matrix pass on PHP 8.3-8.5.
- Focused local MyBB 1.8.41 checks passed for the native pretty monthly calendar, day-view noindex, a temporary public event canonical/rendered description/schema/sitemap, private 404 and unapproved 403 states. The event fixture was deleted and its URL plus empty sitemap chunk returned 404 afterward.
- This is a source alpha only. The latest verified public ZIP remains 0.5.0-beta.6; installed calendar/event theme-permission QA, profile sitemaps, legacy custom-scheme migration and public rich-result validation remain pending.

## 0.6.0-alpha.1 - 2026-09-27

- Added first-class SEO for active public MyBB announcements. Announcement pages now receive a shared canonical URL, page title, guest-rendered description, Open Graph/Twitter article metadata and breadcrumb-aware `Article` JSON-LD without reading raw MyCode into public metadata.
- Added paginated announcement sitemap chunks to the existing sitemap index. Global announcements and announcements assigned to guest-visible forums are included only while active; expired, future, password-protected, inactive and otherwise guest-inaccessible forum announcements are excluded.
- Added the MyBB `postbit_announcement` integration and reused the same URL, forum-permission and board-identity engines as forum/thread SEO. No MyBB core edit, new setting or database migration is required.
- Kept announcement count caching on one scope-aware cache row instead of deriving a new key from the changing current timestamp.
- Added explicit local post-token checks to every VonSEO ACP mutation as defense in depth above MyBB's global ACP guard. The mutation harness now covers CSV import plus rejected tokens for all seven actions without database writes.
- Removed the unused `vonseo_path` Apache fallback parameter. Redirect preview links now resolve entered source/destination URLs without pretending to simulate an unsaved rule, and the guide identifies the Overview, raw XML and plain-text destinations explicitly.
- Expanded the self-contained suite to 238 passing cases covering active/global/protected/future/expired announcements, rendered-text privacy, schema/social output, sitemap filtering, stable count caching, JSON-LD tag-boundary escaping, ACP hardening and out-of-range chunks. All repository PHP files and the 29-case ACP mutation matrix pass on PHP 8.3–8.5.
- This is a source alpha only. The latest verified public ZIP remains 0.5.0-beta.6; calendar/event SEO, profile sitemaps and legacy custom-scheme migration are still pending.

## 0.5.0-beta.6 - 2026-09-27

- Completed IndexNow moderation lifecycle coverage for thread/post approval, unapproval, restore, soft delete and permanent delete. Unapproved replies do not ping; public content removals queue either the current parent thread or a safely captured prior public URL.
- Added a five-minute per-thread delivery cooldown for reply/edit bursts while new, approved and restored threads remain immediately eligible. Concurrent generations survive an in-flight delivery and are deferred instead of being submitted again at once.
- Classified IndexNow configuration errors, HTTP 400/403/422, HTTP 429 with `Retry-After`, timeouts and HTTP 5xx. Configuration/rate-limit failures retain queued work without consuming retry attempts; transient remote failures keep bounded exponential retry behavior.
- Added concise ACP worker diagnostics: Ready, Config Error, Rate Limited or Remote Error, queue depth, last success and the human-readable last failure. A protected POST action replaces the IndexNow key on demand; no old verification file remains.
- Added one compact Site Details form inside VonSEO. Board Name/URL and Homepage Name/URL read and write MyBB's original settings instead of duplicate plugin copies; Homepage identity feeds safe Organization publisher schema, while email/contact/cookie fields stay excluded. SEO Description is edited only there, and the retired Homepage SEO Title override is removed so the homepage title always follows MyBB's Board Name.
- Moved redirect schema checks out of the public-request hot path, added resolved-page titles for forum and portal pagination, and trimmed MyBB identity before Open Graph and schema output.
- Advanced the durable schema to version 4 with moderation event type and per-thread public URL/submission history. Uninstall removes both IndexNow tables; existing settings, redirects and queue work are preserved during upgrade.
- Expanded regression coverage for moderation transitions, private/unapproved filtering, removal delivery, queue races, cooldowns, failure classes, pagination titles, identity trimming, Site Details URL rejection and the new ACP POST actions.

## 0.5.0-beta.4 - 2026-09-27

- Fixed scheduled-task lifecycle: installation no longer starts work before activation; activation repairs/enables the IndexNow task, deactivation disables it, uninstall removes it and its logs, and every transition refreshes MyBB's task cache. The worker also verifies the plugin is active and no longer writes idle logs.
- Moved schema versioning to a durable metadata table and split operational cache fields so concurrent 404, redirect and IndexNow updates cannot overwrite one another. Schema 3 upgrades the IndexNow queue to thread IDs plus generation tokens.
- Reworked IndexNow delivery to revalidate guest visibility and resolve the current thread slug immediately before submission. Deleted/private rows are dropped, fresh concurrent generations are preserved, retries reset on new content and stop after 10 failures.
- Fixed forum and portal canonicals to trust MyBB's resolved page, and changed dynamic HTML replacements to callbacks so literal `$0`, `$1` and backslash tokens cannot corrupt the document head.
- Tightened privacy and correctness: 404 logs retain only route-specific public query fields; only known missing-entity errors become HTTP 404; unknown sitemap types return 404; link forums are excluded; thread counts use a short cache.
- Hardened maintenance paths with 2 MiB/5,000-row streamed CSV parsing, quoted multiline handling, missing redirect-ID rejection and a 10-thread/post fallback lookup bound.
- Corrected crawler controls: subfolder-aware default paths, host-root robots guidance, preserved custom `Crawl-delay`, separate AI training/scraper and AI search toggles, and a dedicated Organization logo setting.
- Expanded the self-contained suite to 193 passing cases covering the original race/corruption triggers, queue generations/retry cap, lifecycle, privacy, pagination, sitemap, CSV and crawler behavior.

## 0.5.0-beta.3 - 2026-09-25

- Completed a wider MyBB thread-link pass after the exact-post fix. Public `lastpost`, `newpost`, `nextnewest` and `nextoldest` links now use keyword forms such as `t-1-topic--lastpost` on the forum index, forum display and thread pages.
- Added a sixth bounded Apache route for those four named actions. Arbitrary actions, display modes, filters, moderation URLs and post-only links are not accepted by the keyword action parser.
- Added native/query/pretty/current/rollback coverage for all four actions and retained the guest-visible thread gate. Incoming query parameters remain discarded by the keyword rewrite.
- Added an early public-entity redirect so directly visited native action aliases migrate to their keyword action URL before MyBB executes the action; current keyword actions then retain MyBB's original navigation behavior.

## 0.5.0-beta.2 - 2026-09-25

- Made exact public thread-post links consistent with the keyword URL family. MyBB links such as `thread-2-post-3.html#pid3` and `showthread.php?tid=2&pid=3#pid3` now render as `t-2-topic--post-3#pid3` when keyword URLs are enabled.
- Added a fifth Apache route for keyword post targets. The route retains both thread and post IDs, MyBB still calculates the correct containing page, and the page canonical remains the ordinary first or paginated thread URL.
- Added guest-safety validation before rewriting a post target: the post must be approved and belong to the public thread. Dynamic `lastpost`/`newpost` actions and post-only URLs remain native.
- Fixed canonical pagination when MyBB reaches a thread through a `pid` and calculates the containing page internally.

## 0.5.0-beta.1 - 2026-09-25

- Added an idempotent schema-version migration runner for MySQL/MariaDB. Fresh installs and beta upgrades create/repair the redirect, 404 and IndexNow queue tables without resetting saved settings or redirect data.
- Replaced synchronous IndexNow hook requests with a deduplicated queue processed by a one-minute MyBB task. Failed batches remain queued with bounded exponential backoff; ACP diagnostics show queue depth and last result.
- Made manual redirects exact for query-bearing URLs, strengthened same-origin validation to include scheme and effective port, and added a zero-rule cache short-circuit on public requests.
- Preserved meaningful native MyBB query identity in the 404 monitor while redacting common secret-bearing values. Logging now uses an atomic upsert and bounded low-value pruning instead of a race-prone select/insert ceiling.
- Corrected crawler behavior: disabled sitemaps are no longer advertised in robots output, and out-of-range thread sitemap pages return HTTP 404 without deep-offset queries.
- Added compact ACP **Needs attention**, schema and IndexNow queue diagnostics. Keyword URLs remain opt-in; no calendar/event module or MyBB core edit was added.
- Expanded the self-contained regression suite for migrations, queue success/retry, redirect origin/query rules, 404 privacy/pruning, robots and sitemap status behavior.

## 0.4.0-beta.2 - 2026-09-24

- Simplified the native MyBB Admin CP overview to show basic SEO and keyword URL status first. The default settings do not need to be filled in one by one; setup/rollback help and technical diagnostics can be expanded only when needed.
- Added a separate read-only **How to use** tab with a short guide to default SEO, optional keyword URLs, crawler checks, redirects, 404 monitoring and IndexNow. The root `.htaccess` file check is not presented as proof that Apache serves the routes.
- Relabelled the unrelated custom-path fallback as optional, so its absence no longer appears to be a keyword URL failure.
- Made VonSEO overview, redirect and 404 tables readable in narrower ACP windows without horizontal scrolling. SEO output, URL routing and stored settings are unchanged.
- Removed a PHP 8.4/8.5 deprecation warning from the keyword URL helper without changing its behavior.

## 0.4.0-beta.1 - 2026-09-24

- Completed the opt-in public forum/thread keyword URL layer: ordinary MyBB navigation and pagination now agree with canonical, schema, sitemap and IndexNow URLs. Special post/action and sort/filter links remain native.
- Added permission-aware redirects for renamed keyword slugs and page-one normalization. Stock MyBB aliases use non-cacheable temporary redirects while this beta remains reversible; disabling the setting temporarily redirects old keyword visits back to stock URLs without a browser-cached loop.
- Tested actual Apache rewrites and a disposable MyBB 1.8.41 database at both root and subfolder paths, including direct visits, missing IDs, stale slugs, UTF-8 rewrite, passworded access and rollback. Existing third-party SEO URL schemes still require a site-specific, reviewed CSV migration.
- Kept keyword URLs disabled on fresh installs. No MyBB core-file edits or calendar/event SEO were added.

## 0.4.0-alpha.2 - 2026-09-24 (source only; ZIP not built)

- Capture the full original post text after MyBB renders it for a guest, then use that text for the first-page `DiscussionForumPosting` schema and a short meta description. Do not read raw first-post MyCode for these fields. If the public first post was not rendered, omit incomplete first-page posting schema.
- Keep the structured-data posting URL/ID on the first thread page even when the current page has its own paginated canonical. Do not label a default social image as an inline post image, and use the original post's edit time rather than the latest reply time for `dateModified`.
- Add mock regression checks for guest/member/unapproved/passworded capture, full-text schema, default-image exclusion, stable paginated URL and source version. Real MyBB/theme and Google Rich Results Test validation remain pending.

## 0.4.0-alpha.1 - 2026-09-24

- Added opt-in, flat, ID-based keyword URLs for public forum/thread canonical, schema, sitemap and IndexNow output, plus public forum-list thread-title links, with optional Apache routes and stock-URL fallback. Flat paths preserve MyBB's relative links; the keyword routes discard incoming query strings to prevent ID overrides. This is an experimental preview, disabled by default.
- Consistently exclude forums and threads beneath password-protected parent forums from guest-oriented SEO output; keep the main listing link stock for those rows and avoid adding protected entity titles to access pages. Malformed parent chains fail closed. Thread canonical slugs use MyBB's raw cached subject so display badword filtering cannot desynchronize URLs.

## 0.3.0-beta.1 - 2026-09-24

Stability and security beta. This is not the 1.0 release.

- Made ACP redirect saves POST-only and bounded 404 monitoring and sitemap pagination.
- Expanded regression coverage for public-only sitemap/IndexNow output, ACP mutations and plugin lifecycle.
- Fixed optional SearchAction URL joining, blank IndexNow key handling, stream-fallback success detection, redirect hit recording and blank CSV feedback.
- Resolved PHP 8.4 CSV/nullable deprecations and refined the Admin CP layout.
- Disabled legacy SearchAction by default on new installs while preserving existing settings.
- Aligned plugin metadata and IndexNow user-agent version. No keyword/slug URL or calendar/event module is included.

## 0.2.0-beta.1 - 2026-09-22

Modern SEO, AI Crawler Controls, IndexNow Engine & CSV Tools.

- Added Google Sitelinks Searchbox JSON-LD (`SearchAction`) on board homepage.
- Added `Organization` publisher schema for enhanced entity authority.
- Enriched `DiscussionForumPosting` with `interactionStatistic` (`ReplyAction` & `ViewAction`), `articleSection`, and ISO-8601 UTC date formats.
- Added Open Graph article tags (`article:published_time`, `article:modified_time`, `article:section`, `article:author`) for thread views.
- Added AI Crawler & LLM Scraper directives (`GPTBot`, `ClaudeBot`, `PerplexityBot`, `Google-Extended`, `Bytespider`, `CCBot`) in `robots.txt` with ACP toggle.
- Added `VonSEO_IndexNow` real-time instant indexing module for automatic submission to Bing, Yandex, Seznam, and Naver.
- Added IndexNow automatic submission on thread creation, new reply, and post update hooks.
- Added IndexNow key verification endpoint (`misc.php?action=vonseo_indexnow_key`).
- Added CSV Import and Export for redirect rules in Admin CP with overwrite protection.
- Added comprehensive IDE static analysis stubs (`extras/ide_stubs.php`) for 100% clean type safety.
- Updated author and website repository information to Vondereich.

## 0.2.0-alpha.1 - 2026-09-22

Redirect/error foundation and Admin CP integration build.

- Added `VonSEO_Redirects` with exact-path 301/302/307/308/410 rules.
- Added source hash lookup, rule hit counters and first/last hit timestamps.
- Added save-time and runtime redirect loop protection.
- Added external-target security gate; external redirects are off by default.
- 301/302 are skipped for non-GET/HEAD requests; 307/308 remain method-preserving.
- Added `VonSEO_Errors` for 404/403/410 response correction and noindex headers.
- Added privacy-safe 404 aggregation without IP storage.
- Added custom 404 endpoint and optional Apache fallback router.
- Added ACP VonSEO overview, health state, Redirect Manager and 404 Monitor.
- Added one-click redirect creation from a recorded 404 path.
- Added optional `.htaccess` rule detection in ACP.
- Error/permission pages no longer inherit private entity title/schema/social output.
- Added admin module and admin language scaffold.

## 0.1.0 - 2026-09-22

Initial Core build.

- modular MyBB 1.8 plugin architecture;
- trusted `bburl` canonical URL resolver;
- duplicate-slash and subfolder normalization;
- homepage/forum/thread/profile/portal context resolver;
- guest-permission indexing baseline;
- thread first-post meta descriptions;
- canonical and robots metadata;
- Open Graph and Twitter Cards;
- first-image-attachment social fallback;
- JSON-LD for homepage, forum, thread and optional profile pages;
- XML sitemap index with public forum/thread filtering;
- dynamic robots output;
- optional Apache clean crawler routes.
