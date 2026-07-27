<?php
/**
 * Agent discovery endpoints: OAuth protected resource metadata, auth.md,
 * agent-skills index.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Serves the machine-readable discovery documents that agent crawlers look
 * for:
 *
 *  - /.well-known/oauth-protected-resource        (RFC 9728)
 *  - /auth.md                                     (workos.com/auth-md)
 *  - /.well-known/agent-skills/index.json         (Agent Skills Discovery RFC v0.2.0)
 *  - /.well-known/agent-skills/markdown-for-agents/SKILL.md
 *
 * THE GOVERNING RULE: a discovery document is a promise to an automated
 * client — an agent that follows a dead URL has been actively misled, which
 * is worse than publishing nothing. Every URL and capability advertised
 * here must resolve on the live site. Consequences of that rule:
 *
 *  - No /.well-known/oauth-authorization-server is served. There is no
 *    authorization server; publishing metadata with a fabricated issuer
 *    would invite agents into OAuth flows that cannot succeed. (A previous
 *    version of this plugin did serve one — deliberately removed.)
 *  - No MCP server card is served. There is no public MCP server.
 *    (Also deliberately removed, together with the mcp_endpoint setting.)
 *  - The PRM's empty `authorization_servers` array is deliberate and
 *    correct: it states that no authorization server issues tokens for
 *    this resource.
 *  - {@see Discovery::advertised_urls()} exposes every URL the published
 *    documents advertise, so the test suite and the `wp bice-agents verify`
 *    CLI command can fail the moment a dead URL would be published.
 *
 * Document builders are pure functions over a config array so they can be
 * unit-tested without WordPress; {@see Discovery::register()} wires them to
 * the request.
 */
final class Discovery {

	/**
	 * Route table: URL path => builder method name.
	 */
	private const ROUTES = array(
		'/.well-known/oauth-protected-resource'                  => 'protected_resource_metadata',
		'/.well-known/agent-skills/index.json'                   => 'skills_index',
		'/.well-known/agent-skills/markdown-for-agents/SKILL.md' => 'skill_md',
		'/auth.md'                                               => 'auth_md',
	);

	/**
	 * Same-origin paths advertised in the documents that are served by other
	 * layers (verified live), not by this plugin. The self-check accepts
	 * these; anything else must match a plugin route or the check fails.
	 */
	public const EXTERNALLY_SERVED_PATHS = array(
		'/',
		'/robots.txt',
		'/.well-known/api-catalog', // RFC 9727 linkset, served by nginx.
	);

	/**
	 * Tombstones: routes this plugin used to serve and deliberately removed.
	 * They must answer a hard 404 — never a soft-404 (a 200 "not found"
	 * page) and never whatever a stale page cache replays — because an
	 * agent probing /.well-known/oauth-authorization-server and getting a
	 * 200 will try to parse it as authorization-server metadata.
	 */
	private const TOMBSTONE_ROUTES = array(
		'/.well-known/oauth-authorization-server',
		'/.well-known/mcp/server-card.json',
		'/.well-known/mcp.json',
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

		// Serve immediately (we are called at plugins_loaded): these documents
		// need no WP query, and answering this early beats security plugins
		// that 403 dot-paths on init or template_redirect. Note this still
		// cannot help when the 403 happens before WordPress runs at all
		// (nginx dotfile deny, Cloudflare WAF) — see the README.
		self::maybe_serve( $settings );
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
	 * Whether a path is a deliberately removed route that must hard-404.
	 *
	 * @param string $path URL path (no query string).
	 * @return bool
	 */
	public static function is_tombstone( string $path ): bool {
		$path = '/' . ltrim( $path, '/' );
		$path = rtrim( $path, '/' ) ?: '/';

		foreach ( self::TOMBSTONE_ROUTES as $route ) {
			if ( strtolower( $path ) === strtolower( $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Serve a discovery document and exit, when the request matches.
	 *
	 * Scope: anonymous front-end GET/HEAD only. Admin, AJAX, REST and
	 * logged-in requests fall through to normal WordPress handling.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	private static function maybe_serve( array $settings ): void {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| is_user_logged_in() ) {
			return;
		}

		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

		if ( self::is_tombstone( $path ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			http_response_code( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Not Found\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- constant string.
			exit;
		}

		$builder = self::match_route( $path );
		if ( null === $builder ) {
			return;
		}

		$config   = self::config_from_wp( $settings );
		$document = self::$builder( $config );

		// Keep the WP-level page cache out of the loop entirely (these are
		// cheap to regenerate); short public max-age still lets edges cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
		header_remove( 'Expires' );
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
		$contact = trim( (string) ( $settings['contact_email'] ?? '' ) );
		if ( '' === $contact ) {
			$contact = (string) get_option( 'admin_email' );
		}

		$signal = '';
		if ( ! empty( $settings['content_signals_enabled'] ) ) {
			$signal = ContentSignals::signal_line( (array) ( $settings['content_signals'] ?? array() ) );
		}

		return array(
			'site_url'              => untrailingslashit( home_url( '/', 'https' ) ),
			'site_name'             => (string) get_bloginfo( 'name' ),
			'contact_email'         => $contact,
			'version'               => defined( 'BICE_MDA_VERSION' ) ? BICE_MDA_VERSION : '0',
			'authorization_servers' => (array) ( $settings['oauth_authorization_servers'] ?? array() ),
			'scopes'                => (array) ( $settings['oauth_scopes'] ?? array() ),
			'register_uri'          => (string) ( $settings['agent_register_uri'] ?? '' ),
			'policy_uri'            => (string) ( $settings['resource_policy_uri'] ?? '' ),
			'tos_uri'               => (string) ( $settings['resource_tos_uri'] ?? '' ),
			'md_suffix'             => ! empty( $settings['md_suffix'] ),
			'content_signal'        => $signal,
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

		$metadata = array(
			'resource'                 => $site,
			// Empty is deliberate and correct when no authorization server
			// issues tokens for this resource — see the class docblock.
			// Defence in depth: even a corrupted stored option must never
			// publish an implausible issuer (e.g. the "http://Array" artifact).
			'authorization_servers'    => array_values(
				array_filter( (array) $config['authorization_servers'], array( Settings::class, 'is_valid_issuer' ) )
			),
			'scopes_supported'         => array_values( (array) $config['scopes'] ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => (string) $config['site_name'],
			'resource_documentation'   => $site . '/auth.md',
		);

		if ( '' !== (string) $config['policy_uri'] ) {
			$metadata['resource_policy_uri'] = (string) $config['policy_uri'];
		}
		if ( '' !== (string) $config['tos_uri'] ) {
			$metadata['resource_tos_uri'] = (string) $config['tos_uri'];
		}

		return $metadata;
	}

	/**
	 * Agent Skills Discovery index (RFC v0.2.0).
	 *
	 * The single skill it lists is served by this same plugin and documents
	 * only capabilities that genuinely work (Markdown negotiation); the
	 * sha256 digest is computed from the exact bytes served.
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
			// Best-effort schema URL for the draft RFC.
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
		$site = rtrim( (string) $config['site_url'], '/' );
		$name = (string) $config['site_name'];
		// No example URL here on purpose: a placeholder slug would be a dead
		// link, and discovery documents must not advertise URLs that 404.
		$suffix = ! empty( $config['md_suffix'] )
			? "\nAny page is also available at its `index.md` suffix: append `index.md` to the page's path (a page at `/some-page/` is mirrored at `/some-page/index.md`).\n"
			: '';

		$signal_section = '' !== (string) $config['content_signal']
			? "\n## Content-usage policy\n\nRespect the `Content-Signal` directive in {$site}/robots.txt\n(see https://contentsignals.org/): `" . (string) $config['content_signal'] . "`\n"
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
{$signal_section}
MD . "\n";
	}

	/**
	 * The /auth.md document (workos.com/auth-md): agent registration and
	 * authentication instructions in human- and machine-readable Markdown.
	 *
	 * Every URL in the Discover table resolves on the live site — the table
	 * lists only the Protected Resource Metadata (served here) and the
	 * RFC 9727 API catalog (served by nginx). Filterable via
	 * `bice_mda_auth_md` for sites that want custom content.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return string Markdown.
	 */
	public static function auth_md( array $config ): string {
		$site     = rtrim( (string) $config['site_url'], '/' );
		$name     = (string) $config['site_name'];
		$email    = (string) $config['contact_email'];
		$servers  = array_values(
			array_filter( (array) $config['authorization_servers'], array( Settings::class, 'is_valid_issuer' ) )
		);
		$register = (string) $config['register_uri'];

		$auth_section = array() !== $servers
			? "Tokens for protected resources are issued by:\n\n"
				. implode( "\n", array_map( static fn( $s ) => '- ' . $s, $servers ) )
				. "\n\nUse the Protected Resource Metadata to identify the resource and scopes, then send the token as `Authorization: Bearer <token>`."
			: "All published content on this site is public and requires **no authentication** — you can skip registration and read everything right now. There is no OAuth authorization server issuing tokens for this resource; the Protected Resource Metadata states that with an empty `authorization_servers` list.";

		$register_section = '' !== $register
			? "Register as an agent at: {$register}"
			: 'No self-service agent registration is currently open. For elevated or programmatic access (APIs, reservations, partnerships), contact the site operator'
				. ( '' !== $email ? " at {$email}." : '.' );

		$interpretation = self::signal_interpretation( (string) $config['content_signal'] );
		$signal_section = '' !== (string) $config['content_signal']
			? "\n## Content-usage policy\n\nRespect the `Content-Signal` directive in {$site}/robots.txt\n(https://contentsignals.org/): `" . (string) $config['content_signal'] . '`'
				. ( '' !== $interpretation ? " — {$interpretation}" : '' ) . "\n"
			: '';

		$markdown = <<<MD
# auth.md

You are an agent reading **{$name}**. This document follows the auth.md
convention (https://workos.com/auth-md): discover → access → register.
Every URL below resolves; nothing aspirational is listed.

## Step 1 — Discover

| Document | URL |
|---|---|
| Protected Resource Metadata (RFC 9728) | {$site}/.well-known/oauth-protected-resource |
| API catalog (RFC 9727 linkset) | {$site}/.well-known/api-catalog |

## Step 2 — Access content

{$auth_section}

Every page is available as Markdown via content negotiation — send
`Accept: text/markdown`. Full recipe:
{$site}/.well-known/agent-skills/markdown-for-agents/SKILL.md

## Step 3 — Register

{$register_section}
{$signal_section}
MD . "\n";

		if ( function_exists( 'apply_filters' ) ) {
			$markdown = (string) apply_filters( 'bice_mda_auth_md', $markdown, $config );
		}

		return $markdown;
	}

	/**
	 * Human-readable interpretation of a Content-Signal directive, derived
	 * from the actual configured values so the prose can never contradict
	 * the directive (e.g. claiming training is not permitted while the
	 * directive says ai-train=yes).
	 *
	 * @param string $signal_line e.g. "Content-Signal: search=yes, ai-train=no".
	 * @return string Sentence fragment, '' when nothing to interpret.
	 */
	public static function signal_interpretation( string $signal_line ): string {
		$phrases = array(
			'search'   => array(
				'yes' => 'search indexing is welcome',
				'no'  => 'search indexing is not permitted',
			),
			'ai-input' => array(
				'yes' => 'AI answer-time use is welcome',
				'no'  => 'AI answer-time use is not permitted',
			),
			'ai-train' => array(
				'yes' => 'training use is permitted',
				'no'  => 'training use is not permitted',
			),
		);

		$parts = array();
		if ( preg_match_all( '/(search|ai-input|ai-train)\s*=\s*(yes|no)/i', $signal_line, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$parts[] = $phrases[ strtolower( $match[1] ) ][ strtolower( $match[2] ) ];
			}
		}

		return implode( '; ', $parts );
	}

	// ------------------------------------------------------------------
	// Self-check support.
	// ------------------------------------------------------------------

	/**
	 * Every URL the published discovery documents advertise.
	 *
	 * This is what makes the plugin structurally unable to advertise a dead
	 * link: the test suite asserts each same-origin URL maps to a served
	 * route or a verified externally-served path, and `wp bice-agents
	 * verify` probes each one live for 200 + a sane content type.
	 *
	 * Excluded on purpose: mailto: contacts, and the configured
	 * registration URI — per the auth.md spec, probing registration
	 * endpoints can create accounts or issue credentials. Discovery
	 * documents only.
	 *
	 * @param array<string, mixed> $config Site config.
	 * @return string[] Deduplicated absolute URLs.
	 */
	public static function advertised_urls( array $config ): array {
		$encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';

		$documents = array(
			self::auth_md( $config ),
			self::skill_md( $config ),
			$encode( self::protected_resource_metadata( $config ) ),
			$encode( self::skills_index( $config ) ),
		);

		$urls = array();
		foreach ( $documents as $document ) {
			if ( ! is_string( $document ) ) {
				continue;
			}
			preg_match_all( '#https?://[^\s()<>"\'`|\\\\]+#i', $document, $matches );
			foreach ( $matches[0] as $url ) {
				$urls[ rtrim( $url, '.,;:' ) ] = true;
			}
		}

		$register = (string) $config['register_uri'];

		$urls = array_keys( $urls );
		$urls = array_filter(
			$urls,
			static function ( string $url ) use ( $register ): bool {
				if ( '' !== $register && rtrim( $url, '/' ) === rtrim( $register, '/' ) ) {
					return false; // Never probe registration endpoints.
				}
				// Spec/reference links to third-party sites are documentation,
				// not capability claims about this origin.
				foreach ( array( 'https://workos.com', 'https://contentsignals.org', 'https://agentskills.io' ) as $external_doc ) {
					if ( str_starts_with( $url, $external_doc ) ) {
						return false;
					}
				}
				return true;
			}
		);

		sort( $urls );

		return array_values( $urls );
	}
}
