<?php
/**
 * WP-CLI: verify that every advertised discovery URL resolves.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * `wp bice-agents verify`
 *
 * Fetches every URL advertised in auth.md, SKILL.md, the Protected Resource
 * Metadata and the skills index, and fails when any returns non-200 or an
 * unexpected content type. This is the live half of the self-check (the
 * structural half runs in PHPUnit): the plugin must be unable to advertise
 * something that isn't there.
 *
 * Registration endpoints are never probed — per the auth.md spec, probing
 * them can create accounts or issue credentials. Discovery documents only
 * ({@see Discovery::advertised_urls()} already excludes them).
 */
final class Cli {

	/**
	 * Expected content-type substrings by path suffix / known path.
	 */
	private const CONTENT_TYPE_EXPECTATIONS = array(
		'/.well-known/oauth-protected-resource' => 'json',
		'/.well-known/agent-skills/index.json'  => 'json',
		'/.well-known/api-catalog'              => 'json', // application/linkset+json.
		'/robots.txt'                           => 'text/plain',
	);

	/**
	 * Verify every advertised discovery URL against the live site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bice-agents verify
	 *
	 * @when after_wp_load
	 */
	public function verify(): void {
		$settings = Settings::get();
		$config   = Discovery::config_from_wp( $settings );
		$urls     = Discovery::advertised_urls( $config );

		// The documents themselves are advertised (Link headers, the skills
		// index) — probe the routes this plugin serves too.
		$site = rtrim( (string) $config['site_url'], '/' );
		foreach ( array( '/auth.md', '/.well-known/oauth-protected-resource', '/.well-known/agent-skills/index.json', '/.well-known/agent-skills/markdown-for-agents/SKILL.md' ) as $route ) {
			$urls[] = $site . $route;
		}
		$urls = array_values( array_unique( $urls ) );

		$failures = 0;

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'user-agent'  => 'bice-markdown-agents self-check/' . ( defined( 'BICE_MDA_VERSION' ) ? BICE_MDA_VERSION : '0' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				\WP_CLI::warning( sprintf( 'FAIL %s — %s', $url, $response->get_error_message() ) );
				$failures++;
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$type   = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

			if ( 200 !== $status ) {
				\WP_CLI::warning( sprintf( 'FAIL %s — HTTP %d', $url, $status ) );
				$failures++;
				continue;
			}

			$expected = $this->expected_type( $url );
			if ( '' !== $expected && ! str_contains( $type, $expected ) ) {
				\WP_CLI::warning( sprintf( 'FAIL %s — content-type "%s", expected to contain "%s"', $url, $type, $expected ) );
				$failures++;
				continue;
			}

			\WP_CLI::log( sprintf( 'OK   %s (%d, %s)', $url, $status, '' !== $type ? $type : 'no content-type' ) );
		}

		if ( $failures > 0 ) {
			\WP_CLI::error( sprintf( '%d advertised URL(s) failed verification. The discovery documents are lying — fix or remove the claims.', $failures ) );
		}

		\WP_CLI::success( sprintf( 'All %d advertised URLs resolve with expected content types.', count( $urls ) ) );
	}

	/**
	 * Expected content-type substring for a URL, '' when anything goes.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private function expected_type( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH );

		foreach ( self::CONTENT_TYPE_EXPECTATIONS as $suffix => $type ) {
			if ( str_ends_with( $path, $suffix ) ) {
				return $type;
			}
		}

		if ( str_ends_with( $path, '.md' ) || str_ends_with( $path, 'SKILL.md' ) ) {
			return 'markdown';
		}

		return '';
	}
}
