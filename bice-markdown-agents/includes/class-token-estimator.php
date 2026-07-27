<?php
/**
 * Token count estimation.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * ESTIMATE ONLY — there is no real tokenizer in PHP.
 *
 * Heuristic: ceil(byte length / 4). Modern BPE tokenizers (GPT/Claude
 * families) average roughly 4 bytes per token on English prose; French text
 * with multi-byte accented characters skews slightly high, which is the safe
 * direction for an agent budgeting context. The same heuristic is applied to
 * both the Markdown and the source HTML so the two headers stay comparable.
 */
final class TokenEstimator {

	/**
	 * Bytes-per-token divisor for the heuristic.
	 */
	private const BYTES_PER_TOKEN = 4;

	/**
	 * Estimate the token count of a string.
	 *
	 * @param string $text Any UTF-8 text (Markdown or HTML).
	 * @return int Estimated token count (>= 0).
	 */
	public static function estimate( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}

		return (int) ceil( strlen( $text ) / self::BYTES_PER_TOKEN );
	}
}
