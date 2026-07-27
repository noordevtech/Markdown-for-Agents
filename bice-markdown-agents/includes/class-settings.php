<?php
/**
 * Settings page: Settings → Markdown for Agents.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Stores everything in a single option array. Defaults are conservative:
 * negotiation on, `.md` suffix off, nothing excluded.
 */
final class Settings {

	public const OPTION = 'bice_mda_settings';

	private const PAGE = 'bice-markdown-agents';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                 => true,
			'md_suffix'               => false,
			'excluded_post_types'     => array(),
			'excluded_paths'          => array(),
			'content_signals_enabled' => true,
			// Default policy: discoverability and answer-time use are wanted
			// (they return links, visits, bookings); training returns nothing.
			'content_signals'         => array(
				'search'   => 'yes',
				'ai-input' => 'yes',
				'ai-train' => 'no',
			),
			'discovery_enabled'           => true,
			'webmcp_enabled'              => true,
			'oauth_authorization_servers' => array(),
			'oauth_scopes'                => array(),
			'agent_register_uri'          => '',
			'contact_email'               => '',
			'resource_policy_uri'         => '',
			'resource_tos_uri'            => '',
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Register admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Add the options page under Settings.
	 */
	public static function register_menu(): void {
		add_options_page(
			__( 'Markdown for Agents', 'bice-markdown-agents' ),
			__( 'Markdown for Agents', 'bice-markdown-agents' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'bice_mda_main',
			__( 'Content negotiation', 'bice-markdown-agents' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'enabled',
			__( 'Serve Markdown to agents', 'bice-markdown-agents' ),
			array( self::class, 'render_enabled_field' ),
			self::PAGE,
			'bice_mda_main'
		);

		add_settings_field(
			'md_suffix',
			__( '.md URL suffix', 'bice-markdown-agents' ),
			array( self::class, 'render_suffix_field' ),
			self::PAGE,
			'bice_mda_main'
		);

		add_settings_field(
			'excluded_post_types',
			__( 'Excluded post types', 'bice-markdown-agents' ),
			array( self::class, 'render_post_types_field' ),
			self::PAGE,
			'bice_mda_main'
		);

		add_settings_field(
			'excluded_paths',
			__( 'Excluded paths', 'bice-markdown-agents' ),
			array( self::class, 'render_paths_field' ),
			self::PAGE,
			'bice_mda_main'
		);

		add_settings_section(
			'bice_mda_signals',
			__( 'Content Signals (robots.txt)', 'bice-markdown-agents' ),
			array( self::class, 'render_signals_intro' ),
			self::PAGE
		);

		add_settings_field(
			'content_signals_enabled',
			__( 'Declare Content Signals', 'bice-markdown-agents' ),
			array( self::class, 'render_signals_enabled_field' ),
			self::PAGE,
			'bice_mda_signals'
		);

		add_settings_field(
			'content_signals',
			__( 'Policy', 'bice-markdown-agents' ),
			array( self::class, 'render_signals_policy_field' ),
			self::PAGE,
			'bice_mda_signals'
		);

		add_settings_section(
			'bice_mda_discovery',
			__( 'Agent discovery', 'bice-markdown-agents' ),
			array( self::class, 'render_discovery_intro' ),
			self::PAGE
		);

		add_settings_field(
			'discovery_enabled',
			__( 'Discovery endpoints', 'bice-markdown-agents' ),
			array( self::class, 'render_discovery_enabled_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'webmcp_enabled',
			__( 'WebMCP browser tools', 'bice-markdown-agents' ),
			array( self::class, 'render_webmcp_enabled_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'oauth_authorization_servers',
			__( 'OAuth authorization servers', 'bice-markdown-agents' ),
			array( self::class, 'render_oauth_servers_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'oauth_scopes',
			__( 'OAuth scopes supported', 'bice-markdown-agents' ),
			array( self::class, 'render_oauth_scopes_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'agent_register_uri',
			__( 'Agent registration URL', 'bice-markdown-agents' ),
			array( self::class, 'render_register_uri_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'contact_email',
			__( 'Agent contact email', 'bice-markdown-agents' ),
			array( self::class, 'render_contact_email_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'resource_policy_uri',
			__( 'Resource policy URL', 'bice-markdown-agents' ),
			array( self::class, 'render_policy_uri_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);

		add_settings_field(
			'resource_tos_uri',
			__( 'Terms of service URL', 'bice-markdown-agents' ),
			array( self::class, 'render_tos_uri_field' ),
			self::PAGE,
			'bice_mda_discovery'
		);
	}

	/**
	 * Sanitize the submitted option array.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		$clean['enabled']                 = ! empty( $input['enabled'] );
		$clean['md_suffix']               = ! empty( $input['md_suffix'] );
		$clean['content_signals_enabled'] = ! empty( $input['content_signals_enabled'] );

		$clean['content_signals'] = array();
		foreach ( ContentSignals::SIGNALS as $signal ) {
			$value = strtolower( trim( (string) ( $input['content_signals'][ $signal ] ?? '' ) ) );

			$clean['content_signals'][ $signal ] = in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
		}

		$clean['discovery_enabled'] = ! empty( $input['discovery_enabled'] );
		$clean['webmcp_enabled']    = ! empty( $input['webmcp_enabled'] );

		$servers = array();
		foreach ( preg_split( '/\R+/', (string) ( $input['oauth_authorization_servers'] ?? '' ) ) ?: array() as $line ) {
			$line = esc_url_raw( trim( $line ) );
			if ( '' !== $line ) {
				$servers[] = $line;
			}
		}
		$clean['oauth_authorization_servers'] = $servers;

		$scopes = array();
		foreach ( preg_split( '/[\s,]+/', (string) ( $input['oauth_scopes'] ?? '' ) ) ?: array() as $scope ) {
			$scope = trim( $scope );
			if ( '' !== $scope && preg_match( '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/', $scope ) ) {
				$scopes[] = $scope;
			}
		}
		$clean['oauth_scopes'] = $scopes;

		$clean['agent_register_uri']  = esc_url_raw( trim( (string) ( $input['agent_register_uri'] ?? '' ) ) );
		$clean['contact_email']       = sanitize_email( (string) ( $input['contact_email'] ?? '' ) );
		$clean['resource_policy_uri'] = esc_url_raw( trim( (string) ( $input['resource_policy_uri'] ?? '' ) ) );
		$clean['resource_tos_uri']    = esc_url_raw( trim( (string) ( $input['resource_tos_uri'] ?? '' ) ) );

		$public_types                 = get_post_types( array( 'public' => true ) );
		$clean['excluded_post_types'] = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) ( $input['excluded_post_types'] ?? array() ) ),
				array_keys( $public_types )
			)
		);

		$raw_paths = (string) ( $input['excluded_paths'] ?? '' );
		$paths     = array();
		foreach ( preg_split( '/\R+/', $raw_paths ) ?: array() as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$paths[] = '/' . ltrim( $line, '/' );
		}
		$clean['excluded_paths'] = $paths;

		return $clean;
	}

	/**
	 * Render: master switch.
	 */
	public static function render_enabled_field(): void {
		$settings = self::get();
		printf(
			'<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['enabled'], true, false ),
			esc_html__( 'Answer requests preferring text/markdown with a Markdown rendering of the page.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: .md suffix switch.
	 */
	public static function render_suffix_field(): void {
		$settings = self::get();
		printf(
			'<label><input type="checkbox" name="%1$s[md_suffix]" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( $settings['md_suffix'], true, false ),
			esc_html__( 'Also serve Markdown at an index.md suffix URL (e.g. /traiteur/index.md).', 'bice-markdown-agents' ),
			esc_html__( 'Uses a distinct URL, so it is safe for edge caches.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: post-type exclusion checkboxes.
	 */
	public static function render_post_types_field(): void {
		$settings = self::get();
		$excluded = (array) $settings['excluded_post_types'];

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			printf(
				'<label style="display:block"><input type="checkbox" name="%1$s[excluded_post_types][]" value="%2$s" %3$s> %4$s <code>%2$s</code></label>',
				esc_attr( self::OPTION ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $excluded, true ), true, false ),
				esc_html( $type->labels->singular_name )
			);
		}
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Checked post types never get a Markdown representation.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: path exclusion textarea.
	 */
	public static function render_paths_field(): void {
		$settings = self::get();
		printf(
			'<textarea name="%1$s[excluded_paths]" rows="5" cols="50" class="large-text code">%2$s</textarea><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_textarea( implode( "\n", (array) $settings['excluded_paths'] ) ),
			esc_html__( 'One path per line. Exact match, or prefix match with a trailing * (e.g. /en/private/*).', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: Content Signals section intro.
	 */
	public static function render_signals_intro(): void {
		printf(
			'<p>%s <a href="https://contentsignals.org/" target="_blank" rel="noopener">contentsignals.org</a></p>',
			esc_html__( 'Publish machine-readable AI content-usage preferences in robots.txt. Existing robots.txt rules (WordPress, WooCommerce, SEO plugins) are preserved; the directive is inserted into the first User-agent: * group.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: Content Signals switch.
	 */
	public static function render_signals_enabled_field(): void {
		$settings = self::get();
		printf(
			'<label><input type="checkbox" name="%1$s[content_signals_enabled]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['content_signals_enabled'], true, false ),
			esc_html__( 'Add a Content-Signal directive to robots.txt (only on public sites).', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: per-signal yes/no/no-preference selectors.
	 */
	public static function render_signals_policy_field(): void {
		$settings = self::get();
		$policy   = (array) $settings['content_signals'];

		$labels = array(
			'search'   => __( 'build a search index; return links and short excerpts', 'bice-markdown-agents' ),
			'ai-input' => __( 'supply content to a model at answer time (RAG, live answers)', 'bice-markdown-agents' ),
			'ai-train' => __( 'use content to train or fine-tune a model', 'bice-markdown-agents' ),
		);

		foreach ( ContentSignals::SIGNALS as $signal ) {
			$current = (string) ( $policy[ $signal ] ?? '' );
			printf(
				'<p><select name="%1$s[content_signals][%2$s]">
					<option value="" %3$s>%4$s</option>
					<option value="yes" %5$s>%6$s</option>
					<option value="no" %7$s>%8$s</option>
				</select> <code>%2$s</code> — %9$s</p>',
				esc_attr( self::OPTION ),
				esc_attr( $signal ),
				selected( $current, '', false ),
				esc_html__( 'No preference', 'bice-markdown-agents' ),
				selected( $current, 'yes', false ),
				esc_html__( 'Yes', 'bice-markdown-agents' ),
				selected( $current, 'no', false ),
				esc_html__( 'No', 'bice-markdown-agents' ),
				esc_html( $labels[ $signal ] )
			);
		}

		$line = ContentSignals::signal_line( $policy );
		printf(
			'<p class="description">%s <code>%s</code></p>',
			esc_html__( 'Current directive:', 'bice-markdown-agents' ),
			esc_html( '' !== $line ? $line : __( '(none — every signal is "no preference")', 'bice-markdown-agents' ) )
		);
	}

	/**
	 * Render: agent discovery section intro.
	 */
	public static function render_discovery_intro(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Machine-readable discovery documents for AI agents: /.well-known/oauth-protected-resource (RFC 9728), /auth.md, /.well-known/agent-skills/index.json, plus WebMCP browser tools. Every advertised URL must resolve — run "wp bice-agents verify" after changing these. If your web server blocks dotfile paths, exempt /.well-known/ — see the README.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: discovery endpoints switch.
	 */
	public static function render_discovery_enabled_field(): void {
		$settings = self::get();
		printf(
			'<label><input type="checkbox" name="%1$s[discovery_enabled]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['discovery_enabled'], true, false ),
			esc_html__( 'Serve the well-known discovery documents and /auth.md.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: WebMCP switch.
	 */
	public static function render_webmcp_enabled_field(): void {
		$settings = self::get();
		printf(
			'<label><input type="checkbox" name="%1$s[webmcp_enabled]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['webmcp_enabled'], true, false ),
			esc_html__( 'Register site tools (search, get page as Markdown) with navigator.modelContext on page load.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: OAuth authorization servers textarea.
	 */
	public static function render_oauth_servers_field(): void {
		$settings = self::get();
		printf(
			'<textarea name="%1$s[oauth_authorization_servers]" rows="3" cols="50" class="large-text code">%2$s</textarea><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_textarea( implode( "\n", (array) $settings['oauth_authorization_servers'] ) ),
			esc_html__( 'One OAuth/OIDC issuer URL per line — servers that can issue tokens for this resource. Leave empty if the site has no protected APIs (the metadata will honestly say so).', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: OAuth scopes text field.
	 */
	public static function render_oauth_scopes_field(): void {
		$settings = self::get();
		printf(
			'<input type="text" name="%1$s[oauth_scopes]" value="%2$s" class="regular-text code"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( implode( ' ', (array) $settings['oauth_scopes'] ) ),
			esc_html__( 'Space-separated scope names for scopes_supported (e.g. read write). Leave empty for none.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: agent registration URL.
	 */
	public static function render_register_uri_field(): void {
		$settings = self::get();
		printf(
			'<input type="url" name="%1$s[agent_register_uri]" value="%2$s" class="regular-text code" placeholder="https://…"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) $settings['agent_register_uri'] ),
			esc_html__( 'register_uri for the agent_auth block in /.well-known/oauth-authorization-server. Defaults to /auth.md when empty.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: agent contact email.
	 */
	public static function render_contact_email_field(): void {
		$settings = self::get();
		printf(
			'<input type="email" name="%1$s[contact_email]" value="%2$s" class="regular-text code" placeholder="info@…"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) $settings['contact_email'] ),
			esc_html__( 'Contact shown in auth.md for agents seeking programmatic access. Falls back to the WordPress admin email when empty.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: resource policy URL (RFC 9728 resource_policy_uri).
	 */
	public static function render_policy_uri_field(): void {
		$settings = self::get();
		printf(
			'<input type="url" name="%1$s[resource_policy_uri]" value="%2$s" class="regular-text code" placeholder="https://…"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) $settings['resource_policy_uri'] ),
			esc_html__( 'resource_policy_uri in the RFC 9728 metadata (e.g. your conditions of use page). Omitted when empty.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render: terms of service URL (RFC 9728 resource_tos_uri).
	 */
	public static function render_tos_uri_field(): void {
		$settings = self::get();
		printf(
			'<input type="url" name="%1$s[resource_tos_uri]" value="%2$s" class="regular-text code" placeholder="https://…"><p class="description">%3$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) $settings['resource_tos_uri'] ),
			esc_html__( 'resource_tos_uri in the RFC 9728 metadata. Omitted when empty.', 'bice-markdown-agents' )
		);
	}

	/**
	 * Render the full settings page, including cache-layer diagnostics.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$detected = CacheCompat::detect();
		$dropin   = CacheCompat::has_advanced_cache_dropin();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Markdown for Agents', 'bice-markdown-agents' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Cache layer diagnostics', 'bice-markdown-agents' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Caching plugin detected', 'bice-markdown-agents' ); ?></td>
						<td><?php echo esc_html( $detected ? implode( ', ', $detected ) : __( 'None recognised', 'bice-markdown-agents' ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'advanced-cache.php drop-in', 'bice-markdown-agents' ); ?></td>
						<td>
							<?php
							if ( $dropin ) {
								$signature = CacheCompat::dropin_signature();
								echo esc_html(
									'' !== $signature
										? sprintf( /* translators: %s: drop-in first comment line */ __( 'Active — signature: %s', 'bice-markdown-agents' ), $signature )
										: __( 'Active (unidentified implementation)', 'bice-markdown-agents' )
								);
							} else {
								esc_html_e( 'Not present', 'bice-markdown-agents' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php if ( $dropin ) : ?>
				<div class="notice notice-warning inline" style="max-width:700px">
					<p>
						<strong><?php esc_html_e( 'Manual configuration required:', 'bice-markdown-agents' ); ?></strong>
						<?php esc_html_e( 'A page-cache drop-in serves cache hits before this plugin loads, so requests with Accept: text/markdown can receive cached HTML unless the cache layer in front (drop-in, nginx, Cloudflare) is told to bypass or segment those requests. See the README section "The cache layer caveat" for the nginx snippet and the Cloudflare cache rule.', 'bice-markdown-agents' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
