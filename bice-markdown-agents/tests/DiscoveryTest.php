<?php
/**
 * Tests for the agent discovery document builders, routing, and the
 * structural self-check that keeps the plugin unable to advertise a URL
 * that nothing serves.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

namespace Bice\MarkdownAgents\Tests;

use Bice\MarkdownAgents\Discovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase {

	/**
	 * Config for a site with no OAuth infrastructure (the default, and the
	 * live truth for the target site).
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private static function config( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_url'              => 'https://example.test',
				'site_name'             => 'Maison Bice',
				'contact_email'         => 'info@example.test',
				'version'               => '1.3.0',
				'authorization_servers' => array(),
				'scopes'                => array(),
				'register_uri'          => '',
				'policy_uri'            => '',
				'tos_uri'               => '',
				'md_suffix'             => true,
				'content_signal'        => 'Content-Signal: search=yes, ai-input=yes, ai-train=no',
			),
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// Routing: what is served, and — just as important — what is not.
	// ------------------------------------------------------------------

	#[DataProvider( 'known_routes' )]
	public function test_known_routes_match( string $path, string $builder ): void {
		$this->assertSame( $builder, Discovery::match_route( $path ) );
	}

	public static function known_routes(): array {
		return array(
			array( '/.well-known/oauth-protected-resource', 'protected_resource_metadata' ),
			array( '/.well-known/agent-skills/index.json', 'skills_index' ),
			array( '/.well-known/agent-skills/markdown-for-agents/SKILL.md', 'skill_md' ),
			array( '/auth.md', 'auth_md' ),
			// Trailing slash and case tolerance.
			array( '/.well-known/oauth-protected-resource/', 'protected_resource_metadata' ),
			array( '/AUTH.MD', 'auth_md' ),
		);
	}

	#[DataProvider( 'removed_and_unknown_routes' )]
	public function test_removed_and_unknown_routes_do_not_match( string $path ): void {
		$this->assertNull( Discovery::match_route( $path ) );
	}

	#[DataProvider( 'tombstone_routes' )]
	public function test_removed_routes_are_tombstoned_to_hard_404( string $path ): void {
		// Removed routes must hard-404 even on soft-404 themes: an agent
		// receiving a 200 would try to parse the body as metadata.
		$this->assertTrue( Discovery::is_tombstone( $path ) );
		$this->assertNull( Discovery::match_route( $path ) );
	}

	public static function tombstone_routes(): array {
		return array(
			array( '/.well-known/oauth-authorization-server' ),
			array( '/.well-known/oauth-authorization-server/' ),
			array( '/.well-known/mcp/server-card.json' ),
			array( '/.well-known/mcp.json' ),
		);
	}

	public function test_live_routes_are_not_tombstones(): void {
		$this->assertFalse( Discovery::is_tombstone( '/auth.md' ) );
		$this->assertFalse( Discovery::is_tombstone( '/.well-known/oauth-protected-resource' ) );
		$this->assertFalse( Discovery::is_tombstone( '/.well-known/agent-skills/index.json' ) );
		$this->assertFalse( Discovery::is_tombstone( '/traiteur/' ) );
	}

	public static function removed_and_unknown_routes(): array {
		return array(
			// Deliberately removed: a fabricated authorization server invites
			// agents into OAuth flows that cannot succeed.
			array( '/.well-known/oauth-authorization-server' ),
			// Deliberately removed: no public MCP server exists.
			array( '/.well-known/mcp/server-card.json' ),
			array( '/.well-known/mcp.json' ),
			// Never routes.
			array( '/' ),
			array( '/traiteur/' ),
			array( '/auth.md.bak' ),
			array( '/xauth.md' ),
			array( '/.well-known/' ),
			array( '/.well-known/security.txt' ),
			array( '/.well-known/api-catalog' ), // nginx serves this, not us.
			array( '/page/auth.md' ),
		);
	}

	// ------------------------------------------------------------------
	// RFC 9728 protected resource metadata.
	// ------------------------------------------------------------------

	public function test_protected_resource_metadata_shape(): void {
		$doc = Discovery::protected_resource_metadata( self::config() );

		$this->assertSame( 'https://example.test', $doc['resource'] );
		// Empty is deliberate: no authorization server issues tokens here.
		$this->assertSame( array(), $doc['authorization_servers'] );
		$this->assertSame( array(), $doc['scopes_supported'] );
		$this->assertSame( array( 'header' ), $doc['bearer_methods_supported'] );
		$this->assertSame( 'Maison Bice', $doc['resource_name'] );
		$this->assertSame( 'https://example.test/auth.md', $doc['resource_documentation'] );
		// Optional URIs omitted rather than emitted empty.
		$this->assertArrayNotHasKey( 'resource_policy_uri', $doc );
		$this->assertArrayNotHasKey( 'resource_tos_uri', $doc );
	}

	public function test_protected_resource_metadata_with_policy_and_tos(): void {
		$doc = Discovery::protected_resource_metadata(
			self::config(
				array(
					'policy_uri' => 'https://example.test/conditions-utilisation/',
					'tos_uri'    => 'https://example.test/conditions-utilisation/',
				)
			)
		);

		$this->assertSame( 'https://example.test/conditions-utilisation/', $doc['resource_policy_uri'] );
		$this->assertSame( 'https://example.test/conditions-utilisation/', $doc['resource_tos_uri'] );
	}

	public function test_protected_resource_metadata_with_issuers_and_scopes(): void {
		$doc = Discovery::protected_resource_metadata(
			self::config(
				array(
					'authorization_servers' => array( 'https://auth.example.test' ),
					'scopes'                => array( 'read', 'reservations' ),
				)
			)
		);

		$this->assertSame( array( 'https://auth.example.test' ), $doc['authorization_servers'] );
		$this->assertSame( array( 'read', 'reservations' ), $doc['scopes_supported'] );
	}

	// ------------------------------------------------------------------
	// Agent skills index + SKILL.md.
	// ------------------------------------------------------------------

	public function test_skills_index_shape(): void {
		$index = Discovery::skills_index( self::config() );

		$this->assertArrayHasKey( '$schema', $index );
		$this->assertSame( '0.2.0', $index['version'] );
		$this->assertCount( 1, $index['skills'] );

		$skill = $index['skills'][0];
		foreach ( array( 'name', 'type', 'description', 'url', 'sha256' ) as $key ) {
			$this->assertArrayHasKey( $key, $skill );
			$this->assertNotSame( '', $skill[ $key ] );
		}
		$this->assertSame( 'markdown-for-agents', $skill['name'] );
		$this->assertSame(
			'https://example.test/.well-known/agent-skills/markdown-for-agents/SKILL.md',
			$skill['url']
		);
	}

	public function test_skills_index_digest_matches_served_skill_md(): void {
		$config = self::config();
		$index  = Discovery::skills_index( $config );

		$this->assertSame(
			hash( 'sha256', Discovery::skill_md( $config ) ),
			$index['skills'][0]['sha256']
		);
	}

	public function test_skill_md_documents_markdown_negotiation(): void {
		$md = Discovery::skill_md( self::config() );

		$this->assertStringStartsWith( "---\n", $md );
		$this->assertStringContainsString( 'Accept: text/markdown', $md );
		$this->assertStringContainsString( 'index.md', $md );
		$this->assertStringContainsString( 'https://example.test/robots.txt', $md );
	}

	public function test_skill_md_has_no_placeholder_example_urls(): void {
		// A placeholder like /example-page/index.md would be a dead link —
		// discovery documents must not advertise URLs that 404.
		$md = Discovery::skill_md( self::config() );

		$this->assertStringNotContainsString( 'example-page', $md );
		$this->assertDoesNotMatchRegularExpression(
			'#https://example\.test/[^\s`)]*index\.md#',
			$md,
			'No concrete index.md URL may be advertised; the suffix rule is described generically.'
		);
	}

	public function test_skill_md_omits_suffix_section_when_disabled(): void {
		$md = Discovery::skill_md( self::config( array( 'md_suffix' => false ) ) );

		$this->assertStringNotContainsString( 'index.md', $md );
	}

	// ------------------------------------------------------------------
	// auth.md.
	// ------------------------------------------------------------------

	public function test_auth_md_starts_with_canonical_heading(): void {
		// The auth.md convention (and scanners) expect the H1 to be "# auth.md".
		$md = Discovery::auth_md( self::config() );

		$this->assertStringStartsWith( "# auth.md\n", $md );
		$this->assertSame( 1, preg_match_all( '/^# /m', $md ), 'Exactly one H1.' );
	}

	public function test_auth_md_discover_table_lists_only_resolving_documents(): void {
		$md = Discovery::auth_md( self::config() );

		$this->assertStringContainsString( '/.well-known/oauth-protected-resource', $md );
		$this->assertStringContainsString( '/.well-known/api-catalog', $md );
		// Deliberately absent: nothing may point agents at infrastructure
		// that does not exist.
		$this->assertStringNotContainsString( 'oauth-authorization-server', $md );
		$this->assertStringNotContainsString( 'server-card', $md );
		$this->assertStringNotContainsString( 'mcp', strtolower( $md ) );
		$this->assertStringNotContainsString( 'agent-skills/index.json', $md );
		$this->assertStringNotContainsString( 'agent_auth', $md );
	}

	public function test_auth_md_default_states_public_access_and_contact(): void {
		$md = Discovery::auth_md( self::config() );

		$this->assertStringContainsString( 'Maison Bice', $md );
		$this->assertStringContainsString( 'no authentication', $md );
		$this->assertStringContainsString( 'info@example.test', $md );
		$this->assertStringContainsString( 'No self-service agent registration is currently open', $md );
	}

	public function test_auth_md_content_signal_matches_robots_policy(): void {
		$md = Discovery::auth_md( self::config() );

		$this->assertStringContainsString( 'Content-Signal: search=yes, ai-input=yes, ai-train=no', $md );
		$this->assertStringContainsString( 'training use is not permitted', $md );
	}

	public function test_auth_md_interpretation_follows_the_actual_policy(): void {
		// The prose is derived from the directive, so it can never contradict
		// robots.txt — with ai-train=yes it must NOT claim training is banned.
		$md = Discovery::auth_md(
			self::config( array( 'content_signal' => 'Content-Signal: search=yes, ai-input=yes, ai-train=yes' ) )
		);

		$this->assertStringContainsString( 'training use is permitted', $md );
		$this->assertStringNotContainsString( 'training use is not permitted', $md );
	}

	public function test_signal_interpretation_parses_each_signal(): void {
		$this->assertSame(
			'search indexing is welcome; AI answer-time use is welcome; training use is not permitted',
			Discovery::signal_interpretation( 'Content-Signal: search=yes, ai-input=yes, ai-train=no' )
		);
		$this->assertSame( '', Discovery::signal_interpretation( '' ) );
	}

	public function test_corrupted_issuer_artifact_never_reaches_published_documents(): void {
		// Even if a corrupted option value slips past Settings::heal(), the
		// builders themselves must refuse to publish an implausible issuer.
		$config = self::config( array( 'authorization_servers' => array( 'http://Array' ) ) );

		$prm = Discovery::protected_resource_metadata( $config );
		$this->assertSame( array(), $prm['authorization_servers'] );

		$md = Discovery::auth_md( $config );
		$this->assertStringNotContainsString( 'http://Array', $md );
		$this->assertStringContainsString( 'no authentication', $md );
	}

	public function test_auth_md_omits_signal_section_when_no_signal(): void {
		$md = Discovery::auth_md( self::config( array( 'content_signal' => '' ) ) );

		$this->assertStringNotContainsString( 'Content-usage policy', $md );
	}

	public function test_auth_md_lists_configured_issuers_and_register_uri(): void {
		$md = Discovery::auth_md(
			self::config(
				array(
					'authorization_servers' => array( 'https://auth.example.test' ),
					'register_uri'          => 'https://example.test/agents/register',
				)
			)
		);

		$this->assertStringContainsString( '- https://auth.example.test', $md );
		$this->assertStringContainsString( 'https://example.test/agents/register', $md );
		$this->assertStringNotContainsString( 'no self-service agent registration', strtolower( $md ) );
	}

	// ------------------------------------------------------------------
	// The structural self-check: the plugin must be unable to advertise a
	// URL that nothing serves.
	// ------------------------------------------------------------------

	public function test_every_advertised_same_origin_url_is_served_or_externally_verified(): void {
		$config = self::config(
			array(
				'policy_uri' => 'https://example.test/conditions-utilisation/',
				'tos_uri'    => 'https://example.test/conditions-utilisation/',
			)
		);

		$urls = Discovery::advertised_urls( $config );
		$this->assertNotEmpty( $urls );

		// Same-origin pages other than the discovery routes (policy/ToS pages)
		// are real WordPress pages on the live site; they are configured by
		// the operator and verified live by `wp bice-agents verify`. Here we
		// assert the structural invariant for everything the plugin itself
		// is responsible for serving.
		$operator_configured = array(
			'/conditions-utilisation',
		);

		foreach ( $urls as $url ) {
			if ( ! str_starts_with( $url, 'https://example.test' ) ) {
				$this->fail( "Non-origin URL advertised as a capability: {$url}" );
			}

			$path = (string) parse_url( $url, PHP_URL_PATH );
			$path = '' === $path ? '/' : $path;
			$norm = rtrim( $path, '/' ) ?: '/';

			$served     = null !== Discovery::match_route( $path );
			$external   = in_array( $norm, array_map( static fn( $p ) => rtrim( $p, '/' ) ?: '/', Discovery::EXTERNALLY_SERVED_PATHS ), true );
			$configured = in_array( $norm, $operator_configured, true );

			$this->assertTrue(
				$served || $external || $configured,
				"Advertised URL {$url} is neither served by the plugin, externally verified, nor operator-configured — a dead link would be published."
			);
		}
	}

	public function test_advertised_urls_excludes_registration_endpoint(): void {
		// Per the auth.md spec, probing registration endpoints can create
		// accounts or issue credentials — the self-check must never hit them.
		$urls = Discovery::advertised_urls(
			self::config( array( 'register_uri' => 'https://example.test/agents/register' ) )
		);

		$this->assertNotContains( 'https://example.test/agents/register', $urls );
	}

	public function test_advertised_urls_excludes_third_party_documentation_links(): void {
		$urls = Discovery::advertised_urls( self::config() );

		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( 'workos.com', $url );
			$this->assertStringNotContainsString( 'contentsignals.org', $url );
			$this->assertStringNotContainsString( 'agentskills.io', $url );
		}
	}

	public function test_no_document_references_admin_connectors_or_credentials(): void {
		$config    = self::config();
		$documents = implode(
			"\n",
			array(
				Discovery::auth_md( $config ),
				Discovery::skill_md( $config ),
				(string) json_encode( Discovery::protected_resource_metadata( $config ) ),
				(string) json_encode( Discovery::skills_index( $config ) ),
			)
		);

		foreach ( array( 'stifli', 'easy-mcp', 'flex-mcp', 'token=', 'key=', 'secret' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $documents );
		}
	}
}
