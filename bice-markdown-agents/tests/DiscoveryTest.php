<?php
/**
 * Tests for the agent discovery document builders and routing.
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
	 * Config for a site with no OAuth infrastructure (the default).
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private static function config( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_url'              => 'https://example.test',
				'site_name'             => 'Maison Bice',
				'site_description'      => 'Traiteur haut de gamme à Montréal',
				'admin_email'           => 'info@example.test',
				'version'               => '1.2.0',
				'authorization_servers' => array(),
				'scopes'                => array(),
				'register_uri'          => '',
				'mcp_endpoint'          => '',
				'md_suffix'             => true,
			),
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// Routing.
	// ------------------------------------------------------------------

	#[DataProvider( 'known_routes' )]
	public function test_known_routes_match( string $path, string $builder ): void {
		$this->assertSame( $builder, Discovery::match_route( $path ) );
	}

	public static function known_routes(): array {
		return array(
			array( '/.well-known/oauth-protected-resource', 'protected_resource_metadata' ),
			array( '/.well-known/oauth-authorization-server', 'authorization_server_metadata' ),
			array( '/.well-known/mcp/server-card.json', 'server_card' ),
			array( '/.well-known/agent-skills/index.json', 'skills_index' ),
			array( '/.well-known/agent-skills/markdown-for-agents/SKILL.md', 'skill_md' ),
			array( '/auth.md', 'auth_md' ),
			// Trailing slash and case tolerance.
			array( '/.well-known/oauth-protected-resource/', 'protected_resource_metadata' ),
			array( '/AUTH.MD', 'auth_md' ),
		);
	}

	#[DataProvider( 'unknown_routes' )]
	public function test_unknown_routes_do_not_match( string $path ): void {
		$this->assertNull( Discovery::match_route( $path ) );
	}

	public static function unknown_routes(): array {
		return array(
			array( '/' ),
			array( '/traiteur/' ),
			array( '/auth.md.bak' ),
			array( '/xauth.md' ),
			array( '/.well-known/' ),
			array( '/.well-known/security.txt' ),
			array( '/.well-known/mcp/server-card.jsonx' ),
			array( '/page/auth.md' ),
		);
	}

	// ------------------------------------------------------------------
	// RFC 9728 protected resource metadata.
	// ------------------------------------------------------------------

	public function test_protected_resource_metadata_shape(): void {
		$doc = Discovery::protected_resource_metadata( self::config() );

		$this->assertSame( 'https://example.test', $doc['resource'] );
		$this->assertSame( 'Maison Bice', $doc['resource_name'] );
		$this->assertSame( array(), $doc['authorization_servers'] );
		$this->assertSame( array(), $doc['scopes_supported'] );
		$this->assertSame( array( 'header' ), $doc['bearer_methods_supported'] );
		$this->assertSame( 'https://example.test/auth.md', $doc['resource_documentation'] );
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
	// Authorization server metadata + agent_auth block.
	// ------------------------------------------------------------------

	public function test_authorization_server_metadata_has_required_rfc8414_fields(): void {
		$doc = Discovery::authorization_server_metadata( self::config() );

		$this->assertSame( 'https://example.test', $doc['issuer'] );
		$this->assertSame( array( 'code' ), $doc['response_types_supported'] );
		$this->assertSame( 'https://example.test/auth.md', $doc['service_documentation'] );
	}

	public function test_authorization_server_metadata_restates_prm_fields(): void {
		// Per the workos/auth.md reference shape, the outer fields restate the
		// RFC 9728 Protected Resource Metadata.
		$doc = Discovery::authorization_server_metadata(
			self::config( array( 'authorization_servers' => array( 'https://auth.example.test' ) ) )
		);

		$this->assertSame( 'https://example.test', $doc['resource'] );
		$this->assertSame( array( 'https://auth.example.test' ), $doc['authorization_servers'] );
		$this->assertSame( array( 'header' ), $doc['bearer_methods_supported'] );
	}

	public function test_agent_auth_block_defaults_register_uri_to_auth_md(): void {
		$doc = Discovery::authorization_server_metadata( self::config() );

		$agent_auth = $doc['agent_auth'];
		$this->assertSame( 'https://example.test/auth.md', $agent_auth['skill'] );
		$this->assertSame( 'https://example.test/auth.md', $agent_auth['register_uri'] );
		$this->assertContains( 'anonymous', $agent_auth['identity_types_supported'] );
		$this->assertNotEmpty( $agent_auth['credential_types_supported'] );
	}

	public function test_agent_auth_block_uses_configured_register_uri_and_issuers(): void {
		$doc = Discovery::authorization_server_metadata(
			self::config(
				array(
					'register_uri'          => 'https://example.test/agents/register',
					'authorization_servers' => array( 'https://auth.example.test' ),
				)
			)
		);

		$this->assertSame( 'https://example.test/agents/register', $doc['agent_auth']['register_uri'] );
		$this->assertSame( array( 'https://auth.example.test' ), $doc['authorization_servers'] );
	}

	// ------------------------------------------------------------------
	// MCP server card.
	// ------------------------------------------------------------------

	public function test_server_card_shape_without_endpoint(): void {
		$card = Discovery::server_card( self::config() );

		$this->assertSame( 'Maison Bice', $card['serverInfo']['name'] );
		$this->assertSame( '1.2.0', $card['serverInfo']['version'] );
		$this->assertSame( 'https://example.test', $card['serverInfo']['websiteUrl'] );
		$this->assertTrue( $card['capabilities']['resources']['markdownNegotiation'] );
		// No fake transport when no real MCP endpoint is configured.
		$this->assertArrayNotHasKey( 'transport', $card );
	}

	public function test_server_card_with_configured_endpoint(): void {
		$card = Discovery::server_card(
			self::config( array( 'mcp_endpoint' => 'https://example.test/wp-json/mcp/v1' ) )
		);

		$this->assertSame( 'streamable-http', $card['transport']['type'] );
		$this->assertSame( 'https://example.test/wp-json/mcp/v1', $card['transport']['endpoint'] );
		$this->assertArrayHasKey( 'tools', $card['capabilities'] );
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

	public function test_skill_md_omits_suffix_section_when_disabled(): void {
		$md = Discovery::skill_md( self::config( array( 'md_suffix' => false ) ) );

		$this->assertStringNotContainsString( 'index.md', $md );
	}

	// ------------------------------------------------------------------
	// auth.md.
	// ------------------------------------------------------------------

	public function test_auth_md_starts_with_canonical_heading(): void {
		// The auth.md convention (and the isitagentready.com validator)
		// expects the document's H1 to be exactly "# auth.md".
		$md = Discovery::auth_md( self::config() );

		$this->assertStringStartsWith( "# auth.md\n", $md );
		$this->assertSame( 1, preg_match_all( '/^# /m', $md ), 'Exactly one H1.' );
	}

	public function test_auth_md_default_states_public_access_and_contact(): void {
		$md = Discovery::auth_md( self::config() );

		$this->assertStringContainsString( 'Maison Bice', $md );
		$this->assertStringContainsString( 'no authentication', $md );
		$this->assertStringContainsString( 'info@example.test', $md );
		$this->assertStringContainsString( '/.well-known/oauth-protected-resource', $md );
		$this->assertStringContainsString( '/.well-known/oauth-authorization-server', $md );
		$this->assertStringContainsString( '/.well-known/mcp/server-card.json', $md );
		$this->assertStringContainsString( '/.well-known/agent-skills/index.json', $md );
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
}
