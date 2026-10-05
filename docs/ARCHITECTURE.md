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

Thread descriptions come from the first visible post. The sanitizer removes common quote/code/image MyCode, tags, raw URLs and excess whitespace before performing a sentence-aware truncation.

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
