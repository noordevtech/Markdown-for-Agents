<?php
/**
 * Agent discovery endpoints: OAuth metadata, auth.md, MCP server card,
 * agent-skills index.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Serves the machine-readable discovery documents that agent crawlers
 * (e.g. isitagentready.com) look for:
 *
 *  - /.well-known/oauth-protected-resource        (RFC 9728)
 *  - /.well-known/oauth-authorization-server      (RFC 8414 + agent_auth block, auth.md spec)
 *  - /auth.md                                     (workos.com/auth-md)
 *  - /.well-known/mcp/server-card.json            (MCP SEP-1649, draft)
 *  - /.well-known/agent-skills/index.json         (Agent Skills Discovery RFC v0.2.0)
 *  - /.well-known/agent-skills/markdown-for-agents/SKILL.md
 *
 * Document builders are pure functions over a config array so they can be
 * unit-tested without WordPress; {@see Discovery::register()} wires them to
 * the request.
 *
 * Standards-maturity note, also in the README: RFC 9728 and RFC 8414 are
 * published RFCs; the agent_auth block (auth.md), the MCP Server Card
 * (SEP-1649) and the Agent Skills index are early-stage/draft specs, so
 * their `$schema` URLs and exact field names are best-effort against the
 * drafts as of 2026 and may need updating as they stabilize.
 */
final class Discovery {

	/**
	 * Route table: URL path => builder method name.
	 */
	private const ROUTES = array(
		'/.well-known/oauth-protected-resource'                 => 'protected_resource_metadata',
		'/.well-known/oauth-authorization-server'               => 'authorization_server_metadata',
		'/.well-known/mcp/server-card.json'                     => 'server_card',
		'/.well-known/agent-skills/index.json'                  => 'skills_index',
		'/.well-known/agent-skills/markdown-for-agents/SKILL.md' => 'skill_md',
		'/auth.md'                                              => 'auth_md',
	);

	/**
	 * Hook the discovery routes into the request lifecycle.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	public static function register( array $settings ): void {
		if ( empty( $settings['discovery_enabled'] ) ) {
			return;
		}

		// init (not plugins_loaded): WPML and friends are loaded, and we can
		// still answer before WP routing would 404 these virtual paths.
		add_action(
			'init',
			static function () use ( $settings ): void {
				self::maybe_serve( $settings );
			},
			1
		);
	}

	/**
	 * Match a request path against the route table.
	 *
	 * @param string $path URL path (no query string).
	 * @return string|null Builder method name, or null when not a discovery route.
	 */
	public static function match_route( string $path ): ?string {
		$path = '/' . ltrim( $path, '/' );
		// Tolerate a trailing slash added by proxies or canonicalization.
		$path = rtrim( $path, '/' ) ?: '/';

		foreach ( self::ROUTES as $route => $builder ) {
			if ( strtolower( $path ) === strtolower( rtrim( $route, '/' ) ) ) {
				return $builder;
			}
		}

		return null;
	}

	/**
	 * Serve a discovery document and exit, when the request matches.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	private static function maybe_serve( array $settings ): void {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$path    = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		$builder = self::match_route( $path );
		if ( null === $builder ) {
			return;
		}

		$config   = self::config_from_wp( $settings );
		$document = self::$builder( $config );

		nocache_headers();
		header_remove( 'Expires' );
		// Small, public, harmless documents — let edges cache them briefly.
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Robots-Tag: noindex' );
		// RFC 9728 §3.1: metadata endpoints must be readable cross-origin.
		header( 'Access-Control-Allow-Origin: *' );

		if ( is_string( $document ) ) {
			header( 'Content-Type: text/markdown; charset=utf-8' );
			echo $document; // phpcs:ignore WordPress.Security.EscapeOutput -- plain markdown body.
		} else {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		exit;
	}

	/**
	 * Build the pure-config array from WordPress state + settings.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	public static function config_from_wp( array $settings ): array {
		return array(
			'site_url'              => untrailingslashit( home_url( '/', 'https' ) ),
			'site_name'             => (string) get_bloginfo( 'name' ),
			'site_description'      => (string) get_bloginfo( 'description' ),
			'admin_email'           => (string) get_option( 'admin_email' ),
			'version'               => defined( 'BICE_MDA_VERSION' ) ? BICE_MDA_VERSION : '0',
			'authorization_servers' => (array) ( $settings['oauth_authorization_servers'] ?? array() ),
			'scopes'                => (array) ( $settings['oauth_scopes'] ?? array() ),
			'register_uri'          => (string) ( $settings['agent_register_uri'] ?? '' ),
			'mcp_endpoint'          => (string) ( $settings['mcp_endpoint'] ?? '' ),
			'md_suffix'             => ! empty( $settings['md_suffix'] ),
		);
	}

	// ------------------------------------------------------------------
	// Pure document builders.
	// ------------------------------------------------------------------

	/**
	 * RFC 9728 OAuth Protected Resource Metadata.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return array<string, mixed>
	 */
	public static function protected_resource_metadata( array $config ): array {
		$site = rtrim( (string) $config['site_url'], '/' );

		return array(
			'resource'                 => $site,
			'resource_name'            => (string) $config['site_name'],
			'authorization_servers'    => array_values( (array) $config['authorization_servers'] ),
			'scopes_supported'         => array_values( (array) $config['scopes'] ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_documentation'   => $site . '/auth.md',
		);
	}

	/**
	 * RFC 8414 Authorization Server Metadata with the auth.md `agent_auth`
	 * extension block (register_uri, identity/credential types).
	 *
	 * When a dedicated OAuth issuer is configured, it is authoritative for
	 * its own /.well-known/oauth-authorization-server; this document exists
	 * so agents probing THIS origin still find registration instructions.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return array<string, mixed>
	 */
	public static function authorization_server_metadata( array $config ): array {
		$site         = rtrim( (string) $config['site_url'], '/' );
		$register_uri = '' !== (string) $config['register_uri'] ? (string) $config['register_uri'] : $site . '/auth.md';

		$servers = array_values( (array) $config['authorization_servers'] );

		// Shape per the workos/auth.md reference: the outer fields restate the
		// RFC 9728 PRM, the RFC 8414 fields describe this issuer, and the
		// agent_auth block is the profile extension. Endpoint fields (token,
		// revocation, claim) are only meaningful with a real authorization
		// server behind them, so they are never fabricated here.
		$metadata = array(
			'resource'                 => $site,
			'authorization_servers'    => $servers,
			'scopes_supported'         => array_values( (array) $config['scopes'] ),
			'bearer_methods_supported' => array( 'header' ),
			'issuer'                   => $site,
			'response_types_supported' => array( 'code' ),
			'service_documentation'    => $site . '/auth.md',
			'agent_auth'               => array(
				'skill'                      => $site . '/auth.md',
				'register_uri'               => $register_uri,
				'identity_types_supported'   => array( 'anonymous', 'email' ),
				'credential_types_supported' => array( 'oauth2_access_token', 'api_key' ),
			),
		);

		return $metadata;
	}

	/**
	 * MCP Server Card (SEP-1649, draft — schema being standardized in
	 * modelcontextprotocol PR #2127).
	 *
	 * The transport block is only emitted when a real MCP endpoint is
	 * configured; advertising a fake endpoint would be worse than none.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return array<string, mixed>
	 */
	public static function server_card( array $config ): array {
		$site = rtrim( (string) $config['site_url'], '/' );

		$card = array(
			'serverInfo'    => array(
				'name'        => (string) $config['site_name'],
				'version'     => (string) $config['version'],
				'description' => '' !== (string) $config['site_description']
					? (string) $config['site_description']
					: 'Content and services of ' . (string) $config['site_name'],
				'websiteUrl'  => $site,
			),
			'capabilities'  => array(
				'resources' => array(
					// Every public page is retrievable as text/markdown via
					// content negotiation (this plugin).
					'markdownNegotiation' => true,
				),
			),
			'documentation' => $site . '/auth.md',
		);

		$endpoint = (string) $config['mcp_endpoint'];
		if ( '' !== $endpoint ) {
			$card['transport']            = array(
				'type'     => 'streamable-http',
				'endpoint' => $endpoint,
			);
			$card['capabilities']['tools'] = array( 'listChanged' => false );
		}

		return $card;
	}

	/**
	 * Agent Skills Discovery index (RFC v0.2.0).
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return array<string, mixed>
	 */
	public static function skills_index( array $config ): array {
		$site  = rtrim( (string) $config['site_url'], '/' );
		$skill = self::skill_md( $config );

		$skills = array(
			array(
				'name'        => 'markdown-for-agents',
				'type'        => 'skill',
				'description' => 'How to read this site efficiently as an AI agent: request any page as text/markdown via Accept-header content negotiation, discover alternates via Link headers, and respect the Content-Signal policy in robots.txt.',
				'url'         => $site . '/.well-known/agent-skills/markdown-for-agents/SKILL.md',
				'sha256'      => hash( 'sha256', $skill ),
			),
		);

		return array(
			// Best-effort schema URL for the draft RFC; see class docblock.
			'$schema' => 'https://agentskills.io/schema/v0.2.0/index.json',
			'version' => '0.2.0',
			'skills'  => $skills,
		);
	}

	/**
	 * The SKILL.md document advertised in the skills index.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return string Markdown.
	 */
	public static function skill_md( array $config ): string {
		$site   = rtrim( (string) $config['site_url'], '/' );
		$name   = (string) $config['site_name'];
		$suffix = ! empty( $config['md_suffix'] )
			? "\nAny page is also available at its `index.md` suffix URL, e.g. `{$site}/example-page/index.md`.\n"
			: '';

		return <<<MD
---
name: markdown-for-agents
description: Read {$name} efficiently as an AI agent — Markdown content negotiation, discovery headers and content-usage policy.
---

# Reading {$name} as an agent

## Get any page as Markdown

Send an Accept header that explicitly prefers `text/markdown`:

```
GET /any-page/ HTTP/1.1
Accept: text/markdown
```

The response is a Markdown rendering of the page (YAML frontmatter with
title/description/lang, absolute links, tables, and JSON-LD appended in a
fenced block), with `X-Markdown-Tokens` and `X-Original-Tokens` estimate
headers. Wildcards (`*/*`) do not trigger Markdown — list `text/markdown`
explicitly with a q-value at least equal to `text/html`.
{$suffix}
## Discovery

HTML responses carry `Link: <url>; rel="alternate"; type="text/markdown"`.
Authentication and registration details: {$site}/auth.md

## Content-usage policy

Respect the `Content-Signal` directive in {$site}/robots.txt
(see https://contentsignals.org/).
MD . "\n";
	}

	/**
	 * The /auth.md document (workos.com/auth-md): agent registration and
	 * authentication instructions in human- and machine-readable Markdown.
	 *
	 * Filterable via `bice_mda_auth_md` for sites that want custom content.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return string Markdown.
	 */
	public static function auth_md( array $config ): string {
		$site     = rtrim( (string) $config['site_url'], '/' );
		$name     = (string) $config['site_name'];
		$email    = (string) $config['admin_email'];
		$servers  = array_values( (array) $config['authorization_servers'] );
		$register = '' !== (string) $config['register_uri'] ? (string) $config['register_uri'] : '';

		$auth_section = array() !== $servers
			? "Tokens for protected resources are issued by:\n\n"
				. implode( "\n", array_map( static fn( $s ) => '- ' . $s, $servers ) )
				. "\n\nUse the metadata from Step 1 to obtain an access token, then send it as `Authorization: Bearer <token>`."
			: "All published content on this site is public and requires **no authentication** — you can skip registration and read everything right now. There is currently no OAuth authorization server issuing tokens for this resource; the Protected Resource Metadata reflects that with an empty `authorization_servers` list.";

		$register_section = '' !== $register
			? "Register as an agent at: {$register}"
			: 'No self-service agent registration is currently open. For elevated or programmatic access (APIs, reservations, partnerships), contact the site operator'
				. ( '' !== $email ? " at {$email}." : '.' );

		$markdown = <<<MD
# auth.md

You are an agent reading **{$name}**. This document follows the auth.md
convention (https://workos.com/auth-md): discover → access → register.
Follow the steps in order.

## Step 1 — Discover

Fetch the machine-readable metadata for this origin:

| Document | URL |
|---|---|
| Protected Resource Metadata (RFC 9728) | {$site}/.well-known/oauth-protected-resource |
| Authorization Server metadata + `agent_auth` block | {$site}/.well-known/oauth-authorization-server |
| MCP server card (SEP-1649) | {$site}/.well-known/mcp/server-card.json |
| Agent skills index | {$site}/.well-known/agent-skills/index.json |

The `agent_auth` block carries `skill` (this document), `register_uri`,
`identity_types_supported` and `credential_types_supported`.

## Step 2 — Access public content

{$auth_section}

Every page is available as Markdown via content negotiation — send
`Accept: text/markdown`. Full recipe:
{$site}/.well-known/agent-skills/markdown-for-agents/SKILL.md

## Step 3 — Register

{$register_section}

## Content-usage policy

Respect the `Content-Signal` directive in {$site}/robots.txt
(https://contentsignals.org/): AI answer-time use is welcome; training use
is not permitted.
MD . "\n";

		if ( function_exists( 'apply_filters' ) ) {
			$markdown = (string) apply_filters( 'bice_mda_auth_md', $markdown, $config );
		}

		return $markdown;
	}
}
