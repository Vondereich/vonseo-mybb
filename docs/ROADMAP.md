# VonSEO MyBB Roadmap

Last updated: 2026-10-12.

Current stable release: **1.0.8**. Development build: **1.1.0-beta.5 - SEO Controls & Preview**.

This roadmap separates released behavior, the agreed next-release scope and deferred candidates. The 1.1.0 scope is implemented in a development beta, not stable-release approved. No release date or search-ranking result is promised. Historical verification below describes those builds, not every deployment of the current plugin. See [CHANGELOG.md](../CHANGELOG.md) for individual maintenance changes.

## Released milestones

### v0.1 Core: implemented

- Modular plugin architecture
- Trusted `bburl` URL resolver
- Page context resolver
- Dynamic titles/descriptions
- Canonical URLs
- Robots meta
- Open Graph / Twitter Cards
- JSON-LD
- Public-only sitemap
- Dynamic robots output
- Subfolder-safe URL normalization

### v0.2 Redirect & Modern SEO Foundation: beta.1 implemented

- Database-backed redirect rules (301 / 302 / 307 / 308 / 410)
- Redirect hit counters & loop/self-redirect protection
- Real 404 status guard & 403 permission guard
- 404 monitor with aggregated URL paths
- ACP VonSEO health page, Redirect Manager and 404 Monitor
- CSV Import and Export for redirect rules with overwrite support
- Optional legacy `SearchAction` JSON-LD schema (Google retired the Sitelinks Search Box result)
- `Organization` publisher identity in schema
- Enriched `DiscussionForumPosting` with `interactionStatistic` (`ReplyAction`/`ViewAction`) and `articleSection`
- Open Graph article tags (`published_time`, `modified_time`, `section`, `author`)
- AI bot crawler control in `robots.txt` (GPTBot, ClaudeBot, PerplexityBot, etc.)
- IndexNow change notifications and posting hooks; delivery does not guarantee indexing
- Optional Apache fallback router for legacy pretty URLs

### v0.3 Stability and security beta: packaged

- Preserve the current MyBB-native URLs; no new slug or calendar/event SEO module in this beta.
- Regress the existing security fixes, public-only output, redirect/404 behavior, and plugin lifecycle.
- Align code, documentation, and beta package version; identify installed MyBB/Apache/IndexNow QA gaps explicitly.

### v0.4 Keyword URL beta: packaged

- Opt-in ID-based public thread/forum keyword URLs without MyBB core edits; still disabled by default.
- Canonical, schema, sitemap, IndexNow, ordinary navigation and pagination share the URL resolver. MyBB actions and filtered links stay native.
- Local Apache/MyBB 1.8.41 root and subfolder direct visits, stale-slug redirects, native alias migration, password access and temporary rollback passed. Native aliases use non-cacheable 302 while rollback is supported; stale keyword slugs use 301.
- Existing third-party custom URL databases and rewrite schemes still need a site-specific inspected redirect import. The beta is not production approval or a search-indexing guarantee.

### v0.5 Stability & diagnostics beta: packaged

- Versioned, idempotent MySQL/MariaDB migrations repair required tables/task while preserving settings and redirect data.
- IndexNow posting hooks enqueue only; a MyBB task batches delivery and retries failures with bounded backoff.
- Query-bearing manual redirects match exactly, and same-origin checks include scheme and effective port.
- 404 identities preserve useful MyBB query values, redact common secrets, use atomic upserts and prune low-value rows after the fixed safety cap.
- Robots output respects a disabled sitemap; out-of-range sitemap chunks return 404 without deep-offset queries.
- ACP adds concise migration/queue state and a Needs attention summary. This milestone does not add calendar/event SEO or automatic legacy URL-database migration.
- At beta.2, approved exact thread-post links joined the keyword family while retaining the post ID/anchor and MyBB-calculated containing-page canonical; dynamic action and post-only links still remained native at that point.
- Beta.3 completes the wider stock thread-link pass: `lastpost`, `newpost`, `nextnewest` and `nextoldest` use an explicit keyword action allowlist, while display/filter/moderation and post-only links remain native.
- Beta.4 hardens replacement handling, durable state and migrations, IndexNow queue re-resolution/races/retry caps, error-query privacy, sitemap/robots behavior, CSV import bounds, secure key generation and task lifecycle cleanup. Source and extracted package passed 193 tests plus the 12-case ACP matrix on PHP 8.3–8.5; live ACP upgrade, deactivate/reactivate, uninstall and fresh reinstall passed on the local MyBB playground.
- Beta.6 completes moderation-aware IndexNow queueing, a five-minute same-thread cooldown, generation-safe delivery and classified failures; removes redirect schema work from ordinary page requests; adds forum/portal page titles; and consolidates MyBB identity plus the VonSEO description into one Site Details form. Source and extracted package pass 218 tests plus the 19-case ACP matrix on PHP 8.3–8.5.

### v0.6 Broader content SEO: 0.6.0 packaged

- Active public announcement pages now receive canonical/title/description output, article social metadata and breadcrumb-aware `Article` JSON-LD from MyBB's guest-rendered announcement text.
- The sitemap index now advertises paginated announcement chunks containing only active global announcements or announcements assigned to guest-visible forums.
- Future, expired, passworded, inactive and otherwise guest-inaccessible forum announcements fail closed. No MyBB core edit, new database table or announcement URL rewrite is used.
- Version 0.6.0 adds guest-visible monthly calendar canonicals and `CollectionPage` schema, plus public event canonical/description/`Event` schema and calendar/event sitemap coverage. Private, unapproved and guest-denied events fail closed; day/week/utility views remain noindex.
- Source and extracted package pass 256 tests, the 29-case ACP method/token matrix and PHP 8.3-8.5 lint. Focused live checks passed for the native monthly calendar, day-view noindex and a temporary public/private/unapproved event lifecycle; broader themes and calendar permission overrides remain pending. The 26-file public ZIP is byte-matched and excludes internal project material.
- Profile sitemaps are the next content-coverage gap. Legacy custom-scheme migration remains a separate operational gap.

### v0.7 Pre-RC hardening: completed

- IndexNow removal URLs fail closed to captured or remembered public history; never-public hidden content cannot be reconstructed into a crawler notification.
- Schema 5 separates content eligibility from retry backoff so new activity cannot bypass configuration, rate-limit or remote-failure pauses.
- Public calendar/event identity remains consistent for logged-in visitors, explicit current-month URLs consolidate to the calendar base, and event sitemap entries no longer claim creation time as modification time.
- Clean Apache sitemap/robots rewrites allowlist supported selectors and discard unrelated action collisions. Nginx and LiteSpeed require equivalent site-specific server rules.

### v1.0 Stable foundation: released

- Version-aligned 1.0.0 package with a fixed 26-file public manifest and no tests, private backups or internal progress documents.
- Keyword URLs remain optional and disabled by default; native MyBB URLs continue to work, and rollback behavior remains available while VonSEO and its server rules are present.
- Source and extracted-package checks pass on PHP 8.3, 8.4 and 8.5: 272 self-contained tests, 32 ACP request/token/permission cases, and PHP lint.
- The local MyBB 1.8/PHP 8.4 playground passed the 21-case guest HTTP smoke, schema 5/5 diagnostics, queue readiness and focused 404 aggregation proof.
- A later manual audit found and closed an authenticated ACP settings-write issue, an overly broad permission and an unsupported-database activation defect. These changes have focused regression coverage; historical scans are not a guarantee for later changes or every deployment.

### v1.0.1 Maintenance: implemented

- Search, response/state filters, sorting and 50-row pagination for ACP redirect lists; search, sorting and pagination for the 404 monitor.
- Root/subfolder Nginx snippets and server-aware ACP instructions. Nginx fixture routing was exercised locally; a complete installed Nginx/MyBB deployment and LiteSpeed runtime remain separate compatibility checks.
- Portable contributor verification runner and GitHub Actions definitions for PHP 7.1-8.5, MySQL/MariaDB and public-package parity. The 1.1.0 test branch subsequently passed the Linux PHP matrix, MySQL 8.0/MariaDB 10.11 database-layer checks and Windows package job; installed HTTP boards on every runtime/server are not claimed.
- No database migration or change to keyword/indexing defaults.

### v1.0.2 Keyword post-target maintenance: implemented

- Cache raw post data by post ID and recheck the requested thread ID plus approved status for every keyword link or redirect decision.
- Regression coverage for both link orders, private/unapproved/missing posts, the ten-post lookup budget and keyword URLs off.
- No database migration or change to keyword/indexing defaults.

### v1.0.3 CSV compatibility: implemented

- CSV import/export compatibility for PHP 7.1-7.3, retaining the newer-runtime behavior.
- Reject binary NUL bytes before parsing or saving imported redirects; retain file/row limits and quoted multiline handling.
- Portable keyword rewrite regressions for LF and CRLF source files. No rewrite or schema change.

### v1.0.4 ACP import feedback: implemented

- One-use, administrator-session-scoped CSV results with created/updated/skipped counts and bounded row-by-row warnings.
- Expiring feedback, escaped output and POST/redirect/GET behavior prevent accidental repeat imports on refresh.
- Remove the redundant top-level ACP global declaration. No schema or SEO-default change.

### v1.0.5 Redirect Inspector: implemented

- ACP-only, read-only inspection of saved rules, with ordered traces for response/state, destinations, loops and unsafe targets.
- Bound inspection to 12 indexed rule lookups; stop at external/outside-board or ambiguous destinations.
- Suggest reviewing shorter permanent redirect chains without applying changes, fetching URLs or incrementing counters. Automatic flattening is not implemented.

### v1.0.6 Inspector normalization boundaries: implemented

- Preserve uncertain input URLs and require a live check for repeated board prefixes, queries consisting only of `0` and related normalization ambiguities.
- Apply the same boundary to later chain destinations without changing frontend redirect behavior.

### v1.0.7 Redirect destination validation: implemented

- Validate resolved destinations before manual save or CSV import, including slash-prefixed absolute URL inputs.
- Preserve same-origin scheme/port checks, the external-redirect opt-in, existing records on invalid writes and runtime target revalidation.

### v1.0.8 Rendering and Portal maintenance: released

- Include Portal thread, last-post and forum anchors in opt-in keyword URL rewriting, with guest-public checks and the existing ten-thread/post fallback budgets. Portal retains its own native canonical.
- Use tag-aware head and anchor edits that preserve JavaScript, styles, comments, templates and body content; leave incomplete head boundaries unchanged.
- Validate social-image, avatar and Organization-logo URLs; invalid avatars retain the default-image fallback. No image URL fetch is performed.
- Use guest-visible, opt-in profile URLs for article authors and thread schema author URLs instead of emitting usernames as article URLs.
- Keep schema 5, keyword/profile indexing defaults and MyBB site-identity ownership unchanged. The public upload ZIP has a 33-file allowlist; tests and private material are excluded.

## v1.1.0 SEO Controls & Preview: beta in progress

The following scope has source implementation in 1.1.0-beta.5. The Inspector normalizes stock guest page ranges and distinguishes inactive engine candidates. Board-wide Guest gates now protect IndexNow and sitemap eligibility; calendar-specific grants cannot bypass global Guest calendar restrictions. Beta.4 closes the seven scoped correctness findings, including charset identity and concurrent-removal history follow-ups, with regression and isolated MySQL proofs. Beta.5 fixes the mobile Site Details overflow found during the release-gate pass. The Linux PHP matrix and native MySQL/MariaDB lifecycle checks have run successfully, and the real local restricted-account test passes 30 checks. Stable approval and publication still require the final-tree/package checks below. ACP automatic thread-description and schema snapshots do not execute the guest first-post renderer; the beta warns separately even when a description override is used. Management is ACP-only; URL schemes and existing site settings are not silently replaced.

### 1. Forum and thread SEO overrides

- Add optional SEO title, description and social-image overrides for individual forums and threads. Blank values retain existing automatic output and fallbacks.
- Do not change MyBB thread subjects, forum names, canonical URLs or keyword slugs. Preserve pagination context in generated SEO titles.
- Add an inherit/current-policy or `noindex` choice. An override may restrict indexing but must never make private, password-protected, inactive, deleted or unapproved content eligible.
- Define forum/thread indexing precedence before implementation and use the effective policy consistently in metadata, sitemap eligibility and IndexNow delivery/removal handling. Keep public URL navigation separate from indexing policy.
- Specify plugin-owned storage, migration and cache/query budgets before coding. Do not add fields to MyBB core tables or edit core files.
- Use a scoped ACP permission for override management, protected POST saves, input/output validation and admin action logging. Basic redirect/404 access alone must not imply permission to change entity SEO.

### 2. Read-only SEO Inspector

- Inspect a supported forum/thread by its board entity identity and show expected canonical, robots, description, social image and schema eligibility.
- Identify whether each value comes from an entity override, existing automatic content or a global fallback.
- Distinguish optional missing configuration, invalid configured values and permission/indexing exclusions. Do not expose private post excerpts or imply that a local preview proves crawler access.
- Bound lookups and keep inspection read-only. No arbitrary URL fetch, automatic full-site crawl, redirect hit update or IndexNow submission.
- Label expected resolved output separately from real HTTP/rendered-page verification; this tool is not a search-engine indexing status report.

### 3. Search and social previews

- Show desktop/mobile-style title, URL, description and social-image previews before saving overrides, using the same validation and resolution policy as frontend output.
- Label previews as illustrative, not pixel-exact or guaranteed search/social results. Google can choose different [title links](https://developers.google.com/search/docs/appearance/title-link) and [snippets](https://developers.google.com/search/docs/appearance/snippet).
- Do not create fabricated images, descriptions or structured-data facts merely to fill empty fields. Invalid image overrides must have explicit feedback and safe fallback behavior.

### 4. ACP guidance and existing-setting preservation

- Explain that Board Name/URL identify the forum while Homepage Name/URL may identify a separate parent site/publisher. Do not force the two pairs to match or automatically overwrite custom values.
- Make Default Social Image guidance clear: an empty optional field is not a proven code defect; a configured but invalid URL needs actionable validation feedback.
- Distinguish description-quality suggestions from syntax errors or release blockers. Keep one editable location for the homepage description and MyBB as the source of truth for existing identity settings.
- Update installation/upgrade guidance and this roadmap to reflect delivered behavior rather than promises or duplicated future items.

### Release gates before 1.1.0 stable

- Fresh install and 1.0.8-to-1.1.0 upgrade pass on supported MySQL/MariaDB setups; migrations are idempotent and preserve existing settings, redirects and queue data.
- Deactivate/reactivate preserves override data; uninstall removes only VonSEO-owned data/task state. Document and test rollback boundaries for the new storage before rollout.
- Permission, method/token, SQL escaping, stored/reflected XSS, image URL validation and input-boundary regressions pass for every new ACP flow.
- Public, private, passworded, inactive, deleted/unapproved content and guest-denied profiles remain protected. Exercise indexing-policy changes and queue races/removal history without reconstructing never-public URLs.
- Metadata, canonical, Open Graph, schema, sitemap and IndexNow use consistent resolved policy. Blank overrides preserve current defaults; title pagination and keyword URL ON/OFF behavior remain intact.
- Root and `/forum/` routing plus installed-theme desktop/mobile smoke tests pass for the changed paths. Separate actual Nginx/LiteSpeed runtime results from Apache and routing-fixture evidence; do not claim untested deployments.
- Run the contributor checks, supported PHP CI matrix and relevant database checks; record actual results and any untested runtime rather than inferring compatibility from a newer PHP version.
- Benchmark the added lookup/render overhead on ordinary pages and larger listings. Keep bounded queries; do not add an unbounded frontend scan or site-wide crawl.
- One independent reviewer checks the completed scope after regressions; resolve release-blocking security/correctness findings before packaging. This is not a blanket security certification.
- Verify version/documentation alignment and byte-matched public ZIP contents. Keep tests, private QC notes and backups out of the upload ZIP; publication is a separate explicitly authorized step.

## Deployment checks for site owners

- Verify the matching server rules on staging before enabling keyword URLs. Apache-style and Nginx examples are supplied; confirm rewrite loading and direct visits on LiteSpeed.
- A board installed in a subfolder cannot automatically own the host-root `/robots.txt`; configure the web root manually and use the ACP guidance to inspect the expected content.
- Test the production theme, mobile layout, custom plugins, guest permissions and user-generated links. VonSEO cannot repair theme HTML or moderation policy on its own.
- Submit the sitemap through the search-engine tools you use and inspect representative public, private and paginated pages. Local tests cannot prove indexing, rankings or rich-result eligibility.
- Migrate legacy third-party URL schemes on staging with reviewed exact redirects. VonSEO does not automatically import another plugin's URL database.

## Later candidates: deferred beyond 1.1.0

These items remain outside the 1.1.0 scope. They are candidates, not commitments for a particular version.

### Legacy migration and coverage gaps

- **P1: Profile/user sitemap.** Add paginated public-profile sitemap coverage with explicit eligibility rules and no disclosure of hidden or inactive accounts.
- **P1: Additional-pages sitemap.** Add an administrator allowlist for canonical public pages; never crawl arbitrary routes or accept visitor-controlled URLs.
- **P1: Legacy SEO migration helper.** Inspect or import known third-party URL records and schemes, preview exact redirects, detect collisions and loops, and support rollback before applying changes.
- **P2: Wider keyword URL coverage.** Evaluate stable ID-based keyword URLs for profiles, announcements, calendars and events while preserving their native MyBB aliases.
- **P2: Wider indexing controls.** Evaluate entity types beyond the forum/thread controls planned for 1.1.0, without weakening MyBB permission checks.
- **P2: Link qualification.** Evaluate a theme-compatible policy for user-generated links such as `rel="ugc"` or `nofollow`; do not rewrite every post link blindly.
- **P2: Canonical scheme enforcement.** Consider an opt-in, trusted-proxy-aware HTTP-to-HTTPS and preferred-host redirect after loop-safe deployment tests.
- **P3: Slug customization.** Consider migration-safe character translation, length and separator controls. Existing stable ID paths remain the compatibility baseline.

Fully arbitrary URL schemes, virtual parent directories and database-backed slug ownership are not near-term commitments. They add collision, rewrite and rollback risk that must be justified by real migration demand.

### Server and compatibility

- Broader installed-theme, calendar-permission and production-server compatibility checks.
- Installed MyBB on Nginx and LiteSpeed runtime verification, beyond the provided examples and Nginx route fixtures.
- Automatic canonical redirect mode (conservative and opt-in).

### Content SEO

- Moderator/Mod CP access to SEO overrides after explicit forum-scoped permission design; initial 1.1.0 management is ACP-only.
- SEO overrides for additional entity types beyond forums and threads.

### Redirects and errors

- Optional redirect-chain flattening with explicit preview/confirmation and rollback. The read-only inspector and manual-shortening suggestion already exist since 1.0.5.
- Ignore or mute selected 404 entries
- Configurable 404 retention/pruning task

### ACP / Diagnostics

- Site-wide canonical/sitemap consistency diagnostics beyond the bounded entity Inspector planned for 1.1.0.
- Broader description-quality and private-content regression tooling, without claiming search-ranking scores or exhaustive leak detection.
- Live HTTP/rewrite diagnostics with an explicit, bounded, trusted-target design; the existing Redirect Inspector inspects saved rules only.

### Advanced Crawling

- Sitemap cache
- IndexNow queue controls, dead-letter inspection and manual retry
- crawler diagnostics

### URL Layer

- Collision and uniquifier diagnostics for any future entity URL expansion
- Redirect-safe slug history for future configurable slug behavior
