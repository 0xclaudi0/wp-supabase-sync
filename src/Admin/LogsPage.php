<?php
/**
 * Tools → Supabase Sync Logs.
 *
 * Everything shown here was redacted on the way into the database, not on the way
 * out, so there is no path by which this screen can print a key.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Admin;

use WPSupabaseSync\Sync\Queue;
use WPSupabaseSync\Support\Logger;

use const WPSupabaseSync\PLUGIN_FILE;
use const WPSupabaseSync\VERSION;

defined( 'ABSPATH' ) || exit;

final class LogsPage {

	public const PAGE_SLUG = 'wpsb-logs';

	private const NONCE_ACTION = 'wpsb_logs_action';
	private const NONCE_FIELD  = 'wpsb_logs_nonce';

	public function __construct(
		private readonly Logger $logger,
		private readonly Queue $queue
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_wpsb_logs_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'tools.php',
			__( 'Supabase Sync Logs', 'wp-supabase-sync' ),
			__( 'Supabase Sync Logs', 'wp-supabase-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wpsb-admin',
			plugin_dir_url( PLUGIN_FILE ) . 'assets/admin.css',
			array(),
			VERSION
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-supabase-sync' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Supabase Sync Logs', 'wp-supabase-sync' ) . '</h1>';

		$this->render_queue_table();
		$this->render_log_table();

		echo '</div>';
	}

	private function render_queue_table(): void {
		$rows = $this->queue->all( '', 50 );

		echo '<h2>' . esc_html__( 'Queue', 'wp-supabase-sync' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'The queue is empty.', 'wp-supabase-sync' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array(
			__( 'Object', 'wp-supabase-sync' ),
			__( 'Action', 'wp-supabase-sync' ),
			__( 'Status', 'wp-supabase-sync' ),
			__( 'Attempts', 'wp-supabase-sync' ),
			__( 'Next attempt', 'wp-supabase-sync' ),
			__( 'Last error', 'wp-supabase-sync' ),
		) as $heading ) {
			printf( '<th>%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$post_id = (int) $row['object_id'];
			$title   = get_the_title( $post_id );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_html( sprintf( '#%d %s', $post_id, '' !== $title ? $title : __( '(no title)', 'wp-supabase-sync' ) ) ),
				esc_html( (string) $row['action'] ),
				esc_html( (string) $row['status'] ),
				(int) $row['attempts'],
				esc_html( (string) $row['available_at'] ),
				esc_html( (string) ( $row['last_error'] ?? '' ) )
			);
		}

		echo '</tbody></table>';
	}

	private function render_log_table(): void {
		$rows = $this->logger->recent( 100 );

		echo '<h2>' . esc_html__( 'Recent events', 'wp-supabase-sync' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:12px;">';
		echo '<input type="hidden" name="action" value="wpsb_logs_action" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		submit_button( __( 'Delete logs older than 7 days', 'wp-supabase-sync' ), 'secondary', 'wpsb_purge', false );
		echo '</form>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nothing logged yet.', 'wp-supabase-sync' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array(
			__( 'When (UTC)', 'wp-supabase-sync' ),
			__( 'Level', 'wp-supabase-sync' ),
			__( 'Channel', 'wp-supabase-sync' ),
			__( 'Message', 'wp-supabase-sync' ),
		) as $heading ) {
			printf( '<th>%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s',
				esc_html( (string) $row['created_at'] ),
				esc_html( (string) $row['level'] ),
				esc_html( (string) $row['channel'] ),
				esc_html( (string) $row['message'] )
			);

			if ( ! empty( $row['context'] ) ) {
				printf(
					'<details><summary>%s</summary><pre>%s</pre></details>',
					esc_html__( 'Details', 'wp-supabase-sync' ),
					esc_html( $this->pretty( (string) $row['context'] ) )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Re-indent stored JSON for display, falling back to the raw string.
	 */
	private function pretty( string $json ): string {
		$decoded = json_decode( $json, true );

		if ( null === $decoded ) {
			return $json;
		}

		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $pretty ) ? $pretty : $json;
	}

	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-supabase-sync' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( isset( $_POST['wpsb_purge'] ) ) {
			$this->logger->purge_older_than( 7 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'tools.php' ) ) );

		exit;
	}
}
