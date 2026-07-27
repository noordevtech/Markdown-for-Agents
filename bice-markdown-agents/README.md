# Markdown for Agents (`bice-markdown-agents`)

A WordPress plugin implementing HTTP content negotiation so AI agents can
request Markdown instead of HTML — a self-hosted replacement for Cloudflare's
Pro-plan-gated "Markdown for Agents" feature.

- A GET/HEAD request whose `Accept` header **genuinely prefers**
  `text/markdown` (explicit listing, q ≥ q of `text/html`, per RFC 9110)
  receives a Markdown rendering of the fully rendered page.
- **Every other request receives the normal HTML response, byte-identical to
  before.** This is the primary safety property; the Accept parser is strict
  and covered by 55 unit tests before anything else was built.
- Optional: an `index.md` URL suffix (`/traiteur/` → `/traiteur/index.md`).
- **Content Signals**: declares AI content-usage preferences
  ([contentsignals.org](https://contentsignals.org/)) in `robots.txt`,
  preserving every existing rule.

Built for (but not limited to): WordPress + WooCommerce + Elementor Pro +
Hello Elementor child theme, WPML (FR `/` + EN `/en/`), PHP 8.4, nginx +
PHP-FPM behind Cloudflare, with an `advanced-cache.php` page-cache drop-in.

---

## ⚠️ The cache layer caveat — READ THIS FIRST

**Two cache layers on this stack serve responses before this plugin ever
runs. Both must be configured by hand. The plugin cannot do it for you.**

Layers, in request order:

| Layer | Behaviour on a Markdown request | Who fixes it |
|---|---|---|
| 1. Cloudflare (caches HTML, `cf-cache-status: HIT`) | Serves cached **HTML** without contacting the origin | **You**, with a Cache Rule (below) |
| 2. `advanced-cache.php` drop-in (`x-cache-by: Advanced Cache`) | On a HIT, serves cached **HTML** before any plugin loads — `template_redirect` never fires | **You**, with the nginx snippet (below) |
| 3. This plugin | Negotiates correctly | — |

What the plugin itself guarantees, regardless of the layers above:

- Markdown responses are sent with `Cache-Control: private, no-store,
  max-age=0` and `Vary: Accept` (merged with existing `Vary`), so a
  well-behaved cache never stores them. Cloudflare does not reliably honour
  `Vary` on HTML — which is exactly why the Markdown variant is made
  uncacheable instead of relying on `Vary`. Correctness beats performance;
  agent traffic is low volume.
- Markdown responses carry `X-Robots-Tag: noindex` so the alternate
  representation cannot be indexed as duplicate content.
- When serving Markdown, the plugin defines `DONOTCACHEPAGE` (documented and
  honoured by WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache,
  Hummingbird), calls LiteSpeed's documented
  `litespeed_control_set_nocache` action when LiteSpeed is present, and sets
  Cache Enabler's documented `cache_enabler_bypass_cache` filter. One flagged
  guess: the `wpo_can_cache_page` filter used for WP-Optimize was **not**
  verified against WP-Optimize source (it is guarded and inert elsewhere; see
  `includes/class-cache-compat.php`).
- The Settings page (Settings → Markdown for Agents) shows which caching
  plugin was detected at runtime and the drop-in's signature line, rather
  than assuming one.

### Fix for layer 2 (the `advanced-cache.php` drop-in) — nginx

The drop-in short-circuits on cache hits before plugins load. In order of
preference:

1. **If your caching plugin has a filter API for cache exclusions**, register
   an exclusion for requests with `Accept: text/markdown` there. The generic
   `advanced-cache.php` on this stack exposes no such API that I could
   detect at runtime, so:
2. **`DONOTCACHEPAGE`** is defined on every Markdown response (prevents
   *storing*, works for the plugins listed above) — but it cannot prevent a
   drop-in from *serving* an existing HTML hit; so:
3. **Ship-level fix (required on this stack): the nginx snippet** in
   [`nginx/markdown-agents.conf`](nginx/markdown-agents.conf). It appends a
   `bma_md=1` marker to the `REQUEST_URI` that PHP sees for
   Markdown-preferring requests, so the drop-in (which keys its cache on
   `$_SERVER['REQUEST_URI']`) treats them as a separate URL and never serves
   them the cached HTML — and never serves browsers a stored Markdown body.
   Install instructions are in the snippet header (one `include` + one
   `fastcgi_param` line).

### Fix for layer 1 (Cloudflare) — Cache Rule

Create a Cache Rule (available on the Free plan):

- **When:** `any(http.request.headers["accept"][*] contains "text/markdown")`
- **Then:** Bypass cache.

Without it, agents hitting a Cloudflare-cached URL get HTML (feature miss,
not a safety miss — browsers are unaffected either way, and the `no-store`
response header prevents Cloudflare from ever storing the Markdown variant).

---

## Installation

### As a normal plugin

```bash
cp -r bice-markdown-agents wp-content/plugins/
wp plugin activate bice-markdown-agents
```

The `vendor/` directory (with `league/html-to-markdown`) is committed, so no
Composer step is needed on the server.

### As an mu-plugin (this site's deployment pattern)

WordPress does not load subdirectories of `mu-plugins/`, so use the loader:

```bash
cp -r bice-markdown-agents wp-content/mu-plugins/
cp bice-markdown-agents/mu-loader-sample.php wp-content/mu-plugins/bice-markdown-agents-loader.php
```

Settings and diagnostics live under **Settings → Markdown for Agents**:
master on/off, `.md` suffix on/off, post-type exclusions, path exclusions.

---

## What gets converted

The **rendered page body**, captured with output buffering at
`template_redirect` — not the raw post content, which for Elementor pages is
just layout meta. Before conversion the plugin strips `<script>`, `<style>`,
`<noscript>`, `<svg>`, `<nav>`, `<header>`, `<footer>`, HTML comments,
form controls, hidden elements and common cookie/consent banners
(Complianz, CookieYes, OneTrust, Cookie Notice, Moove GDPR, generic
`cookie`/`consent` class tokens), keeping the main content region
(`<main>` / `[role=main]` / the Elementor `wp-page` wrapper / `<article>` /
`#content`).

Preserved: heading hierarchy, links (absolute URLs), lists, GFM tables,
blockquotes, code blocks, image alt text (`![alt](src)`, honouring
`data-src` lazy-loading), and all `application/ld+json` blocks — appended at
the end in fenced ` ```json ` blocks. YAML frontmatter (`title`,
`description`, `image`, `lang`) is prepended when the page has the matching
meta tags.

Conversion uses `league/html-to-markdown` (bundled) with DOM pre-processing.

**Token estimates** (`X-Markdown-Tokens`, `X-Original-Tokens`) use a
documented heuristic — `ceil(bytes / 4)`, roughly the BPE average for prose —
because no real tokenizer exists in PHP. Treat them as estimates only.

## Scope guards

Markdown is emitted only for front-end, publicly visible, HTTP 200 GET/HEAD
responses. Never for: `wp-admin`, `wp-login`, REST, XML-RPC, AJAX, cron,
CLI, feeds, sitemaps, `robots.txt`, favicon, trackbacks, previews,
WooCommerce cart/checkout/account pages, logged-in users,
password-protected posts, non-200 statuses, excluded post types or paths.
Every flag fails safe: an unknown context blocks Markdown.

WPML: the plugin converts whatever the current language context renders
(`/` → FR, `/en/` → EN). No cross-language mapping is attempted.

If anything at all is off — non-200 status set by the template, headers
already sent, conversion failure or empty result — the original HTML bytes
are returned untouched.

## HTML responses advertise the alternate

Eligible HTML responses get
`Link: <same-url>; rel="alternate"; type="text/markdown"`.

## Content Signals in robots.txt

The plugin can declare AI content-usage preferences per the
[Content Signals](https://contentsignals.org/) specification
(draft-romm-aipref-contentsignals), configurable under
**Settings → Markdown for Agents → Content Signals**. Three signals, each
`yes` / `no` / no preference:

| Signal | Meaning | Default |
|---|---|---|
| `search` | Build a search index; return links and short excerpts (not AI summaries) | `yes` — discoverability is the core of the business |
| `ai-input` | Supply content to a model at answer time (RAG, grounding, live answers) | `yes` — assistants surfacing the site at answer time bring visits and bookings |
| `ai-train` | Use content to train or fine-tune a model | `no` — training returns no link, no visit, no booking |

Default emitted directive:

```
Content-Signal: search=yes, ai-input=yes, ai-train=no
```

The insertion is **surgical**: every existing robots.txt rule contributed by
WordPress, WooCommerce, Rank Math or anything else on the `robots_txt`
filter is preserved verbatim. The directive (with an explanatory comment
preamble) is inserted inside the first `User-agent: *` group; if none
exists, a new wildcard group is prepended above the existing rules. The
logic is idempotent — an already-present `Content-Signal:` line (ours or a
third party's) is never duplicated — and nothing is emitted on sites with
`blog_public` off (staging/discouraged sites), independent of the Markdown
negotiation master switch.

Note: if your robots.txt is not generated by WordPress (a physical
`robots.txt` file, or an edge rule serving it), this filter never runs —
add the directive in that layer instead.

---

## Verification

```bash
SITE=https://your-site.example

# 1. Agent request returns markdown
curl -sI -H 'Accept: text/markdown' $SITE/ | grep -i content-type
# → content-type: text/markdown; charset=utf-8

# 2. Browser request is unchanged — the critical one
curl -sI -H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
     $SITE/ | grep -i content-type
# → content-type: text/html; charset=UTF-8

# 3. Token headers present
curl -sI -H 'Accept: text/markdown' $SITE/ | grep -i x-markdown-tokens

# 4. Markdown is not edge-cacheable
curl -sI -H 'Accept: text/markdown' $SITE/ | grep -i cache-control
# → cache-control: private, no-store, max-age=0

# 5. Works in both languages
curl -s -H 'Accept: text/markdown' $SITE/en/ | head -20

# 6. HTML advertises the alternate
curl -sI $SITE/ | grep -i '^link.*markdown'

# 7. Wildcard alone must NOT return markdown
curl -sI -H 'Accept: */*' $SITE/ | grep -i content-type
# → content-type: text/html; charset=UTF-8

# 8. Content Signals present in robots.txt (existing rules intact)
curl -s $SITE/robots.txt | grep -i content-signal
# → Content-Signal: search=yes, ai-input=yes, ai-train=no
```

External validation:

```bash
curl -s -X POST https://isitagentready.com/api/scan \
     -H 'Content-Type: application/json' \
     -d "{\"url\":\"$SITE\"}" | jq '.checks.contentAccessibility.markdownNegotiation'
```

## Development

```bash
composer install          # dev deps (PHPUnit)
vendor/bin/phpunit        # 135 tests: Accept parser, scope guards, converter, signals
composer install --no-dev # before committing vendor/ for deployment
```

Test coverage: the RFC 9110 Accept parser (real browser strings, wildcards,
q-value ties, malformed input, quoted parameters), every scope-guard flag,
conversion of a representative Elementor page fixture (frontmatter,
headings, lists, tables, blockquotes, lazy images, JSON-LD, cookie-banner
stripping, malformed HTML, entities, nested inline elements), and the
Content Signals robots.txt logic (directive building, surgical insertion,
rule preservation, idempotency).
