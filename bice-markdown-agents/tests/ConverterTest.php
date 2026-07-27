<?php
/**
 * Tests for the HTML → Markdown converter against a representative
 * Elementor page fixture, plus malformed-input edge cases.
 *
 * @package Bice\MarkdownAgents\Tests
 */

declare(strict_types=1);

namespace Bice\MarkdownAgents\Tests;

use Bice\MarkdownAgents\Converter;
use Bice\MarkdownAgents\TokenEstimator;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase {

	private const PAGE_URL = 'https://example.test/traiteur/';

	private static string $markdown = '';

	public static function setUpBeforeClass(): void {
		$html          = (string) file_get_contents( __DIR__ . '/fixtures/elementor-page.html' );
		self::$markdown = ( new Converter( self::PAGE_URL ) )->convert( $html );
	}

	// ------------------------------------------------------------------
	// Frontmatter.
	// ------------------------------------------------------------------

	public function test_frontmatter_is_present_with_expected_keys(): void {
		$md = self::$markdown;
		$this->assertStringStartsWith( "---\n", $md );
		$this->assertMatchesRegularExpression( '/^title: "Traiteur . Maison Bice"$/mu', $md );
		$this->assertMatchesRegularExpression( '/^description: "Service traiteur haut de gamme/mu', $md );
		$this->assertMatchesRegularExpression( '~^image: "https://example\.test/wp-content/uploads/2025/06/traiteur-hero\.jpg"$~m', $md );
		$this->assertMatchesRegularExpression( '/^lang: "fr-FR"$/m', $md );
	}

	public function test_frontmatter_omitted_when_no_meta_present(): void {
		$md = ( new Converter( self::PAGE_URL ) )->convert( '<html><body><p>Bonjour</p></body></html>' );
		$this->assertStringNotContainsString( '---', $md );
		$this->assertStringContainsString( 'Bonjour', $md );
	}

	// ------------------------------------------------------------------
	// Structure preservation.
	// ------------------------------------------------------------------

	public function test_heading_hierarchy_is_preserved(): void {
		$this->assertStringContainsString( '# Service traiteur & événements', self::$markdown );
		$this->assertStringContainsString( '## Nos formules', self::$markdown );
		$this->assertStringContainsString( '## Tarifs indicatifs', self::$markdown );
	}

	public function test_inline_emphasis_is_preserved(): void {
		$this->assertStringContainsString( '**Maison Bice**', self::$markdown );
		$this->assertStringContainsString( '*sur mesure*', self::$markdown );
	}

	public function test_links_are_absolute(): void {
		// Relative link "menus/" resolved against the page URL.
		$this->assertStringContainsString( '[carte des menus](https://example.test/traiteur/menus/)', self::$markdown );
		// Root-relative link.
		$this->assertStringContainsString( '[contactez-nous](https://example.test/contact/)', self::$markdown );
	}

	public function test_lazy_image_uses_data_src_with_alt_text(): void {
		$this->assertStringContainsString(
			'![Buffet gastronomique](https://example.test/wp-content/uploads/2025/06/buffet.jpg)',
			self::$markdown
		);
		$this->assertStringNotContainsString( 'data:image/gif', self::$markdown );
	}

	public function test_lists_are_preserved(): void {
		$this->assertMatchesRegularExpression( '/^- Cocktail dînatoire/mu', self::$markdown );
		$this->assertMatchesRegularExpression( '/^- Menu gastronomique \*\*5 services\*\*/mu', self::$markdown );
	}

	public function test_tables_are_preserved_as_gfm(): void {
		$this->assertMatchesRegularExpression( '/\|\s*Formule\s*\|\s*Prix\s*\|/u', self::$markdown );
		$this->assertMatchesRegularExpression( '/\|\s*-+\s*\|\s*-+\s*\|/u', self::$markdown );
		$this->assertMatchesRegularExpression( '/\|\s*Cocktail\s*\|\s*45.\$\s*\|/u', self::$markdown );
	}

	public function test_blockquote_is_preserved(): void {
		$this->assertMatchesRegularExpression( '/^> « Une expérience culinaire inoubliable\./mu', self::$markdown );
	}

	public function test_json_ld_is_appended_in_fenced_block(): void {
		$this->assertMatchesRegularExpression( '/```json\n\{.+"@type": "LocalBusiness".+\n```/su', self::$markdown );
		$this->assertStringContainsString( '"Montréal"', self::$markdown );
	}

	public function test_invalid_json_ld_is_skipped(): void {
		$this->assertStringNotContainsString( 'not { valid json', self::$markdown );
	}

	// ------------------------------------------------------------------
	// Stripping.
	// ------------------------------------------------------------------

	public function test_chrome_is_stripped(): void {
		$md = self::$markdown;
		// nav / header / footer.
		$this->assertStringNotContainsString( 'Accueil', $md );
		$this->assertStringNotContainsString( 'Tous droits réservés', $md );
		// Cookie banner.
		$this->assertStringNotContainsString( 'Nous utilisons des cookies', $md );
		// Scripts, styles, noscript.
		$this->assertStringNotContainsString( 'elementorFrontendConfig', $md );
		$this->assertStringNotContainsString( 'color:#4a3b2a', $md );
		$this->assertStringNotContainsString( 'pixel.gif', $md );
		// Form controls.
		$this->assertStringNotContainsString( 'Envoyer', $md );
		// HTML comments.
		$this->assertStringNotContainsString( 'Advanced Cache', $md );
		// No leftover raw HTML tags.
		$this->assertStringNotContainsString( '<div', $md );
		$this->assertStringNotContainsString( '<section', $md );
	}

	public function test_empty_elementor_wrappers_leave_no_blank_line_runs(): void {
		$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', self::$markdown );
	}

	// ------------------------------------------------------------------
	// Robustness: malformed HTML, entities, nesting.
	// ------------------------------------------------------------------

	public function test_malformed_html_still_converts(): void {
		$html = '<html><body><main><h1>Titre<p>Un <strong>texte <em>imbriqué</strong></em> cassé<div><ul><li>item un<li>item deux</main>';
		$md   = ( new Converter( self::PAGE_URL ) )->convert( $html );

		$this->assertStringContainsString( '# Titre', $md );
		$this->assertStringContainsString( 'item un', $md );
		$this->assertStringContainsString( 'item deux', $md );
	}

	public function test_entities_are_decoded(): void {
		$html = '<html><body><main><p>Caf&eacute; &amp; th&#233; &mdash; d&egrave;s 5&nbsp;$</p></main></body></html>';
		$md   = ( new Converter( self::PAGE_URL ) )->convert( $html );

		$this->assertStringContainsString( 'Café', $md );
		$this->assertStringContainsString( '&', $md );
		$this->assertStringContainsString( 'thé', $md );
		$this->assertStringNotContainsString( '&eacute;', $md );
		$this->assertStringNotContainsString( '&amp;', $md );
	}

	public function test_nested_inline_elements(): void {
		$html = '<html><body><main><p><a href="/x/"><strong>Gras <em>et italique</em></strong></a></p></main></body></html>';
		$md   = ( new Converter( self::PAGE_URL ) )->convert( $html );

		$this->assertStringContainsString( '[**Gras *et italique***](https://example.test/x/)', $md );
	}

	public function test_code_blocks_are_preserved(): void {
		$html = '<html><body><main><pre><code class="language-php">echo "bonjour";</code></pre></main></body></html>';
		$md   = ( new Converter( self::PAGE_URL ) )->convert( $html );

		$this->assertStringContainsString( 'echo "bonjour";', $md );
		$this->assertStringContainsString( '```', $md );
	}

	public function test_empty_input_returns_empty_string(): void {
		$converter = new Converter( self::PAGE_URL );
		$this->assertSame( '', $converter->convert( '' ) );
		$this->assertSame( '', $converter->convert( '   ' ) );
	}

	// ------------------------------------------------------------------
	// URL resolution.
	// ------------------------------------------------------------------

	public function test_absolutize_handles_url_forms(): void {
		$c = new Converter( 'https://example.test/fr/menu/' );

		$this->assertSame( 'https://example.test/contact/', $c->absolutize( '/contact/' ) );
		$this->assertSame( 'https://example.test/fr/menu/prix/', $c->absolutize( 'prix/' ) );
		$this->assertSame( 'https://example.test/fr/autre/', $c->absolutize( '../autre/' ) );
		$this->assertSame( 'https://cdn.example.net/a.jpg', $c->absolutize( '//cdn.example.net/a.jpg' ) );
		$this->assertSame( 'https://autre.site/x', $c->absolutize( 'https://autre.site/x' ) );
		$this->assertSame( 'mailto:info@example.test', $c->absolutize( 'mailto:info@example.test' ) );
		$this->assertSame( 'tel:+15145551234', $c->absolutize( 'tel:+15145551234' ) );
		$this->assertSame( 'https://example.test/fr/menu/#tarifs', $c->absolutize( '#tarifs' ) );
	}

	// ------------------------------------------------------------------
	// Token estimation (documented heuristic: ceil(bytes / 4)).
	// ------------------------------------------------------------------

	public function test_token_estimate_heuristic(): void {
		$this->assertSame( 0, TokenEstimator::estimate( '' ) );
		$this->assertSame( 1, TokenEstimator::estimate( 'ab' ) );
		$this->assertSame( 1, TokenEstimator::estimate( 'abcd' ) );
		$this->assertSame( 2, TokenEstimator::estimate( 'abcde' ) );
		// Multi-byte: "é" is 2 bytes in UTF-8.
		$this->assertSame( 1, TokenEstimator::estimate( 'éé' ) );
		$this->assertSame( 25, TokenEstimator::estimate( str_repeat( 'x', 100 ) ) );
	}

	public function test_markdown_is_dramatically_smaller_than_source_html(): void {
		$html = (string) file_get_contents( __DIR__ . '/fixtures/elementor-page.html' );

		$this->assertLessThan(
			TokenEstimator::estimate( $html ),
			TokenEstimator::estimate( self::$markdown )
		);
	}
}
