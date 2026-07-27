<?php
/**
 * RFC 9110 Accept header parser.
 *
 * Pure PHP, no WordPress dependencies, so it can be unit-tested in isolation.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Parses `Accept` headers and decides whether a request genuinely prefers
 * `text/markdown` over `text/html`.
 *
 * Decision rule (deliberately strict — a false positive breaks the site for
 * human visitors):
 *
 *  1. `text/markdown` must be listed EXPLICITLY. A wildcard (`* / *` or
 *     `text/ *`) never counts as asking for Markdown.
 *  2. Its q-value must be greater than zero.
 *  3. Its q-value must be greater than or equal to the effective q-value of
 *     `text/html` (which, per RFC 9110, is taken from the most specific
 *     matching range: exact > `text/ *` > `* / *`; absent → 0).
 *
 * A typical browser header —
 * `text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,* / *;q=0.8`
 * — contains no explicit `text/markdown`, so rule 1 fails and HTML is served.
 */
final class AcceptParser {

	/**
	 * Media-range token per RFC 9110: type "/" subtype, either may be "*".
	 */
	private const MEDIA_RANGE_PATTERN = '#\A(\*|[a-z0-9!\#$%&\'^_.+\-]+)/(\*|[a-z0-9!\#$%&\'^_.+\-]+)\z#i';

	/**
	 * Decide whether the given Accept header prefers text/markdown.
	 *
	 * @param string|null $header Raw Accept header value, or null when absent.
	 * @return bool True only when Markdown is explicitly and at-least-equally preferred.
	 */
	public static function prefers_markdown( ?string $header ): bool {
		if ( null === $header || '' === trim( $header ) ) {
			return false;
		}

		$entries = self::parse( $header );
		if ( array() === $entries ) {
			return false;
		}

		// Rule 1: exact listing only. Wildcards must never trigger Markdown.
		$markdown_q = self::exact_q( $entries, 'text', 'markdown' );
		if ( null === $markdown_q || $markdown_q <= 0.0 ) {
			return false;
		}

		// Effective q for text/html via most-specific match (exact > text/* > */*).
		$html_q = self::effective_q( $entries, 'text', 'html' );

		return $markdown_q >= $html_q;
	}

	/**
	 * Parse an Accept header into a list of media-range entries.
	 *
	 * Malformed entries are dropped rather than guessed at. A malformed
	 * q-value (e.g. `q=abc`, `q=2`, `q=1.2345`) makes that entry
	 * unacceptable (q = 0) — the conservative reading for our purpose,
	 * since it can only ever result in HTML being served.
	 *
	 * @param string $header Raw header value.
	 * @return array<int, array{type:string, subtype:string, q:float}>
	 */
	public static function parse( string $header ): array {
		$entries = array();

		foreach ( self::split_on_unquoted_commas( $header ) as $element ) {
			$element = trim( $element, " \t" );
			if ( '' === $element ) {
				continue;
			}

			$params = self::split_on_unquoted( $element, ';' );
			$range  = strtolower( trim( array_shift( $params ), " \t" ) );

			if ( ! preg_match( self::MEDIA_RANGE_PATTERN, $range, $m ) ) {
				continue; // Not a media range at all — drop it.
			}

			list( , $type, $subtype ) = $m;

			// "*/subtype" is not a valid media range per RFC 9110.
			if ( '*' === $type && '*' !== $subtype ) {
				continue;
			}

			$q = 1.0;
			foreach ( $params as $param ) {
				$param = trim( $param, " \t" );
				// Everything after the first q parameter is an accept-ext; stop there.
				if ( preg_match( '/\Aq\s*=\s*(.*)\z/is', $param, $qm ) ) {
					$q = self::parse_qvalue( trim( $qm[1], " \t" ) );
					break;
				}
			}

			$entries[] = array(
				'type'    => $type,
				'subtype' => $subtype,
				'q'       => $q,
			);
		}

		return $entries;
	}

	/**
	 * Parse a qvalue per RFC 9110 §12.4.2: 0(.000..) to 1(.000..), max 3 decimals.
	 * Anything malformed collapses to 0.0 (unacceptable) — never to 1.0.
	 *
	 * @param string $raw Raw qvalue text (quotes tolerated, though not RFC-valid).
	 * @return float
	 */
	private static function parse_qvalue( string $raw ): float {
		$raw = trim( $raw, '"' );

		if ( ! preg_match( '/\A(0(\.\d{0,3})?|1(\.0{0,3})?)\z/', $raw ) ) {
			return 0.0;
		}

		return (float) $raw;
	}

	/**
	 * Q-value of an EXACT type/subtype listing, or null when not explicitly listed.
	 * Duplicated exact listings resolve to the highest q.
	 *
	 * @param array<int, array{type:string, subtype:string, q:float}> $entries Parsed entries.
	 * @param string                                                  $type    Lowercase type.
	 * @param string                                                  $subtype Lowercase subtype.
	 * @return float|null
	 */
	public static function exact_q( array $entries, string $type, string $subtype ): ?float {
		$found = null;
		foreach ( $entries as $entry ) {
			if ( $entry['type'] === $type && $entry['subtype'] === $subtype ) {
				$found = ( null === $found ) ? $entry['q'] : max( $found, $entry['q'] );
			}
		}
		return $found;
	}

	/**
	 * Effective q-value for a concrete media type, honouring wildcard precedence:
	 * exact match > type wildcard (`text/ *`) > full wildcard (`* / *`) > absent (0).
	 *
	 * @param array<int, array{type:string, subtype:string, q:float}> $entries Parsed entries.
	 * @param string                                                  $type    Lowercase type.
	 * @param string                                                  $subtype Lowercase subtype.
	 * @return float
	 */
	public static function effective_q( array $entries, string $type, string $subtype ): float {
		$exact = self::exact_q( $entries, $type, $subtype );
		if ( null !== $exact ) {
			return $exact;
		}

		$type_wildcard = null;
		$full_wildcard = null;
		foreach ( $entries as $entry ) {
			if ( $entry['type'] === $type && '*' === $entry['subtype'] ) {
				$type_wildcard = ( null === $type_wildcard ) ? $entry['q'] : max( $type_wildcard, $entry['q'] );
			} elseif ( '*' === $entry['type'] && '*' === $entry['subtype'] ) {
				$full_wildcard = ( null === $full_wildcard ) ? $entry['q'] : max( $full_wildcard, $entry['q'] );
			}
		}

		if ( null !== $type_wildcard ) {
			return $type_wildcard;
		}
		if ( null !== $full_wildcard ) {
			return $full_wildcard;
		}

		return 0.0;
	}

	/**
	 * Split a header on commas that are not inside a quoted string.
	 *
	 * @param string $header Header value.
	 * @return string[]
	 */
	private static function split_on_unquoted_commas( string $header ): array {
		return self::split_on_unquoted( $header, ',' );
	}

	/**
	 * Split on a delimiter, ignoring delimiters inside double-quoted strings
	 * (with backslash escapes), per RFC 9110 quoted-string rules.
	 *
	 * @param string $value     Value to split.
	 * @param string $delimiter Single-character delimiter.
	 * @return string[]
	 */
	private static function split_on_unquoted( string $value, string $delimiter ): array {
		$parts     = array();
		$buffer    = '';
		$in_quotes = false;
		$escaped   = false;
		$length    = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];

			if ( $escaped ) {
				$buffer .= $char;
				$escaped = false;
				continue;
			}

			if ( $in_quotes && '\\' === $char ) {
				$buffer .= $char;
				$escaped = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_quotes = ! $in_quotes;
				$buffer   .= $char;
				continue;
			}

			if ( $char === $delimiter && ! $in_quotes ) {
				$parts[] = $buffer;
				$buffer  = '';
				continue;
			}

			$buffer .= $char;
		}

		$parts[] = $buffer;

		return $parts;
	}
}
