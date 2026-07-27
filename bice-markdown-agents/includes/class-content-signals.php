<?php
/**
 * Content Signals: declare AI content-usage preferences in robots.txt.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Emits Content-Signal directives (contentsignals.org,
 * draft-romm-aipref-contentsignals) into robots.txt.
 *
 * Surgical by design: every existing rule contributed by WordPress,
 * WooCommerce, Rank Math or anything else on the robots_txt filter is
 * preserved. The signal line is inserted inside the first `User-agent: *`
 * group when one exists; otherwise a new wildcard group is prepended,
 * leaving the existing output intact. Insertion is idempotent — if any
 * Content-Signal line is already present (ours or another plugin's),
 * nothing is added.
 *
 * The three signals:
 *   search    build a search index; return links and short excerpts.
 *   ai-input  supply content to a model at answer time (RAG, grounding).
 *   ai-train  use content to train or fine-tune a model.
 *
 * Each can be yes, no, or absent (absent expresses no preference).
 * The string-manipulation core is pure and unit-tested without WordPress.
 */
final class ContentSignals {

	/**
	 * Signal names in canonical emission order.
	 */
	public const SIGNALS = array( 'search', 'ai-input', 'ai-train' );

	/**
	 * Hook into robots.txt generation.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	public static function register( array $settings ): void {
		if ( empty( $settings['content_signals_enabled'] ) ) {
			return;
		}

		add_filter(
			'robots_txt',
			static function ( $output, $public ) use ( $settings ) {
				// Never advertise a policy on a discouraged / staging site.
				if ( ! $public ) {
					return (string) $output;
				}

				$line = self::signal_line( (array) ( $settings['content_signals'] ?? array() ) );

				return self::apply( (string) $output, $line );
			},
			99,
			2
		);
	}

	/**
	 * Build the Content-Signal directive from a policy array.
	 *
	 * @param array<string, string> $policy Map of signal name => 'yes'|'no'|'' (absent).
	 * @return string Directive line, or '' when no signal expresses a preference.
	 */
	public static function signal_line( array $policy ): string {
		$parts = array();

		foreach ( self::SIGNALS as $signal ) {
			$value = strtolower( trim( (string) ( $policy[ $signal ] ?? '' ) ) );
			if ( 'yes' === $value || 'no' === $value ) {
				$parts[] = $signal . '=' . $value;
			}
		}

		if ( array() === $parts ) {
			return '';
		}

		return 'Content-Signal: ' . implode( ', ', $parts );
	}

	/**
	 * Insert a Content-Signal directive into robots.txt output.
	 *
	 * Pure function: no WordPress, fully unit-testable.
	 *
	 * @param string $output Existing robots.txt body.
	 * @param string $line   Directive from {@see signal_line()}; '' is a no-op.
	 * @return string
	 */
	public static function apply( string $output, string $line ): string {
		if ( '' === $line ) {
			return $output;
		}

		// Idempotent: never double-insert, ours or anyone else's.
		if ( false !== stripos( $output, 'Content-Signal:' ) ) {
			return $output;
		}

		$preamble = <<<TXT
# Content Signals Policy — https://contentsignals.org/
# As a condition of accessing this site, you agree to the signals below.
#   yes = this use is permitted    no = this use is not permitted
#   (a signal that is absent expresses no preference either way)
# Definitions:
#   search    build a search index; return links and short excerpts.
#             Does not cover AI-generated summaries.
#   ai-input  supply this content to a model at answer time
#             (retrieval-augmented generation, grounding, live answers).
#   ai-train  use this content to train or fine-tune a model.

TXT;

		// Insert the signal immediately inside the first "User-agent: *" group.
		$inserted = preg_replace(
			'/^(User-agent:\s*\*[ \t]*)$/mi',
			'$1' . "\n" . $line,
			$output,
			1,
			$count
		);

		if ( null !== $inserted && $count > 0 ) {
			return $preamble . "\n" . ltrim( $inserted, "\n" );
		}

		// No wildcard group present — emit our own, leaving existing rules intact.
		return $preamble
			. "User-agent: *\n"
			. $line . "\n\n"
			. ltrim( $output, "\n" );
	}
}
