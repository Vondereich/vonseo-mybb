# VonSEO MyBB Architecture

VonSEO MyBB follows the same core ownership rules used by VonCMS SEO while using MyBB's plugin hooks and database layer.

## URL ownership

`VonSEO_Url` is the only canonical URL resolver used by:

- `<link rel="canonical">`;
- `og:url`;
- JSON-LD URLs;
- XML sitemap URLs;
- crawler route links.

The configured MyBB `bburl` is the trusted origin. Request `Host` headers are not used to build canonical content URLs.

The resolver:

1. trims trailing slashes from `bburl`;
2. collapses duplicate slashes in URL paths without damaging `https://`;
3. preserves MyBB's configured friendly URL output from `get_thread_link()`, `get_forum_link()` and `get_profile_link()`;
4. strips an already-present MyBB install subfolder before joining to `bburl`, preventing `/forum/forum/...` URLs.

## Request / redirect layer

`VonSEO_Redirects` runs from MyBB's `global_end` hook, after core global setup but before the requested front-controller script continues.

Flow:

```text
Request
  ↓
MyBB global bootstrap
  ↓
VonSEO redirect lookup
  ├─ 301 / 302 → safe Location response
  ├─ 307 / 308 → method-preserving Location response
  ├─ 410       → themed Gone error page
  └─ no match  → normal MyBB request
```

The redirect table uses a SHA-256 source hash for exact lookup and keeps the original source path for display. Runtime and save-time loop guards are both applied.

Unknown pretty paths can optionally be routed into MyBB through the matching Apache-style or Nginx fallback in `extras/`. Keep native MyBB routes ahead of the fallback. The Nginx subfolder example does not route paths outside the board directory into MyBB.

ACP lists load `VonSEO_AdminList` only in the admin module. It allowlists query columns/directions and status/state filters, escapes literal substring searches, counts matching rows and fetches a bounded 50-row page with a unique tie-breaker. Requested pages are clamped before calculating an offset. There is no new frontend query or schema change for this list UI.

## Error layer

MyBB 1.8 historically renders many content lookup errors through its generic error page. `VonSEO_Errors` marks MyBB error/no-permission hooks and corrects the HTTP response at `pre_output_page`, where headers can still be changed safely.

Rules:

- missing thread/forum/profile-like GET/HEAD requests → 404 when the 404 guard is enabled;
- permission pages → 403;
- explicit VonSEO Gone rules → 410;
- POST validation/form errors are not automatically converted to 404;
- error pages receive `X-Robots-Tag: noindex, follow`;
- error pages do not receive canonical/social/schema data from an underlying private entity.

The 404 monitor aggregates only normalized URL paths, hit counts and first/last timestamps. Static asset extensions are ignored and visitor IP addresses are not stored.

## Public indexing baseline

Search engines are guests. Indexability and sitemap inclusion are therefore tested against MyBB's Guest group (`gid=1`), not the permission level of the currently logged-in visitor.

VonSEO excludes:

- forums not viewable by guests;
- forums whose guest permissions do not allow thread viewing;
- `canonlyviewownthreads` forums;
- password-protected forums;
- inactive forums;
- invisible/unapproved/deleted threads;
- moved-thread placeholders;
- non-content utility pages.

## Server-rendered output

VonSEO runs on `pre_output_page`, after MyBB has parsed the page and before it is sent to the browser. Metadata is therefore present in raw HTML and does not depend on JavaScript.

## Content description

Automatic thread descriptions use the original approved post as MyBB rendered it for Guest, not raw database MyCode or an administrator's private view. Description normalization and truncation are separate from the complete guest-rendered text used by DiscussionForumPosting.

## Entity overrides and ACP snapshots

Schema 6 adds the plugin-owned `vonseo_overrides` table for forum/thread title, description, image and restrictive noindex controls. A forum policy affects directly contained threads only; no override grants public access. Indexed, request-cached lookups are shared by metadata, sitemap filtering and IndexNow reconciliation. Separate ACP permissions and protected POST mutations govern management; native names, slugs and MyBB settings are not silently replaced.

The ACP Inspector is a read-only configuration snapshot. It does not execute the Guest post renderer or fetch arbitrary URLs. Without that rendered first post, a first-page thread snapshot cannot supply DiscussionForumPosting. A separate schema limitation notice remains even when a custom description hides the automatic-description fallback notice. Search/social cards are illustrative, not live indexing results.

## IndexNow history concurrency

Admission atomically updates only the remembered public URL and update time. Successful delivery monotonically advances the cooldown timestamp and cannot replace a URL admitted while an older request was in flight. Removal acknowledgements delete only the selected queue generation, not history: a concurrent restoration must retain its evidence. This history contains only previously admitted public URLs and does not grant current eligibility; board/Guest gates still control delivery. Uninstall removes all VonSEO-owned history.

## Social image fallback

Thread:

1. first visible image attachment in the first post (when enabled);
2. configured default social image;
3. no image tag.

Only the winning image is emitted, avoiding duplicate `og:image` fallbacks.

## Schema

Current schema:

- `WebSite` for homepage;
- `CollectionPage` + `BreadcrumbList` for forums;
- `DiscussionForumPosting` + `BreadcrumbList` for threads;
- `Article` + `BreadcrumbList` for eligible announcements;
- `CollectionPage` for guest-visible monthly calendars;
- `Event` + `BreadcrumbList` for public approved events;
- optional `ProfilePage` + `Person` for indexable profiles.

All schema URLs come from the same URL resolver as canonical and Open Graph.

## Crawler endpoints

- `misc.php?action=vonseo_sitemap`
- `misc.php?action=vonseo_robots`
- `misc.php?action=vonseo_404`
- internal fallback: `misc.php?action=vonseo_route`

The sitemap is an index that points to forum, chunked thread, announcement, calendar and event sitemaps. Default chunk size is 1000 URLs and can be configured up to 50000. Profile sitemaps are not included in 1.0.x.
