<?php
/**
 * Tests for the pure parts of Settings: corruption healing, list parsing
 * and issuer validation.
 *
 * WordPress runs a register_setting sanitize callback twice when an option
 * is first created; a pre-1.3.1 sanitize() was not idempotent and stored
 * PHP's "Array" string-cast artifacts ("http://Array" issuers, "Array"
 * scopes, "/Array" paths). These tests pin the healing behaviour that keeps
 * such artifacts out of the published discovery documents.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

namespace Bice\MarkdownAgents\Tests;

use Bice\MarkdownAgents\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	// ------------------------------------------------------------------
	// Issuer validation.
	// ------------------------------------------------------------------

	public function test_valid_issuers_accepted(): void {
		$this->assertTrue( Settings::is_valid_issuer( 'https://auth.example.test' ) );
		$this->assertTrue( Settings::is_valid_issuer( 'https://auth.example.test/tenant' ) );
		$this->assertTrue( Settings::is_valid_issuer( 'http://localhost:8080' ) );
	}

	public function test_corruption_artifact_and_garbage_issuers_rejected(): void {
		// The exact artifact the double-sanitize bug stored.
		$this->assertFalse( Settings::is_valid_issuer( 'http://Array' ) );
		$this->assertFalse( Settings::is_valid_issuer( 'Array' ) );
		$this->assertFalse( Settings::is_valid_issuer( '' ) );
		$this->assertFalse( Settings::is_valid_issuer( 'ftp://auth.example.test' ) );
		$this->assertFalse( Settings::is_valid_issuer( 'https://noDotHost' ) );
	}

	// ------------------------------------------------------------------
	// List parsing (idempotence base).
	// ------------------------------------------------------------------

	public function test_parse_list_accepts_string_and_array_forms(): void {
		$this->assertSame(
			array( 'a', 'b' ),
			Settings::parse_list( "a\nb\n", '/\R+/' )
		);
		$this->assertSame(
			array( 'a', 'b' ),
			Settings::parse_list( array( ' a ', 'b', '', array( 'nested-ignored' ) ), '/\R+/' )
		);
	}

	// ------------------------------------------------------------------
	// Healing corrupted stored settings.
	// ------------------------------------------------------------------

	public function test_heal_strips_the_exact_corruption_from_production(): void {
		// The corrupted state observed live after the double-sanitize bug.
		$healed = Settings::heal(
			array(
				'oauth_authorization_servers' => array( 'http://Array' ),
				'oauth_scopes'                => array( 'Array' ),
				'excluded_paths'              => array( '/Array' ),
			)
		);

		$this->assertSame( array(), $healed['oauth_authorization_servers'] );
		$this->assertSame( array(), $healed['oauth_scopes'] );
		$this->assertSame( array(), $healed['excluded_paths'] );
	}

	public function test_heal_keeps_legitimate_values(): void {
		$healed = Settings::heal(
			array(
				'oauth_authorization_servers' => array( 'https://auth.example.test', 'http://Array' ),
				'oauth_scopes'                => array( 'read', 'Array', 'reservations' ),
				'excluded_paths'              => array( '/mentions-legales/', '/Array', '/en/private/*' ),
			)
		);

		$this->assertSame( array( 'https://auth.example.test' ), $healed['oauth_authorization_servers'] );
		$this->assertSame( array( 'read', 'reservations' ), $healed['oauth_scopes'] );
		$this->assertSame( array( '/mentions-legales/', '/en/private/*' ), $healed['excluded_paths'] );
	}

	public function test_heal_coerces_string_blobs_back_to_lists(): void {
		$healed = Settings::heal(
			array(
				'oauth_authorization_servers' => "https://auth.example.test\nhttp://Array",
				'oauth_scopes'                => 'read write',
				'excluded_paths'              => "/panier/\n/commander/",
			)
		);

		$this->assertSame( array( 'https://auth.example.test' ), $healed['oauth_authorization_servers'] );
		$this->assertSame( array( 'read', 'write' ), $healed['oauth_scopes'] );
		$this->assertSame( array( '/panier/', '/commander/' ), $healed['excluded_paths'] );
	}

	public function test_heal_is_idempotent(): void {
		$input = array(
			'oauth_authorization_servers' => array( 'https://auth.example.test' ),
			'oauth_scopes'                => array( 'read' ),
			'excluded_paths'              => array( '/panier/' ),
		);

		$once = Settings::heal( $input );
		$this->assertSame( $once, Settings::heal( $once ) );
	}
}
