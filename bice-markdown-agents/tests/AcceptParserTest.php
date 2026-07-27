<?php
/**
 * Tests for the RFC 9110 Accept parser — the component where the
 * site-breaking risk lives. A false positive here serves Markdown to a
 * human browser.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

namespace Bice\MarkdownAgents\Tests;

use Bice\MarkdownAgents\AcceptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptParserTest extends TestCase {

	// ------------------------------------------------------------------
	// The critical safety cases: real browser headers MUST get HTML.
	// ------------------------------------------------------------------

	#[DataProvider( 'real_browser_headers' )]
	public function test_real_browser_headers_never_get_markdown( string $header ): void {
		$this->assertFalse( AcceptParser::prefers_markdown( $header ) );
	}

	public static function real_browser_headers(): array {
		return array(
			'Chrome/Edge'         => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7' ),
			'Chrome (spec)'       => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8' ),
			'Firefox'             => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/png,image/svg+xml,*/*;q=0.8' ),
			'Safari'              => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ),
			'Old IE'              => array( '*/*' ),
			'curl default'        => array( '*/*' ),
			'Googlebot'           => array( 'text/html,application/xhtml+xml,application/signed-exchange;v=b3,application/xml;q=0.9,*/*;q=0.8' ),
			'prefetch'            => array( 'text/html' ),
			'image request'       => array( 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8' ),
		);
	}

	// ------------------------------------------------------------------
	// Wildcards must never trigger Markdown.
	// ------------------------------------------------------------------

	#[DataProvider( 'wildcard_headers' )]
	public function test_wildcards_do_not_trigger_markdown( string $header ): void {
		$this->assertFalse( AcceptParser::prefers_markdown( $header ) );
	}

	public static function wildcard_headers(): array {
		return array(
			'full wildcard'            => array( '*/*' ),
			'full wildcard with q'     => array( '*/*;q=1' ),
			'text wildcard'            => array( 'text/*' ),
			'text wildcard high q'     => array( 'text/*;q=1.0' ),
			'wildcards only'           => array( 'text/*, */*;q=0.5' ),
			'invalid */subtype range'  => array( '*/markdown' ),
		);
	}

	// ------------------------------------------------------------------
	// Genuine agent requests get Markdown.
	// ------------------------------------------------------------------

	#[DataProvider( 'markdown_preferring_headers' )]
	public function test_agent_headers_get_markdown( string $header ): void {
		$this->assertTrue( AcceptParser::prefers_markdown( $header ) );
	}

	public static function markdown_preferring_headers(): array {
		return array(
			'bare'                        => array( 'text/markdown' ),
			'uppercase'                   => array( 'TEXT/MARKDOWN' ),
			'mixed case'                  => array( 'Text/Markdown' ),
			'with charset param'          => array( 'text/markdown;charset=utf-8' ),
			'md then wildcard fallback'   => array( 'text/markdown, */*;q=0.8' ),
			'md above html'               => array( 'text/markdown, text/html;q=0.9' ),
			'md above html with spaces'   => array( ' text/markdown , text/html ; q=0.9 ' ),
			'explicit q=1'                => array( 'text/markdown;q=1' ),
			'explicit q=1.000'            => array( 'text/markdown;q=1.000' ),
			'md with plain-text fallback' => array( 'text/markdown,text/plain;q=0.9,*/*;q=0.1' ),
		);
	}

	// ------------------------------------------------------------------
	// q-value ties and orderings.
	// ------------------------------------------------------------------

	public function test_tie_between_markdown_and_html_prefers_markdown(): void {
		// Spec: markdown wins when q(markdown) >= q(html).
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/markdown, text/html' ) );
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/html, text/markdown' ) );
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/html;q=0.5, text/markdown;q=0.5' ) );
	}

	public function test_html_preferred_over_markdown_gets_html(): void {
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/html, text/markdown;q=0.9' ) );
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/markdown;q=0.1, text/html;q=0.2' ) );
	}

	public function test_html_q_via_wildcard_counts_in_comparison(): void {
		// html is reachable through */* at q=0.9, markdown explicit at 0.5 → HTML.
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/markdown;q=0.5, */*;q=0.9' ) );
		// html via text/* at 0.4 < markdown 0.5 → Markdown.
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/markdown;q=0.5, text/*;q=0.4' ) );
	}

	public function test_exact_html_beats_wildcard_for_html_q(): void {
		// text/html;q=0.3 explicit, */*;q=0.9 — exact match wins, so html q is 0.3.
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/markdown;q=0.5, text/html;q=0.3, */*;q=0.9' ) );
	}

	public function test_markdown_q_zero_is_never_served(): void {
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/markdown;q=0' ) );
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/markdown;q=0.000' ) );
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/markdown;q=0, text/html;q=0' ) );
	}

	public function test_duplicate_listings_use_highest_q(): void {
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/markdown;q=0.1, text/markdown;q=1, text/html;q=0.9' ) );
	}

	// ------------------------------------------------------------------
	// Malformed input: always fail safe to HTML.
	// ------------------------------------------------------------------

	#[DataProvider( 'malformed_headers' )]
	public function test_malformed_input_falls_back_to_html( ?string $header ): void {
		$this->assertFalse( AcceptParser::prefers_markdown( $header ) );
	}

	public static function malformed_headers(): array {
		return array(
			'null'                   => array( null ),
			'empty'                  => array( '' ),
			'whitespace'             => array( '   ' ),
			'garbage'                => array( 'not-a-media-type' ),
			'lone slash'             => array( '/' ),
			'missing subtype'        => array( 'text/' ),
			'missing type'           => array( '/markdown' ),
			'only commas'            => array( ',,,' ),
			'only semicolons'        => array( ';;;' ),
			'bare q param'           => array( ';q=1' ),
			'markdown with bad q'    => array( 'text/markdown;q=abc' ),
			'markdown with q>1'      => array( 'text/markdown;q=2' ),
			'markdown q 4 decimals'  => array( 'text/markdown;q=0.9999' ),
			'markdown negative q'    => array( 'text/markdown;q=-1' ),
			'markdown empty q'       => array( 'text/markdown;q=' ),
			'spaces inside range'    => array( 'text / markdown' ),
			'control characters'     => array( "text/markdown\x00" ),
			'markdown as param only' => array( 'text/html;profile=text/markdown' ),
		);
	}

	public function test_malformed_entries_do_not_poison_valid_ones(): void {
		// Garbage entries are dropped; the valid markdown listing still works.
		$this->assertTrue( AcceptParser::prefers_markdown( 'garbage, text/markdown, also;;garbage' ) );
		// And a valid browser-ish header with junk still gets HTML.
		$this->assertFalse( AcceptParser::prefers_markdown( 'junk, text/html, more junk' ) );
	}

	public function test_quoted_parameter_values_with_commas_do_not_split_entries(): void {
		// A quoted param containing a comma and a fake markdown listing must not
		// create a phantom text/markdown entry.
		$this->assertFalse(
			AcceptParser::prefers_markdown( 'text/html;note="a,text/markdown,b", */*;q=0.8' )
		);
	}

	public function test_accept_ext_after_q_is_ignored(): void {
		// Parameters after q are accept-ext and must not affect the q-value.
		$this->assertTrue( AcceptParser::prefers_markdown( 'text/markdown;q=1;ext=q=0' ) );
		$this->assertFalse( AcceptParser::prefers_markdown( 'text/html;q=1;v=b3, text/markdown;q=0.9' ) );
	}

	// ------------------------------------------------------------------
	// Parser internals.
	// ------------------------------------------------------------------

	public function test_parse_extracts_types_and_qvalues(): void {
		$entries = AcceptParser::parse( 'text/html,application/xml;q=0.9,*/*;q=0.8' );

		$this->assertCount( 3, $entries );
		$this->assertSame( array( 'type' => 'text', 'subtype' => 'html', 'q' => 1.0 ), $entries[0] );
		$this->assertSame( array( 'type' => 'application', 'subtype' => 'xml', 'q' => 0.9 ), $entries[1] );
		$this->assertSame( array( 'type' => '*', 'subtype' => '*', 'q' => 0.8 ), $entries[2] );
	}

	public function test_effective_q_specificity_order(): void {
		$entries = AcceptParser::parse( 'text/html;q=0.2, text/*;q=0.5, */*;q=0.9' );

		$this->assertSame( 0.2, AcceptParser::effective_q( $entries, 'text', 'html' ) );
		$this->assertSame( 0.5, AcceptParser::effective_q( $entries, 'text', 'plain' ) );
		$this->assertSame( 0.9, AcceptParser::effective_q( $entries, 'image', 'png' ) );
	}

	public function test_effective_q_absent_type_is_zero(): void {
		$entries = AcceptParser::parse( 'application/json' );
		$this->assertSame( 0.0, AcceptParser::effective_q( $entries, 'text', 'html' ) );
	}
}
