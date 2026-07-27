<?php
/**
 * Tests for the Content Signals robots.txt logic.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

namespace Bice\MarkdownAgents\Tests;

use Bice\MarkdownAgents\ContentSignals;
use PHPUnit\Framework\TestCase;

final class ContentSignalsTest extends TestCase {

	private const POLICY_LINE = 'Content-Signal: search=yes, ai-input=yes, ai-train=no';

	/**
	 * robots.txt as WordPress + WooCommerce + Rank Math typically emit it.
	 */
	private const WP_ROBOTS = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /cart/\nDisallow: /checkout/\n\nSitemap: https://example.test/sitemap_index.xml\n";

	// ------------------------------------------------------------------
	// Directive building.
	// ------------------------------------------------------------------

	public function test_signal_line_from_full_policy(): void {
		$line = ContentSignals::signal_line(
			array(
				'search'   => 'yes',
				'ai-input' => 'yes',
				'ai-train' => 'no',
			)
		);
		$this->assertSame( self::POLICY_LINE, $line );
	}

	public function test_signal_line_skips_absent_signals(): void {
		$this->assertSame(
			'Content-Signal: ai-train=no',
			ContentSignals::signal_line( array( 'ai-train' => 'no' ) )
		);
		$this->assertSame(
			'Content-Signal: search=yes, ai-train=no',
			ContentSignals::signal_line(
				array(
					'search'   => 'yes',
					'ai-input' => '',
					'ai-train' => 'no',
				)
			)
		);
	}

	public function test_signal_line_empty_when_no_preference_expressed(): void {
		$this->assertSame( '', ContentSignals::signal_line( array() ) );
		$this->assertSame( '', ContentSignals::signal_line( array( 'search' => '', 'ai-input' => '' ) ) );
	}

	public function test_signal_line_rejects_invalid_values_and_normalizes_case(): void {
		$this->assertSame(
			'Content-Signal: search=yes',
			ContentSignals::signal_line(
				array(
					'search'   => ' YES ',
					'ai-input' => 'maybe',
					'ai-train' => '1',
				)
			)
		);
	}

	public function test_signal_line_ignores_unknown_signal_names(): void {
		$this->assertSame(
			'Content-Signal: search=yes',
			ContentSignals::signal_line(
				array(
					'search'  => 'yes',
					'ai-eval' => 'no',
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// Insertion.
	// ------------------------------------------------------------------

	public function test_inserts_into_existing_wildcard_group(): void {
		$result = ContentSignals::apply( self::WP_ROBOTS, self::POLICY_LINE );

		// Directive sits immediately after the wildcard user-agent line.
		$this->assertStringContainsString( "User-agent: *\n" . self::POLICY_LINE . "\n", $result );
		// Preamble comes first.
		$this->assertStringStartsWith( '# Content Signals Policy', $result );
	}

	public function test_existing_rules_are_preserved_verbatim(): void {
		$result = ContentSignals::apply( self::WP_ROBOTS, self::POLICY_LINE );

		foreach ( array(
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /cart/',
			'Disallow: /checkout/',
			'Sitemap: https://example.test/sitemap_index.xml',
		) as $rule ) {
			$this->assertStringContainsString( $rule, $result );
		}
	}

	public function test_only_first_wildcard_group_receives_the_directive(): void {
		$robots = "User-agent: *\nDisallow: /a/\n\nUser-agent: *\nDisallow: /b/\n";
		$result = ContentSignals::apply( $robots, self::POLICY_LINE );

		$this->assertSame( 1, substr_count( $result, 'Content-Signal:' ) );
	}

	public function test_creates_wildcard_group_when_none_exists(): void {
		$robots = "User-agent: Googlebot\nDisallow: /private/\n";
		$result = ContentSignals::apply( $robots, self::POLICY_LINE );

		$this->assertStringContainsString( "User-agent: *\n" . self::POLICY_LINE . "\n", $result );
		$this->assertStringContainsString( "User-agent: Googlebot\nDisallow: /private/", $result );
	}

	public function test_handles_empty_robots_output(): void {
		$result = ContentSignals::apply( '', self::POLICY_LINE );

		$this->assertStringContainsString( "User-agent: *\n" . self::POLICY_LINE . "\n", $result );
	}

	public function test_wildcard_match_tolerates_spacing_and_case(): void {
		$result = ContentSignals::apply( "user-agent:  * \nDisallow: /x/\n", self::POLICY_LINE );

		$this->assertSame( 1, substr_count( $result, 'Content-Signal:' ) );
		$this->assertStringContainsString( self::POLICY_LINE . "\nDisallow: /x/", $result );
	}

	public function test_does_not_match_non_wildcard_user_agents(): void {
		// "User-agent: *bot" style lines must not be treated as the wildcard group.
		$robots = "User-agent: *bot\nDisallow: /y/\n";
		$result = ContentSignals::apply( $robots, self::POLICY_LINE );

		// A fresh wildcard group is created instead.
		$this->assertStringContainsString( "User-agent: *\n" . self::POLICY_LINE . "\n", $result );
		$this->assertStringContainsString( "User-agent: *bot\nDisallow: /y/", $result );
	}

	// ------------------------------------------------------------------
	// Idempotency and no-ops.
	// ------------------------------------------------------------------

	public function test_apply_is_idempotent(): void {
		$once  = ContentSignals::apply( self::WP_ROBOTS, self::POLICY_LINE );
		$twice = ContentSignals::apply( $once, self::POLICY_LINE );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'Content-Signal:' ) );
	}

	public function test_respects_foreign_content_signal_lines(): void {
		$robots = "User-agent: *\ncontent-signal: ai-train=no\n";
		$this->assertSame( $robots, ContentSignals::apply( $robots, self::POLICY_LINE ) );
	}

	public function test_empty_line_is_a_no_op(): void {
		$this->assertSame( self::WP_ROBOTS, ContentSignals::apply( self::WP_ROBOTS, '' ) );
	}
}
