# VonSEO MyBB Roadmap

This roadmap separates released behavior from possible future work. Items under **Post-1.0 candidates** are not commitments and may change after compatibility and security review.

## v0.1 Core: implemented
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

## v0.2 Redirect & Modern SEO Foundation: beta.1 implemented
- Database-backed redirect rules (301 / 302 / 307 / 308 / 410)
- Redirect hit counters & loop/self-redirect protection
- Real 404 status guard & 403 permission guard
- 404 monitor with aggregated URL paths
- ACP VonSEO health page, Redirect Manager and 404 Monitor
- CSV Import and Export for redirect rules with overwrite support
- Optional legacy `SearchAction` JSON-LD schema (Google retired the Sitelinks Search Box result)
- `Organization` publisher schema for entity authority
- Enriched `DiscussionForumPosting` with `interactionStatistic` (`ReplyAction`/`ViewAction`) and `articleSection`
- Open Graph article tags (`published_time`, `modified_time`, `section`, `author`)
- AI bot crawler control in `robots.txt` (GPTBot, ClaudeBot, PerplexityBot, etc.)
- IndexNow real-time instant indexing engine & auto-ping hooks
- Optional Apache fallback router for legacy pretty URLs

## v0.3 Stability and security beta: packaged
- Preserve the current MyBB-native URLs; no new slug or calendar/event SEO module in this beta.
- Regress the existing security fixes, public-only output, redirect/404 behavior, and plugin lifecycle.
- Align code, documentation, and beta package version; identify installed MyBB/Apache/IndexNow QA gaps explicitly.

## v0.4 Keyword URL beta: packaged
- Opt-in ID-based public thread/forum keyword URLs without MyBB core edits; still disabled by default.
- Canonical, schema, sitemap, IndexNow, ordinary navigation and pagination share the URL resolver. MyBB actions and filtered links stay native.
- Local Apache/MyBB 1.8.41 root and subfolder direct visits, stale-slug redirects, native alias migration, password access and temporary rollback passed. Native aliases use non-cacheable 302 while rollback is supported; stale keyword slugs use 301.
- Existing third-party custom URL databases and rewrite schemes still need a site-specific inspected redirect import. The beta is not production approval or a search-indexing guarantee.

## v0.5 Stability & diagnostics beta: packaged
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

## v0.6 Broader content SEO: 0.6.0 packaged
- Active public announcement pages now receive canonical/title/description output, article social metadata and breadcrumb-aware `Article` JSON-LD from MyBB's guest-rendered announcement text.
- The sitemap index now advertises paginated announcement chunks containing only active global announcements or announcements assigned to guest-visible forums.
- Future, expired, passworded, inactive and otherwise guest-inaccessible forum announcements fail closed. No MyBB core edit, new database table or announcement URL rewrite is used.
- Version 0.6.0 adds guest-visible monthly calendar canonicals and `CollectionPage` schema, plus public event canonical/description/`Event` schema and calendar/event sitemap coverage. Private, unapproved and guest-denied events fail closed; day/week/utility views remain noindex.
- Source and extracted package pass 256 tests, the 29-case ACP method/token matrix and PHP 8.3-8.5 lint. Focused live checks passed for the native monthly calendar, day-view noindex and a temporary public/private/unapproved event lifecycle; broader themes and calendar permission overrides remain pending. The 26-file public ZIP is byte-matched and excludes internal project material.
- Profile sitemaps are the next content-coverage gap. Legacy custom-scheme migration remains a separate operational gap.

## v0.7 Pre-RC hardening: completed
- IndexNow removal URLs fail closed to captured or remembered public history; never-public hidden content cannot be reconstructed into a crawler notification.
- Schema 5 separates content eligibility from retry backoff so new activity cannot bypass configuration, rate-limit or remote-failure pauses.
- Public calendar/event identity remains consistent for logged-in visitors, explicit current-month URLs consolidate to the calendar base, and event sitemap entries no longer claim creation time as modification time.
- Clean Apache sitemap/robots rewrites allowlist supported selectors and discard unrelated action collisions. Nginx and LiteSpeed require equivalent site-specific server rules.

## v1.0 Stable foundation: released
- Version-aligned 1.0.0 package with a fixed 26-file public manifest and no tests, private backups or internal progress documents.
- Keyword URLs remain optional and disabled by default; native MyBB URLs continue to work, and rollback behavior remains available while VonSEO and its server rules are present.
- Source and extracted-package checks pass on PHP 8.3, 8.4 and 8.5: 272 self-contained tests, 32 ACP request/token/permission cases, and PHP lint.
- The local MyBB 1.8/PHP 8.4 playground passed the 21-case guest HTTP smoke, schema 5/5 diagnostics, queue readiness and focused 404 aggregation proof.
- A later manual audit found and closed an authenticated ACP settings-write issue, an overly broad permission and an unsupported-database activation defect. These changes have focused regression coverage; historical scans are not a guarantee for later changes or every deployment.

## v1.0.1 Maintenance: implemented
- Search, response/state filters, sorting and 50-row pagination for ACP redirect lists; search, sorting and pagination for the 404 monitor.
- Root/subfolder Nginx snippets and server-aware ACP instructions. Nginx fixture routing was exercised locally; a complete installed Nginx/MyBB deployment and LiteSpeed runtime remain separate compatibility checks.
- Portable contributor verification runner and GitHub Actions definitions for PHP 7.1-8.5, MySQL/MariaDB and public-package parity. Remote matrix results remain unverified until the workflow runs.
- No database migration or change to keyword/indexing defaults.

## v1.0.2 Keyword post-target maintenance: implemented
- Cache raw post data by post ID and recheck the requested thread ID plus approved status for every keyword link or redirect decision.
- Regression coverage for both link orders, private/unapproved/missing posts, the ten-post lookup budget and keyword URLs off.
- No database migration or change to keyword/indexing defaults.

## Deployment checks for site owners
- Verify the matching server rules on staging before enabling keyword URLs. Apache-style and Nginx examples are supplied; confirm rewrite loading and direct visits on LiteSpeed.
- A board installed in a subfolder cannot automatically own the host-root `/robots.txt`; configure the web root manually and use the ACP guidance to inspect the expected content.
- Test the production theme, mobile layout, custom plugins, guest permissions and user-generated links. VonSEO cannot repair theme HTML or moderation policy on its own.
- Submit the sitemap through the search-engine tools you use and inspect representative public, private and paginated pages. Local tests cannot prove indexing, rankings or rich-result eligibility.
- Migrate legacy third-party URL schemes on staging with reviewed exact redirects. VonSEO does not automatically import another plugin's URL database.

## Post-1.0 candidates: not required for 1.0

### Legacy migration and coverage gaps
- **P1: Profile/user sitemap.** Add paginated public-profile sitemap coverage with explicit eligibility rules and no disclosure of hidden or inactive accounts.
- **P1: Additional-pages sitemap.** Add an administrator allowlist for canonical public pages; never crawl arbitrary routes or accept visitor-controlled URLs.
- **P1: Legacy SEO migration helper.** Inspect or import known third-party URL records and schemes, preview exact redirects, detect collisions and loops, and support rollback before applying changes.
- **P2: Wider keyword URL coverage.** Evaluate stable ID-based keyword URLs for profiles, announcements, calendars and events while preserving their native MyBB aliases.
- **P2: Indexing controls.** Add optional per-forum/per-entity index or noindex overrides without weakening MyBB permission checks.
- **P2: Link qualification.** Evaluate a theme-compatible policy for user-generated links such as `rel="ugc"` or `nofollow`; do not rewrite every post link blindly.
- **P2: Canonical scheme enforcement.** Consider an opt-in, trusted-proxy-aware HTTP-to-HTTPS and preferred-host redirect after loop-safe deployment tests.
- **P3: Slug customization.** Consider migration-safe character translation, length and separator controls. Existing stable ID paths remain the compatibility baseline.

Fully arbitrary URL schemes, virtual parent directories and database-backed slug ownership are not near-term commitments. They add collision, rewrite and rollback risk that must be justified by real migration demand.

### Server and compatibility
- Broader installed-theme, calendar-permission and production-server compatibility checks.
- Installed MyBB on Nginx and LiteSpeed runtime verification, beyond the provided examples and Nginx route fixtures.
- Automatic canonical redirect mode (conservative and opt-in).

### Content SEO
- Custom SEO metadata table
- Thread SEO override panel for moderators/admins
- Forum SEO override panel
- SERP preview
- Custom social image

### Redirects and errors
- Redirect chain inspector and flatten suggestion
- Ignore or mute selected 404 entries
- Configurable 404 retention/pruning task

### ACP / Diagnostics
- Canonical consistency inspector
- Sitemap health
- Missing/weak description checks
- Private-content leak checks
- URL Inspector
- rewrite diagnostics

### Advanced Crawling
- Sitemap cache
- IndexNow queue controls, dead-letter inspection and manual retry
- crawler diagnostics

### URL Layer
- Collision and uniquifier diagnostics for any future entity URL expansion
- Redirect-safe slug history for future configurable slug behavior
