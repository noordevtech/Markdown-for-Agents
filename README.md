# Markdown for Agents — WordPress plugin

Makes a WordPress site agent-ready. HTTP content negotiation: AI agents
that request `Accept: text/markdown` receive a Markdown rendering of the
page; every other request receives the normal HTML, byte-identical. Plus
[Content Signals](https://contentsignals.org/) in robots.txt, OAuth
protected-resource metadata (RFC 9728), `/auth.md`, an MCP server card,
an agent-skills discovery index, and WebMCP browser tools. A self-hosted
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
- Content Signals in robots.txt (`Content-Signal: search=yes, ai-input=yes,
  ai-train=no` by default, configurable per signal), inserted surgically so
  existing WordPress/WooCommerce/SEO-plugin rules are preserved.
- Agent discovery: `/.well-known/oauth-protected-resource` (RFC 9728),
  `/.well-known/oauth-authorization-server` (+`agent_auth`), `/auth.md`,
  `/.well-known/mcp/server-card.json` (SEP-1649 draft), and
  `/.well-known/agent-skills/index.json` with a real SKILL.md — honest
  defaults that never invent OAuth/MCP infrastructure, configurable when
  the real thing exists. nginx snippet included for the usual dotfile-deny
  403 on `/.well-known/`.
- WebMCP: `navigator.modelContext.provideContext()` tools
  (`search_content`, `get_page_markdown`) for browser-embedded agents.
- Works as a normal plugin or from `mu-plugins/` via the bundled loader.
- 164 PHPUnit tests; `league/html-to-markdown` bundled in `vendor/`.
