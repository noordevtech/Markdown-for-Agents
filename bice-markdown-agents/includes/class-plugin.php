<?php
/**
 * Request orchestration: negotiation, output capture, response headers.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Wires the pieces together:
 *
 *  - `.md` suffix rewriting before WordPress parses the request.
 *  - Accept negotiation + scope guards at template_redirect.
 *  - Output buffering of the fully rendered page (required for Elementor,
 *    whose post_content is layout meta, not content).
 *  - Response headers, including the cache-safety set.
 *  - `Link: <url>; rel="alternate"; type="text/markdown"` on HTML responses.
 */
final class Plugin {

	/**
	 * Whether the `.md` suffix path requested Markdown for this request.
	 *
	 * @var bool
	 */
	private bool $suffix_requested = false;

	/**
	 * Whether Accept-header negotiation selected Markdown for this request.
	 *
	 * @var bool
	 */
	private bool $negotiated = false;

	/**
	 * Singleton handle, so both plugin and mu-plugin loaders boot exactly once.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boot the plugin. Idempotent: a second call (e.g. plugin active while an
	 * mu-plugins loader also requires the file) is a no-op.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self();
		self::$instance->register();
	}

	/**
	 * Register hooks. Runs at file-load time, which is early enough in both
	 * plugin and mu-plugin contexts.
	 */
	private function register(): void {
		// Settings/options need core fully loaded; plugins_loaded fires in
		// both plugin and mu-plugin contexts, well before the request is parsed.
		add_action( 'plugins_loaded', array( $this, 'setup' ), 1 );

		if ( is_admin() ) {
			Settings::init();
		}
	}

	/**
	 * Per-request setup once settings are readable.
	 */
	public function setup(): void {
		$settings = Settings::get();

		if ( ! $settings['enabled'] ) {
			return;
		}

		if ( $settings['md_suffix'] ) {
			$this->maybe_rewrite_md_suffix();
		}

		// Very late priority: canonical redirects, old-slug redirects and
		// other template_redirect handlers must win before we buffer output.
		add_action( 'template_redirect', array( $this, 'handle_front_end_request' ), PHP_INT_MAX );
	}

	/**
	 * Map `/path/index.md` onto `/path/` before WordPress parses the request,
	 * remembering that Markdown was asked for via the URL.
	 */
	private function maybe_rewrite_md_suffix(): void {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! str_ends_with( $path, '/index.md' ) ) {
			return;
		}

		$new_path = substr( $path, 0, -strlen( 'index.md' ) );
		$query    = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI']  = $new_path . ( '' !== $query ? '?' . $query : '' );
		$this->suffix_requested = true;
	}

	/**
	 * Decide, at template_redirect, what this request gets.
	 */
	public function handle_front_end_request(): void {
		$settings = Settings::get();
		$context  = ScopeGuard::current_context( $settings );

		if ( ! ScopeGuard::allows( $context ) ) {
			return; // Out of scope: untouched HTML, no alternate advertised.
		}

		$this->negotiated = AcceptParser::prefers_markdown( $_SERVER['HTTP_ACCEPT'] ?? null );

		if ( ! $this->negotiated && ! $this->suffix_requested ) {
			// Normal HTML response — just advertise the alternate representation.
			if ( ! headers_sent() ) {
				header(
					sprintf( 'Link: <%s>; rel="alternate"; type="text/markdown"', esc_url_raw( $this->current_url() ) ),
					false
				);
			}
			return;
		}

		// Markdown was selected. Stop every reachable page cache from storing
		// this variant under the shared URL key (see CacheCompat for what a
		// drop-in on a cache HIT means — that layer needs nginx/Cloudflare help).
		if ( $this->negotiated ) {
			CacheCompat::mark_uncacheable();
		}

		ob_start( array( $this, 'convert_buffered_output' ) );
	}

	/**
	 * Output-buffer callback: convert the rendered HTML, or bail out to the
	 * original bytes whenever anything is off. Returning the input unchanged
	 * is the primary safety property.
	 *
	 * @param string $html Fully rendered page HTML.
	 * @return string Markdown, or the untouched HTML on any doubt.
	 */
	public function convert_buffered_output( string $html ): string {
		// The template may have changed the status after template_redirect.
		$status = http_response_code();
		if ( 200 !== $status ) {
			return $html;
		}

		// If headers are already on the wire we cannot relabel the response;
		// serving Markdown bytes under a text/html header would be worse.
		if ( headers_sent() ) {
			return $html;
		}

		if ( '' === trim( $html ) ) {
			return $html;
		}

		try {
			$markdown = ( new Converter( $this->current_url() ) )->convert( $html );
		} catch ( \Throwable $e ) {
			return $html; // Conversion failure must never break the page.
		}

		if ( '' === trim( $markdown ) ) {
			return $html;
		}

		$this->send_markdown_headers( $markdown, $html );

		return $markdown;
	}

	/**
	 * Response headers for a Markdown representation.
	 *
	 * @param string $markdown Converted Markdown body.
	 * @param string $html     Source HTML (for the X-Original-Tokens estimate).
	 */
	private function send_markdown_headers( string $markdown, string $html ): void {
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Markdown-Tokens: ' . TokenEstimator::estimate( $markdown ) );
		header( 'X-Original-Tokens: ' . TokenEstimator::estimate( $html ) );
		header( 'X-Robots-Tag: noindex' );

		if ( $this->negotiated ) {
			// Negotiated variant shares the URL with HTML. Cloudflare does not
			// reliably honour Vary on HTML, so this variant must never be
			// edge-cached: correctness beats performance at agent volumes.
			header( 'Cache-Control: private, no-store, max-age=0' );
			$this->send_merged_vary_header( 'Accept' );
		}
		// Suffix-only requests (/page/index.md) live at their own URL, so they
		// need neither Vary nor the no-store hammer.
	}

	/**
	 * Emit a Vary header merged with any values already queued for sending.
	 *
	 * @param string $value Vary member to add.
	 */
	private function send_merged_vary_header( string $value ): void {
		$members = array();

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Vary:' ) ) {
				foreach ( explode( ',', substr( $header, 5 ) ) as $member ) {
					$member = trim( $member );
					if ( '' !== $member ) {
						$members[ strtolower( $member ) ] = $member;
					}
				}
			}
		}

		$members[ strtolower( $value ) ] = $value;

		header_remove( 'Vary' );
		header( 'Vary: ' . implode( ', ', $members ) );
	}

	/**
	 * Absolute URL of the current request (path + query, no fragment).
	 *
	 * @return string
	 */
	private function current_url(): string {
		$host = (string) ( $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) );
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );

		// Strip the nginx cache-segmentation marker so advertised URLs stay
		// canonical (see nginx/markdown-agents.conf).
		$uri = remove_query_arg( 'bma_md', $uri );

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}
}
