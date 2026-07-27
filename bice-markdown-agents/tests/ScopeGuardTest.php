<?php
/**
 * Tests for the scope guards.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

namespace Bice\MarkdownAgents\Tests;

use Bice\MarkdownAgents\ScopeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopeGuardTest extends TestCase {

	/**
	 * A fully "open" front-end context: public GET, 200, nothing special.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private static function open_context( array $overrides = array() ): array {
		$open = array(
			'method'                => 'GET',
			'status'                => 200,
			'is_admin'              => false,
			'is_login'              => false,
			'is_rest'               => false,
			'is_xmlrpc'             => false,
			'is_ajax'               => false,
			'is_cron'               => false,
			'is_cli'                => false,
			'is_feed'               => false,
			'is_robots'             => false,
			'is_favicon'            => false,
			'is_sitemap'            => false,
			'is_trackback'          => false,
			'is_preview'            => false,
			'is_customize_preview'  => false,
			'is_404'                => false,
			'is_woo_cart'           => false,
			'is_woo_checkout'       => false,
			'is_woo_account'        => false,
			'is_user_logged_in'     => false,
			'is_password_protected' => false,
			'post_type'             => 'page',
			'excluded_post_types'   => array(),
			'path'                  => '/traiteur/',
			'excluded_paths'        => array(),
		);

		return array_merge( $open, $overrides );
	}

	public function test_public_front_end_get_is_allowed(): void {
		$this->assertTrue( ScopeGuard::allows( self::open_context() ) );
	}

	public function test_head_requests_are_allowed(): void {
		$this->assertTrue( ScopeGuard::allows( self::open_context( array( 'method' => 'HEAD' ) ) ) );
	}

	#[DataProvider( 'blocked_methods' )]
	public function test_non_get_head_methods_are_blocked( string $method ): void {
		$this->assertFalse( ScopeGuard::allows( self::open_context( array( 'method' => $method ) ) ) );
	}

	public static function blocked_methods(): array {
		return array(
			array( 'POST' ),
			array( 'PUT' ),
			array( 'DELETE' ),
			array( 'PATCH' ),
			array( 'OPTIONS' ),
			array( '' ),
		);
	}

	#[DataProvider( 'non_200_statuses' )]
	public function test_non_200_statuses_are_blocked( int $status ): void {
		$this->assertFalse( ScopeGuard::allows( self::open_context( array( 'status' => $status ) ) ) );
	}

	public static function non_200_statuses(): array {
		return array(
			array( 0 ),
			array( 201 ),
			array( 301 ),
			array( 302 ),
			array( 401 ),
			array( 403 ),
			array( 404 ),
			array( 500 ),
		);
	}

	#[DataProvider( 'blocking_flags' )]
	public function test_each_blocking_flag_blocks_on_its_own( string $flag ): void {
		$this->assertFalse(
			ScopeGuard::allows( self::open_context( array( $flag => true ) ) ),
			"Flag {$flag}=true must block Markdown."
		);
	}

	public static function blocking_flags(): array {
		return array(
			array( 'is_admin' ),
			array( 'is_login' ),
			array( 'is_rest' ),
			array( 'is_xmlrpc' ),
			array( 'is_ajax' ),
			array( 'is_cron' ),
			array( 'is_cli' ),
			array( 'is_feed' ),
			array( 'is_robots' ),
			array( 'is_favicon' ),
			array( 'is_sitemap' ),
			array( 'is_trackback' ),
			array( 'is_preview' ),
			array( 'is_customize_preview' ),
			array( 'is_404' ),
			array( 'is_woo_cart' ),
			array( 'is_woo_checkout' ),
			array( 'is_woo_account' ),
			array( 'is_user_logged_in' ),
			array( 'is_password_protected' ),
		);
	}

	public function test_missing_context_keys_fail_safe(): void {
		// An empty context must never allow Markdown.
		$this->assertFalse( ScopeGuard::allows( array() ) );
		// Even with method and status provided, unspecified flags block.
		$this->assertFalse(
			ScopeGuard::allows(
				array(
					'method' => 'GET',
					'status' => 200,
				)
			)
		);
	}

	public function test_excluded_post_type_blocks(): void {
		$ctx = self::open_context(
			array(
				'post_type'           => 'product',
				'excluded_post_types' => array( 'product' ),
			)
		);
		$this->assertFalse( ScopeGuard::allows( $ctx ) );
	}

	public function test_non_excluded_post_type_is_allowed(): void {
		$ctx = self::open_context(
			array(
				'post_type'           => 'page',
				'excluded_post_types' => array( 'product' ),
			)
		);
		$this->assertTrue( ScopeGuard::allows( $ctx ) );
	}

	public function test_excluded_exact_path_blocks(): void {
		$ctx = self::open_context(
			array(
				'path'           => '/mentions-legales/',
				'excluded_paths' => array( '/mentions-legales/' ),
			)
		);
		$this->assertFalse( ScopeGuard::allows( $ctx ) );
	}

	public function test_excluded_path_matches_with_and_without_trailing_slash(): void {
		$this->assertTrue( ScopeGuard::path_is_excluded( '/panier', array( '/panier/' ) ) );
		$this->assertTrue( ScopeGuard::path_is_excluded( '/panier/', array( '/panier' ) ) );
	}

	public function test_excluded_path_wildcard_prefix(): void {
		$rules = array( '/en/private/*' );
		$this->assertTrue( ScopeGuard::path_is_excluded( '/en/private/report/', $rules ) );
		$this->assertTrue( ScopeGuard::path_is_excluded( '/en/private/', $rules ) );
		$this->assertFalse( ScopeGuard::path_is_excluded( '/en/public/', $rules ) );
	}

	public function test_non_matching_path_rules_do_not_block(): void {
		$this->assertFalse( ScopeGuard::path_is_excluded( '/traiteur/', array( '/autre/', '/en/private/*' ) ) );
		$this->assertFalse( ScopeGuard::path_is_excluded( '/traiteur/', array() ) );
		// A rule must not match a mere substring of a different path.
		$this->assertFalse( ScopeGuard::path_is_excluded( '/traiteur-express/', array( '/traiteur' ) ) );
	}

	public function test_empty_rules_are_ignored(): void {
		$this->assertFalse( ScopeGuard::path_is_excluded( '/traiteur/', array( '', '   ' ) ) );
	}
}
