<?php
/**
 * Scope guards: decide whether a request is eligible for a Markdown
 * representation at all, independent of the Accept header.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * The decision logic is a pure function over a context array so it can be
 * unit-tested without WordPress; {@see ScopeGuard::current_context()} gathers
 * the real context from WordPress at template_redirect time.
 *
 * Markdown is only ever emitted for front-end, publicly visible, HTTP 200
 * GET/HEAD responses. Everything else — admin, login, REST, XML-RPC, AJAX,
 * cron, CLI, feeds, sitemaps, robots.txt, favicon, trackbacks, previews,
 * WooCommerce cart/checkout/account, logged-in users, password-protected
 * posts, non-200 statuses — gets untouched HTML.
 */
final class ScopeGuard {

	/**
	 * Default context: every flag in its "blocked" state must be explicitly
	 * cleared by the collector. Missing keys therefore fail safe.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'method'               => '',
			'status'               => 0,
			'is_admin'             => true,
			'is_login'             => true,
			'is_rest'              => true,
			'is_xmlrpc'            => true,
			'is_ajax'              => true,
			'is_cron'              => true,
			'is_cli'               => true,
			'is_feed'              => true,
			'is_robots'            => true,
			'is_favicon'           => true,
			'is_sitemap'           => true,
			'is_trackback'         => true,
			'is_preview'           => true,
			'is_customize_preview' => true,
			'is_404'               => true,
			'is_woo_cart'          => true,
			'is_woo_checkout'      => true,
			'is_woo_account'       => true,
			'is_user_logged_in'    => true,
			'is_password_protected' => true,
			'post_type'            => '',
			'excluded_post_types'  => array(),
			'path'                 => '/',
			'excluded_paths'       => array(),
		);
	}

	/**
	 * Pure eligibility decision.
	 *
	 * @param array<string, mixed> $context Request context; missing keys fail safe.
	 * @return bool True when a Markdown representation may be served.
	 */
	public static function allows( array $context ): bool {
		$ctx = array_merge( self::defaults(), $context );

		if ( ! in_array( strtoupper( (string) $ctx['method'] ), array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		if ( 200 !== (int) $ctx['status'] ) {
			return false;
		}

		$blocking_flags = array(
			'is_admin',
			'is_login',
			'is_rest',
			'is_xmlrpc',
			'is_ajax',
			'is_cron',
			'is_cli',
			'is_feed',
			'is_robots',
			'is_favicon',
			'is_sitemap',
			'is_trackback',
			'is_preview',
			'is_customize_preview',
			'is_404',
			'is_woo_cart',
			'is_woo_checkout',
			'is_woo_account',
			'is_user_logged_in',
			'is_password_protected',
		);

		foreach ( $blocking_flags as $flag ) {
			if ( false !== $ctx[ $flag ] ) {
				return false;
			}
		}

		if ( '' !== (string) $ctx['post_type']
			&& in_array( (string) $ctx['post_type'], (array) $ctx['excluded_post_types'], true ) ) {
			return false;
		}

		if ( self::path_is_excluded( (string) $ctx['path'], (array) $ctx['excluded_paths'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Match a request path against exclusion rules.
	 *
	 * A rule matches the exact path, or the path plus a trailing slash, or —
	 * when the rule ends in `*` — any path with the given prefix.
	 *
	 * @param string   $path  Request path (no query string).
	 * @param string[] $rules Exclusion rules.
	 * @return bool
	 */
	public static function path_is_excluded( string $path, array $rules ): bool {
		$path = '/' . ltrim( $path, '/' );

		foreach ( $rules as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}
			$rule = '/' . ltrim( $rule, '/' );

			if ( str_ends_with( $rule, '*' ) ) {
				if ( str_starts_with( $path, rtrim( $rule, '*' ) ) ) {
					return true;
				}
				continue;
			}

			if ( $path === $rule || $path === rtrim( $rule, '/' ) . '/' || rtrim( $path, '/' ) === rtrim( $rule, '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gather the live context from WordPress. Only meaningful at or after
	 * template_redirect, when conditional tags are resolved.
	 *
	 * @param array<string, mixed> $settings Plugin settings (excluded_post_types, excluded_paths).
	 * @return array<string, mixed>
	 */
	public static function current_context( array $settings = array() ): array {
		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

		$status = http_response_code();
		// http_response_code() returns 200 by default before any status is
		// sent; combine with is_404() below for the cases WP knows about.

		return array(
			'method'                => $_SERVER['REQUEST_METHOD'] ?? '',
			'status'                => false === $status ? 0 : (int) $status,
			'is_admin'              => is_admin(),
			'is_login'              => self::is_login_request(),
			'is_rest'               => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'is_xmlrpc'             => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			'is_ajax'               => wp_doing_ajax(),
			'is_cron'               => wp_doing_cron(),
			'is_cli'                => defined( 'WP_CLI' ) && WP_CLI,
			'is_feed'               => is_feed(),
			'is_robots'             => is_robots(),
			'is_favicon'            => function_exists( 'is_favicon' ) && is_favicon(),
			'is_sitemap'            => '' !== get_query_var( 'sitemap', '' ) || '' !== get_query_var( 'sitemap-stylesheet', '' ),
			'is_trackback'          => is_trackback(),
			'is_preview'            => is_preview(),
			'is_customize_preview'  => is_customize_preview(),
			'is_404'                => is_404(),
			'is_woo_cart'           => function_exists( 'is_cart' ) && is_cart(),
			'is_woo_checkout'       => function_exists( 'is_checkout' ) && is_checkout(),
			'is_woo_account'        => function_exists( 'is_account_page' ) && is_account_page(),
			'is_user_logged_in'     => is_user_logged_in(),
			'is_password_protected' => post_password_required(),
			'post_type'             => (string) ( get_post_type() ?: '' ),
			'excluded_post_types'   => (array) ( $settings['excluded_post_types'] ?? array() ),
			'path'                  => $path,
			'excluded_paths'        => (array) ( $settings['excluded_paths'] ?? array() ),
		);
	}

	/**
	 * Whether this request targets the login/registration screen.
	 *
	 * @return bool
	 */
	private static function is_login_request(): bool {
		if ( function_exists( 'is_login' ) && is_login() ) {
			return true;
		}

		$script = $_SERVER['SCRIPT_NAME'] ?? '';

		return str_ends_with( (string) $script, 'wp-login.php' ) || str_ends_with( (string) $script, 'wp-register.php' );
	}
}
