<?php
/**
 * Settings screen, and the Test Connection action behind it.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Settings;

use WPSupabaseSync\Client\ApiException;
use WPSupabaseSync\Client\Response;
use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Support\Logger;
use WPSupabaseSync\Support\Redactor;

use const WPSupabaseSync\PLUGIN_FILE;
use const WPSupabaseSync\VERSION;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const PAGE_SLUG     = 'wpsb-settings';
	private const OPTION_GROUP = 'wpsb_settings_group';
	private const AJAX_ACTION  = 'wpsb_test_connection';
	private const AJAX_NONCE   = 'wpsb_test_connection_nonce';

	public function __construct(
		private readonly Settings $settings,
		private readonly SupabaseClient $client,
		private readonly Logger $logger
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_test_connection' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'WP Supabase Sync', 'wp-supabase-sync' ),
			__( 'Supabase Sync', 'wp-supabase-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				// This option holds credentials. It must never be readable
				// through the REST API, which is what show_in_rest would do.
				'show_in_rest'      => false,
			)
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$base = plugin_dir_url( PLUGIN_FILE ) . 'assets/';

		wp_enqueue_style( 'wpsb-admin', $base . 'admin.css', array(), VERSION );
		wp_enqueue_script( 'wpsb-admin', $base . 'admin.js', array(), VERSION, true );

		// Only an action name and a nonce cross into JavaScript. The keys stay
		// server-side; the browser never receives them, not even redacted.
		wp_localize_script(
			'wpsb-admin',
			'wpsbAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'i18n'    => array(
					'testing'   => __( 'Testing…', 'wp-supabase-sync' ),
					'testAgain' => __( 'Test Connection', 'wp-supabase-sync' ),
					'failed'    => __( 'The test could not run. Check the browser console and your PHP error log.', 'wp-supabase-sync' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'wp-supabase-sync' ) );
		}

		$this->settings->refresh();

		echo '<div class="wrap wpsb-settings">';
		echo '<h1>' . esc_html__( 'WP Supabase Sync', 'wp-supabase-sync' ) . '</h1>';
		echo '<p class="description">' . esc_html__(
			'Mirrors published WordPress content into a Supabase table so another frontend can read it directly. WordPress stays the source of truth.',
			'wp-supabase-sync'
		) . '</p>';

		settings_errors();

		$this->render_readiness_panel();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';

		settings_fields( self::OPTION_GROUP );

		$this->render_connection_fields();
		$this->render_content_fields();
		$this->render_behaviour_fields();

		submit_button( __( 'Save Settings', 'wp-supabase-sync' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * The Test Connection panel.
	 *
	 * Placed above the form on purpose. The first thing someone does on this
	 * screen after pasting credentials is look for confirmation that they work,
	 * and making them scroll past every other setting to find it is a small
	 * cruelty.
	 */
	private function render_readiness_panel(): void {
		$configured = $this->settings->is_configured();

		echo '<div class="wpsb-panel">';
		echo '<h2>' . esc_html__( 'Connection', 'wp-supabase-sync' ) . '</h2>';

		if ( ! $configured ) {
			echo '<p>' . esc_html__(
				'Add your project URL and service role key below, save, then run the test.',
				'wp-supabase-sync'
			) . '</p>';
		}

		printf(
			'<p><button type="button" class="button button-secondary" id="wpsb-test-connection" %s>%s</button></p>',
			disabled( ! $configured, true, false ),
			esc_html__( 'Test Connection', 'wp-supabase-sync' )
		);

		echo '<div id="wpsb-test-results" class="wpsb-results" aria-live="polite"></div>';

		if ( ! $this->settings->sync_enabled() ) {
			echo '<p class="wpsb-hint">' . esc_html__(
				'Syncing is currently off. That is the default: the plugin will not write to your database until you have confirmed the connection works.',
				'wp-supabase-sync'
			) . '</p>';
		}

		echo '</div>';
	}

	private function render_connection_fields(): void {
		echo '<h2 class="title">' . esc_html__( 'Supabase project', 'wp-supabase-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_url_field();
		$this->render_key_field(
			'service_role_key',
			__( 'Service role key', 'wp-supabase-sync' ),
			Settings::CONST_SERVICE_KEY,
			$this->settings->service_role_key(),
			$this->settings->service_key_source(),
			__( 'Used for all writes. It bypasses row level security, so treat it like a database password — define it in wp-config.php rather than storing it here.', 'wp-supabase-sync' )
		);
		$this->render_key_field(
			'anon_key',
			__( 'Anon key', 'wp-supabase-sync' ),
			Settings::CONST_ANON_KEY,
			$this->settings->anon_key(),
			$this->settings->anon_key_source(),
			__( 'Optional, and never used for writing. Diagnostics uses it to confirm that the public can read published rows and cannot read anything else.', 'wp-supabase-sync' )
		);

		echo '</tbody></table>';
	}

	private function render_url_field(): void {
		$from_constant = Settings::SOURCE_CONSTANT === $this->settings->url_source();

		echo '<tr><th scope="row">';
		printf(
			'<label for="wpsb_project_url">%s</label>',
			esc_html__( 'Project URL', 'wp-supabase-sync' )
		);
		echo '</th><td>';

		if ( $from_constant ) {
			printf(
				'<code>%s</code><p class="description">%s</p>',
				esc_html( $this->settings->project_url() ),
				sprintf(
					/* translators: %s: PHP constant name. */
					esc_html__( 'Set in wp-config.php via %s.', 'wp-supabase-sync' ),
					'<code>' . esc_html( Settings::CONST_URL ) . '</code>'
				)
			);
		} else {
			printf(
				'<input type="url" id="wpsb_project_url" name="%s[project_url]" value="%s" class="regular-text" placeholder="https://yourproject.supabase.co" />',
				esc_attr( Settings::OPTION ),
				esc_attr( (string) $this->settings->all()['project_url'] )
			);
			echo '<p class="description">' . esc_html__(
				'The project URL from Settings → Data API in your Supabase dashboard. Paste the base URL only; the plugin appends /rest/v1 itself.',
				'wp-supabase-sync'
			) . '</p>';
		}//end if

		echo '</td></tr>';
	}

	/**
	 * Render a credential field.
	 *
	 * The stored key is never rendered into the HTML — not in a value attribute,
	 * not in a data attribute, not even masked in a way that could be reversed.
	 * The field always starts empty, and an empty submission means "unchanged",
	 * which is why clearing a key needs its own checkbox.
	 */
	private function render_key_field(
		string $field,
		string $label,
		string $constant,
		string $value,
		string $source,
		string $description
	): void {
		$id   = 'wpsb_' . $field;
		$info = KeyInspector::inspect( $value );

		echo '<tr><th scope="row">';
		printf( '<label for="%s">%s</label>', esc_attr( $id ), esc_html( $label ) );
		echo '</th><td>';

		if ( Settings::SOURCE_CONSTANT === $source ) {
			printf(
				'<p><code>%s</code> %s</p>',
				esc_html( (string) $info['fingerprint'] ),
				sprintf(
					/* translators: %s: PHP constant name. */
					esc_html__( '— set in wp-config.php via %s.', 'wp-supabase-sync' ),
					'<code>' . esc_html( $constant ) . '</code>'
				)
			);
		} else {
			printf(
				'<input type="password" id="%s" name="%s[%s]" value="" class="regular-text" autocomplete="new-password" spellcheck="false" />',
				esc_attr( $id ),
				esc_attr( Settings::OPTION ),
				esc_attr( $field )
			);

			if ( '' !== $value ) {
				printf(
					'<p class="description">%s</p>',
					sprintf(
						/* translators: %s: masked key fingerprint. */
						esc_html__( 'A key is stored (%s). Leave this blank to keep it.', 'wp-supabase-sync' ),
						'<code>' . esc_html( (string) $info['fingerprint'] ) . '</code>'
					)
				);
				printf(
					'<p><label><input type="checkbox" name="%s[clear_%s]" value="1" /> %s</label></p>',
					esc_attr( Settings::OPTION ),
					esc_attr( $field ),
					esc_html__( 'Remove the stored key', 'wp-supabase-sync' )
				);
			}
		}//end if

		if ( '' !== $value ) {
			echo '<p class="description">' . esc_html( KeyInspector::describe( $info ) ) . '</p>';
			$this->render_key_warning( $field, $info );
		}

		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Warn when a key is obviously the wrong one for the field it is in.
	 *
	 * Catching this here, before any request is made, saves the classic hour of
	 * debugging "why do my writes silently do nothing" — the anon key in the
	 * service field produces empty results rather than an error, because row
	 * level security is doing exactly what it was told to.
	 *
	 * @param array<string, mixed> $info Result of KeyInspector::inspect().
	 */
	private function render_key_warning( string $field, array $info ): void {
		$message = '';

		if ( true === ( $info['is_expired'] ?? false ) ) {
			$message = __( 'This key has expired. Supabase will reject it with PGRST301.', 'wp-supabase-sync' );
		} elseif ( 'service_role_key' === $field && KeyInspector::is_public_key( $info ) ) {
			$message = __( 'This looks like a publishable or anon key, not a service role key. Writes will be silently filtered by row level security rather than failing loudly.', 'wp-supabase-sync' );
		} elseif ( 'anon_key' === $field && KeyInspector::is_service_key( $info ) ) {
			$message = __( 'This looks like a service role key, not an anon key. Diagnostics uses this key to prove that the public cannot see unpublished rows, and a service key bypasses row level security entirely — so that check would wrongly appear to pass.', 'wp-supabase-sync' );
		} elseif ( KeyInspector::SHAPE_UNKNOWN === ( $info['shape'] ?? '' ) ) {
			$message = (string) $info['note'];
		}

		if ( '' === $message ) {
			return;
		}

		printf(
			'<p class="wpsb-warning"><strong>%s</strong> %s</p>',
			esc_html__( 'Check this key:', 'wp-supabase-sync' ),
			esc_html( $message )
		);
	}

	private function render_content_fields(): void {
		$all = $this->settings->all();

		echo '<h2 class="title">' . esc_html__( 'What gets mirrored', 'wp-supabase-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="wpsb_site_id">%s</label></th><td>'
			. '<input type="text" id="wpsb_site_id" name="%s[site_id]" value="%s" class="regular-text" />'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Site ID', 'wp-supabase-sync' ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $all['site_id'] ),
			esc_html__( 'Identifies this site in the mirror table. Several WordPress sites can share one Supabase project as long as each has a distinct Site ID — it is half of the uniqueness constraint used for upserts. Changing it later orphans the rows written under the old value.', 'wp-supabase-sync' )
		);

		printf(
			'<tr><th scope="row"><label for="wpsb_table_name">%s</label></th><td>'
			. '<input type="text" id="wpsb_table_name" name="%s[table_name]" value="%s" class="regular-text" />'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Table name', 'wp-supabase-sync' ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) $all['table_name'] ),
			esc_html__( 'The table in your public schema that content is written to. Lowercase letters, numbers and underscores only.', 'wp-supabase-sync' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'wp-supabase-sync' ) . '</th><td>';
		echo '<fieldset>';

		$selected = $this->settings->synced_post_types();

		foreach ( $this->available_post_types() as $name => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[synced_post_types][]" value="%s" %s /> %s <code>%s</code></label>',
				esc_attr( Settings::OPTION ),
				esc_attr( $name ),
				checked( in_array( $name, $selected, true ), true, false ),
				esc_html( $label ),
				esc_html( $name )
			);
		}

		echo '</fieldset>';
		echo '<p class="description">' . esc_html__(
			'Only public post types are listed. Private ones have no business in a table a public frontend reads.',
			'wp-supabase-sync'
		) . '</p>';
		echo '</td></tr>';

		printf(
			'<tr><th scope="row"><label for="wpsb_synced_meta_keys">%s</label></th><td>'
			. '<textarea id="wpsb_synced_meta_keys" name="%s[synced_meta_keys]" rows="4" class="large-text code">%s</textarea>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Meta keys', 'wp-supabase-sync' ),
			esc_attr( Settings::OPTION ),
			esc_textarea( implode( "\n", $this->settings->synced_meta_keys() ) ),
			esc_html__( 'One key per line. This is an allowlist, and deliberately empty by default: post meta routinely holds page-builder blobs and third-party plugin state that should not be published.', 'wp-supabase-sync' )
		);

		echo '</tbody></table>';
	}

	private function render_behaviour_fields(): void {
		$all = $this->settings->all();

		echo '<h2 class="title">' . esc_html__( 'Behaviour', 'wp-supabase-sync' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[sync_enabled]" value="1" %s /> %s</label>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Enable syncing', 'wp-supabase-sync' ),
			esc_attr( Settings::OPTION ),
			checked( (bool) $all['sync_enabled'], true, false ),
			esc_html__( 'Write changes to Supabase', 'wp-supabase-sync' ),
			esc_html__( 'Off by default. Run the connection test first.', 'wp-supabase-sync' )
		);

		printf(
			'<tr><th scope="row"><label for="wpsb_batch_size">%s</label></th><td>'
			. '<input type="number" id="wpsb_batch_size" name="%s[batch_size]" value="%s" min="1" max="%s" class="small-text" />'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Batch size', 'wp-supabase-sync' ),
			esc_attr( Settings::OPTION ),
			esc_attr( (string) (int) $all['batch_size'] ),
			esc_attr( (string) Settings::MAX_BATCH_SIZE ),
			esc_html(
				sprintf(
					/* translators: %d: maximum batch size. */
					__( 'How many queued changes are sent per request. Maximum %d.', 'wp-supabase-sync' ),
					Settings::MAX_BATCH_SIZE
				)
			)
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[delete_data_on_uninstall]" value="1" %s /> %s</label>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Uninstall', 'wp-supabase-sync' ),
			esc_attr( Settings::OPTION ),
			checked( (bool) $all['delete_data_on_uninstall'], true, false ),
			esc_html__( 'Delete this plugin\'s tables and settings when it is uninstalled', 'wp-supabase-sync' ),
			esc_html__( 'Only affects WordPress. Nothing in Supabase is ever deleted by uninstalling.', 'wp-supabase-sync' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Public post types the user may choose to mirror.
	 *
	 * @return array<string, string> Name mapped to label.
	 */
	private function available_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$list  = array();

		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$list[ $type->name ] = $type->labels->name ?? $type->name;
		}

		return $list;
	}

	/**
	 * Run the connection test and return results as JSON.
	 *
	 * Ordered, and short-circuits: if the project cannot be reached there is no
	 * point reporting that authentication failed too. One accurate red beats
	 * four cascading ones — the same rule the full diagnostics runner follows.
	 */
	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run this test.', 'wp-supabase-sync' ) ),
				403
			);
		}

		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		$this->settings->refresh();

		$results = $this->run_connection_test();

		$this->logger->info(
			'settings',
			__( 'Connection test run from the settings screen.', 'wp-supabase-sync' ),
			array( 'results' => wp_list_pluck( $results, 'status', 'id' ) )
		);

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Ordered results for the Test Connection panel, short-circuiting like the
	 * full diagnostics runner does.
	 *
	 * @return array<int, array{id: string, label: string, status: string, detail: string}>
	 */
	private function run_connection_test(): array {
		$results = array();

		$url = $this->settings->project_url();
		$key = $this->settings->service_role_key();

		if ( '' === $url || '' === $key ) {
			$results[] = $this->result(
				'settings',
				__( 'Configuration', 'wp-supabase-sync' ),
				'fail',
				'' === $url
					? __( 'No project URL is set.', 'wp-supabase-sync' )
					: __( 'No service role key is set.', 'wp-supabase-sync' )
			);

			return $results;
		}

		$results[] = $this->result(
			'settings',
			__( 'Configuration', 'wp-supabase-sync' ),
			'pass',
			sprintf(
				/* translators: %s: project URL. */
				__( 'Project URL is %s, and a service role key is set.', 'wp-supabase-sync' ),
				$url
			)
		);

		$info      = KeyInspector::inspect( $key );
		$results[] = $this->key_shape_result( $info );

		$service_label = __( 'Reachability and authentication (service role key)', 'wp-supabase-sync' );

		try {
			$service_response = $this->client->ping( SupabaseClient::ROLE_SERVICE );
		} catch ( ApiException $e ) {
			$results[] = $this->result( 'service_reachable', $service_label, 'fail', $e->getMessage() );

			return $results;
		}

		$results[] = $this->ping_result( 'service_reachable', $service_label, $service_response );

		if ( '' === $this->settings->anon_key() ) {
			return $results;
		}

		$anon_label = __( 'Authentication (anon key)', 'wp-supabase-sync' );

		/*
		 * Skip the anon key only when the host itself was unreachable, because
		 * then the second attempt can only restate the first failure.
		 *
		 * A *rejected* service key is different: the project answered, and the
		 * anon key is a separate credential that may well be fine. Skipping on
		 * any failure would hide the useful case where one key was re-copied and
		 * the other was not.
		 */
		if ( $service_response->is_transport_error() ) {
			$results[] = $this->result(
				'anon_reachable',
				$anon_label,
				'skip',
				__( 'Skipped: the project could not be reached at all, so testing a second key would only repeat the same failure.', 'wp-supabase-sync' )
			);

			return $results;
		}

		try {
			$results[] = $this->ping_result( 'anon_reachable', $anon_label, $this->client->ping( SupabaseClient::ROLE_ANON ) );
		} catch ( ApiException $e ) {
			$results[] = $this->result( 'anon_reachable', $anon_label, 'fail', $e->getMessage() );
		}

		return $results;
	}

	/**
	 * Judge the key before any request is made.
	 *
	 * @param array<string, mixed> $info Result of KeyInspector::inspect().
	 * @return array{id: string, label: string, status: string, detail: string}
	 */
	private function key_shape_result( array $info ): array {
		$label = __( 'Service role key shape', 'wp-supabase-sync' );

		if ( true === ( $info['is_expired'] ?? false ) ) {
			return $this->result(
				'key_shape',
				$label,
				'fail',
				__( 'The key has expired, so Supabase will reject it with PGRST301. Copy a current key from Settings → API Keys.', 'wp-supabase-sync' )
			);
		}

		if ( KeyInspector::is_public_key( $info ) ) {
			return $this->result(
				'key_shape',
				$label,
				'fail',
				__( 'This is an anon or publishable key, not a service role key. Row level security will filter every write instead of rejecting it, so syncing would appear to work while writing nothing.', 'wp-supabase-sync' )
			);
		}

		if ( KeyInspector::is_service_key( $info ) ) {
			return $this->result(
				'key_shape',
				$label,
				'pass',
				KeyInspector::describe( $info )
			);
		}

		return $this->result(
			'key_shape',
			$label,
			'warn',
			trim( KeyInspector::describe( $info ) . ' ' . (string) $info['note'] )
		);
	}

	/**
	 * Turn a ping response into a reportable result.
	 *
	 * @return array{id: string, label: string, status: string, detail: string}
	 */
	private function ping_result( string $id, string $label, Response $response ): array {
		if ( $response->is_transport_error() ) {
			return $this->result( $id, $label, 'fail', $this->transport_detail( $response ) );
		}

		if ( $response->is_success() ) {
			return $this->result(
				$id,
				$label,
				'pass',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The PostgREST root returned HTTP %d and the key was accepted. This confirms reachability and a valid key; it does not confirm the key has table privileges — that needs the full diagnostics run.', 'wp-supabase-sync' ),
					$response->status
				)
			);
		}

		return $this->result( $id, $label, 'fail', $this->failure_detail( $response ) );
	}

	/**
	 * Explain a request that never reached Supabase.
	 *
	 * The three causes below account for nearly every occurrence, and naming
	 * them is far more useful than repeating cURL's message alone.
	 */
	private function transport_detail( Response $response ): string {
		$detail = sprintf(
			/* translators: 1: WP_Error code, 2: error message. */
			__( 'WordPress could not complete the request (%1$s: %2$s).', 'wp-supabase-sync' ),
			$response->transport_code,
			$response->transport_error
		);

		return $detail . ' ' . __(
			'Usually one of: the URL is wrong, outbound HTTP is blocked on this host, or WP_HTTP_BLOCK_EXTERNAL is set in wp-config.php. If WordPress is running in a container, note that the host machine is not "localhost" from inside it.',
			'wp-supabase-sync'
		);
	}

	/**
	 * Explain an HTTP failure.
	 *
	 * States the observation first — status, code, message — then the likely
	 * cause. A detail that only says "something went wrong" is worse than no
	 * diagnostics at all, because it costs the reader time to discover it is
	 * useless.
	 */
	private function failure_detail( Response $response ): string {
		$observed = sprintf(
			/* translators: 1: request path, 2: outcome summary. */
			__( '%1$s returned %2$s.', 'wp-supabase-sync' ),
			'/rest/v1/',
			$response->summary()
		);

		$code = $response->error_code();

		if ( 'PGRST301' === $code || 401 === $response->status || 403 === $response->status ) {
			return $observed . ' ' . __(
				'The key was rejected. Re-copy it from Settings → API Keys, and confirm it belongs to the same project as the URL above.',
				'wp-supabase-sync'
			);
		}

		if ( 404 === $response->status ) {
			return $observed . ' ' . __(
				'The project URL is reachable but is not serving PostgREST at /rest/v1/. Check that the URL is the project URL rather than the dashboard or Studio URL.',
				'wp-supabase-sync'
			);
		}

		if ( $response->status >= 500 ) {
			return $observed . ' ' . __(
				'That is a server-side error, so it is worth retrying before changing anything.',
				'wp-supabase-sync'
			);
		}

		return $observed;
	}

	/**
	 * Build one result, redacting the detail as it goes.
	 *
	 * @return array{id: string, label: string, status: string, detail: string}
	 */
	private function result( string $id, string $label, string $status, string $detail ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			// Belt and braces. Nothing here should contain a key, and if
			// something ever does, it does not reach the browser.
			'detail' => Redactor::redact( $detail ),
		);
	}
}
