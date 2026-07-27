<?php
/**
 * Rendered-HTML → Markdown conversion.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts a fully rendered page (as captured by output buffering — NOT the
 * raw post_content, which for Elementor pages is just layout meta) into
 * Markdown:
 *
 *  1. Parse the document with DOMDocument (libxml recovers from malformed HTML).
 *  2. Extract head metadata for YAML frontmatter and collect JSON-LD blocks.
 *  3. Locate the main content region (main / [role=main] / Elementor wrapper /
 *     article / #content / body).
 *  4. Strip chrome: script, style, noscript, svg, nav, header, footer,
 *     comments, form controls, cookie/consent banners, hidden elements.
 *  5. Make link and image URLs absolute.
 *  6. Serialize to Markdown via league/html-to-markdown (+ GFM tables).
 *  7. Assemble frontmatter + body + JSON-LD fenced blocks.
 */
final class Converter {

	/**
	 * Canonical URL of the page being converted; used to absolutize URLs.
	 *
	 * @var string
	 */
	private string $page_url;

	/**
	 * @param string $page_url Absolute URL of the page being converted.
	 */
	public function __construct( string $page_url = '' ) {
		$this->page_url = $page_url;
	}

	/**
	 * Convert rendered HTML to Markdown.
	 *
	 * @param string $html Full rendered HTML document.
	 * @return string Markdown text (UTF-8, trailing newline).
	 */
	public function convert( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$dom = $this->load_dom( $html );
		if ( null === $dom ) {
			return '';
		}

		$xpath = new DOMXPath( $dom );

		$meta    = $this->extract_metadata( $xpath );
		$json_ld = $this->extract_json_ld( $xpath );

		$region = $this->select_main_region( $xpath );
		if ( null === $region ) {
			return '';
		}

		$this->strip_chrome( $xpath, $region );
		$this->absolutize_urls( $xpath, $region );

		$body_html = $dom->saveHTML( $region );
		$markdown  = $this->serialize_markdown( (string) $body_html );

		return $this->assemble( $meta, $markdown, $json_ld );
	}

	/**
	 * Parse HTML into a DOM, tolerating malformed markup and forcing UTF-8.
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|null
	 */
	private function load_dom( string $html ): ?DOMDocument {
		$dom = new DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );
		// The XML declaration prefix is the reliable way to make libxml treat
		// the input as UTF-8 regardless of meta tags.
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Extract title / description / image / lang for the frontmatter.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @return array<string, string> Only keys that were actually present.
	 */
	private function extract_metadata( DOMXPath $xpath ): array {
		$meta = array();

		$title = $this->meta_content( $xpath, array( 'title' ), array( 'og:title' ) );
		if ( '' === $title ) {
			$node  = $xpath->query( '//head/title' )?->item( 0 );
			$title = null !== $node ? trim( $node->textContent ) : '';
		}
		if ( '' !== $title ) {
			$meta['title'] = $title;
		}

		$description = $this->meta_content( $xpath, array( 'description' ), array( 'og:description' ) );
		if ( '' !== $description ) {
			$meta['description'] = $description;
		}

		$image = $this->meta_content( $xpath, array(), array( 'og:image' ) );
		if ( '' !== $image ) {
			$meta['image'] = $this->absolutize( $image );
		}

		$html_el = $xpath->query( '//html' )?->item( 0 );
		if ( $html_el instanceof DOMElement && '' !== $html_el->getAttribute( 'lang' ) ) {
			$meta['lang'] = $html_el->getAttribute( 'lang' );
		}

		return $meta;
	}

	/**
	 * First non-empty content attribute among meta[name=...] / meta[property=...].
	 * Open Graph tags occur with either attribute in the wild, so both are checked.
	 *
	 * @param DOMXPath $xpath      Document xpath.
	 * @param string[] $names      Values for the name attribute.
	 * @param string[] $properties Values for the property attribute (also tried as name).
	 * @return string
	 */
	private function meta_content( DOMXPath $xpath, array $names, array $properties ): string {
		$queries = array();
		foreach ( $names as $name ) {
			$queries[] = sprintf( '//meta[@name="%s"]/@content', $name );
		}
		foreach ( $properties as $property ) {
			$queries[] = sprintf( '//meta[@property="%s"]/@content', $property );
			$queries[] = sprintf( '//meta[@name="%s"]/@content', $property );
		}

		foreach ( $queries as $query ) {
			$node = $xpath->query( $query )?->item( 0 );
			if ( null !== $node && '' !== trim( $node->nodeValue ?? '' ) ) {
				return trim( $node->nodeValue );
			}
		}

		return '';
	}

	/**
	 * Collect JSON-LD payloads (whole document, before scripts are stripped).
	 * Invalid JSON is skipped rather than emitted.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @return string[] Pretty-printed JSON strings.
	 */
	private function extract_json_ld( DOMXPath $xpath ): array {
		$blocks = array();

		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) ?: array() as $script ) {
			$raw = trim( $script->textContent );
			if ( '' === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw );
			if ( null === $decoded && 'null' !== $raw ) {
				continue;
			}
			$encoded = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false !== $encoded ) {
				$blocks[] = $encoded;
			}
		}

		return $blocks;
	}

	/**
	 * Pick the most specific main-content region available.
	 *
	 * @param DOMXPath $xpath Document xpath.
	 * @return DOMElement|null
	 */
	private function select_main_region( DOMXPath $xpath ): ?DOMElement {
		$candidates = array(
			'//main',
			'//*[@role="main"]',
			// Elementor page/post wrappers; header/footer templates use other
			// data-elementor-type values and must not be selected.
			'//div[@data-elementor-type="wp-page"]',
			'//div[@data-elementor-type="wp-post"]',
			'//div[@data-elementor-type="single-page"]',
			'//div[@data-elementor-type="single-post"]',
			'//article',
			'//*[@id="content"]',
			'//body',
		);

		foreach ( $candidates as $query ) {
			$node = $xpath->query( $query )?->item( 0 );
			if ( $node instanceof DOMElement ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Remove non-content chrome from the selected region.
	 *
	 * @param DOMXPath   $xpath  Document xpath.
	 * @param DOMElement $region Region to clean, in place.
	 */
	private function strip_chrome( DOMXPath $xpath, DOMElement $region ): void {
		$queries = array(
			'.//script',
			'.//style',
			'.//noscript',
			'.//svg',
			'.//nav',
			'.//header',
			'.//footer',
			'.//iframe',
			'.//form',
			'.//button',
			'.//input',
			'.//select',
			'.//textarea',
			'.//canvas',
			'.//comment()',
			'.//*[@hidden]',
			'.//*[@aria-hidden="true"]',
			// Common cookie/consent banners (Complianz, CookieYes, OneTrust,
			// Cookie Notice, Moove GDPR, generic).
			'.//*[contains(concat(" ", normalize-space(@class), " "), " cookie")'
				. ' or contains(@id, "cookie")'
				. ' or contains(@class, "cookie-")'
				. ' or contains(@class, "-cookie")'
				. ' or contains(@id, "cmplz")'
				. ' or contains(@class, "cmplz")'
				. ' or contains(@id, "onetrust")'
				. ' or contains(@class, "cky-")'
				. ' or contains(@id, "moove_gdpr")'
				. ' or contains(@class, "gdpr-banner")'
				. ' or contains(@class, "consent-banner")]',
			// Screen-reader-only helpers.
			'.//*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]',
			'.//*[contains(concat(" ", normalize-space(@class), " "), " elementor-screen-only ")]',
		);

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query, $region );
			if ( ! $nodes ) {
				continue;
			}
			// Iterate a static copy: removing while iterating a live list skips nodes.
			foreach ( iterator_to_array( $nodes ) as $node ) {
				$node->parentNode?->removeChild( $node );
			}
		}
	}

	/**
	 * Rewrite link/image URLs to absolute form, and promote lazy-load
	 * attributes (data-src) over placeholder src values.
	 *
	 * @param DOMXPath   $xpath  Document xpath.
	 * @param DOMElement $region Region to rewrite, in place.
	 */
	private function absolutize_urls( DOMXPath $xpath, DOMElement $region ): void {
		foreach ( $xpath->query( './/a[@href]', $region ) ?: array() as $a ) {
			if ( $a instanceof DOMElement ) {
				$a->setAttribute( 'href', $this->absolutize( $a->getAttribute( 'href' ) ) );
			}
		}

		foreach ( $xpath->query( './/img', $region ) ?: array() as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}

			$src = $img->getAttribute( 'src' );
			foreach ( array( 'data-src', 'data-lazy-src' ) as $lazy_attr ) {
				$lazy = $img->getAttribute( $lazy_attr );
				if ( '' !== $lazy && ( '' === $src || str_starts_with( $src, 'data:' ) ) ) {
					$src = $lazy;
					break;
				}
			}

			if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
				// Placeholder-only image: drop it rather than emit a data: URI.
				$img->parentNode?->removeChild( $img );
				continue;
			}

			$img->setAttribute( 'src', $this->absolutize( $src ) );
		}
	}

	/**
	 * Resolve a URL reference against the page URL.
	 *
	 * @param string $url URL reference (possibly relative).
	 * @return string
	 */
	public function absolutize( string $url ): string {
		$url = trim( $url );

		if ( '' === $url || '' === $this->page_url ) {
			return $url;
		}
		if ( preg_match( '#\A[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			return $url; // Already has a scheme (http:, https:, mailto:, tel:, data:, …).
		}

		$parts  = parse_url( $this->page_url );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		if ( '' === $host ) {
			return $url;
		}
		$origin = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( str_starts_with( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		if ( str_starts_with( $url, '#' ) ) {
			return $this->page_url . $url;
		}
		if ( str_starts_with( $url, '?' ) ) {
			$path = $parts['path'] ?? '/';
			return $origin . $path . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . self::normalize_path( $url );
		}

		// Relative path: resolve against the page's directory.
		$base_path = $parts['path'] ?? '/';
		if ( ! str_ends_with( $base_path, '/' ) ) {
			$slash     = strrpos( $base_path, '/' );
			$base_path = false === $slash ? '/' : substr( $base_path, 0, $slash + 1 );
		}

		return $origin . self::normalize_path( $base_path . $url );
	}

	/**
	 * Collapse `.` and `..` segments in a URL path.
	 *
	 * @param string $path Absolute URL path.
	 * @return string
	 */
	private static function normalize_path( string $path ): string {
		$query    = '';
		$hash_pos = strcspn( $path, '?#' );
		if ( $hash_pos < strlen( $path ) ) {
			$query = substr( $path, $hash_pos );
			$path  = substr( $path, 0, $hash_pos );
		}

		$output = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $output );
				continue;
			}
			$output[] = $segment;
		}

		$normalized = implode( '/', $output );
		if ( '' === $normalized || '/' !== $normalized[0] ) {
			$normalized = '/' . ltrim( $normalized, '/' );
		}

		return $normalized . $query;
	}

	/**
	 * HTML → Markdown via league/html-to-markdown with GFM tables.
	 *
	 * @param string $html Cleaned region HTML.
	 * @return string
	 */
	private function serialize_markdown( string $html ): string {
		$converter = new HtmlConverter(
			array(
				'header_style'            => 'atx',
				'strip_tags'              => true,
				'strip_placeholder_links' => true,
				'hard_break'              => true,
				'use_autolinks'           => false,
				'preserve_comments'       => false,
				'remove_nodes'            => 'script style noscript',
				'italic_style'            => '*',
				'bold_style'              => '**',
			)
		);
		$converter->getEnvironment()->addConverter( new TableConverter() );

		$markdown = $converter->convert( $html );

		// League escapes text with htmlspecialchars and DOM serialization
		// re-escapes it, so its single final decode still leaves entities like
		// `&amp;` behind. Our Markdown is consumed as plain text by agents,
		// never re-rendered as HTML, so decode once more to get literal chars.
		$markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse runs of blank lines left behind by empty layout wrappers.
		$markdown = (string) preg_replace( "/\n{3,}/", "\n\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * Assemble frontmatter, body and JSON-LD appendix.
	 *
	 * @param array<string, string> $meta     Frontmatter keys.
	 * @param string                $markdown Converted body.
	 * @param string[]              $json_ld  Pretty-printed JSON-LD payloads.
	 * @return string
	 */
	private function assemble( array $meta, string $markdown, array $json_ld ): string {
		$out = '';

		if ( array() !== $meta ) {
			$out .= "---\n";
			foreach ( array( 'title', 'description', 'image', 'lang' ) as $key ) {
				if ( isset( $meta[ $key ] ) ) {
					$out .= $key . ': ' . self::yaml_quote( $meta[ $key ] ) . "\n";
				}
			}
			$out .= "---\n\n";
		}

		$out .= $markdown;

		foreach ( $json_ld as $block ) {
			$out .= "\n\n```json\n" . $block . "\n```";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Double-quote a scalar for YAML, escaping backslashes, quotes and newlines.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function yaml_quote( string $value ): string {
		$value = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
		$value = (string) preg_replace( '/\s+/u', ' ', $value );

		return '"' . trim( $value ) . '"';
	}
}
