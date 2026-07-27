# Markdown for Agents — WordPress plugin

HTTP content negotiation for WordPress: AI agents that request
`Accept: text/markdown` receive a Markdown rendering of the page; every
other request receives the normal HTML, byte-identical. A self-hosted
replacement for Cloudflare's Pro-plan-gated "Markdown for Agents" feature.

**The plugin lives in [`bice-markdown-agents/`](bice-markdown-agents/) —
full documentation, including the critical cache-layer caveat and
verification commands, is in
[`bice-markdown-agents/README.md`](bice-markdown-agents/README.md).**

Quick facts:

- Strict RFC 9110 `Accept` parsing with q-values; wildcards never trigger
  Markdown, so browsers are never affected.
- Markdown responses: `Content-Type: text/markdown; charset=utf-8`,
  `X-Markdown-Tokens` / `X-Original-Tokens` estimates, merged `Vary: Accept`,
  `Cache-Control: private, no-store, max-age=0`, `X-Robots-Tag: noindex`.
- HTML responses advertise the alternate via a `Link` header.
- Optional `/page/index.md` suffix URLs.
- Converts the rendered page (Elementor-safe), with YAML frontmatter and
  JSON-LD preserved.
- Works as a normal plugin or from `mu-plugins/` via the bundled loader.
- 120 PHPUnit tests; `league/html-to-markdown` bundled in `vendor/`.
