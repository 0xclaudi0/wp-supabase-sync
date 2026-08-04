<?php
/**
 * Tools → Supabase Sync. The diagnostics screen.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Admin;

use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\DiagnosticsRunner;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Settings\SettingsPage;
use WPSupabaseSync\Sync\Backfill;
use WPSupabaseSync\Sync\Queue;
use WPSupabaseSync\Sync\Schema;
use WPSupabaseSync\Sync\SyncEngine;

use const WPSupabaseSync\PLUGIN_FILE;
use const WPSupabaseSync\VERSION;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

	public const PAGE_SLUG = 'wpsb-diagnostics';

	private const NONCE_ACTION = 'wpsb_diagnostics_action';
	private const NONCE_FIELD  = 'wpsb_diagnostics_nonce';

	public function __construct(
		private readonly Settings $settings,
		private readonly DiagnosticsRunner $diagnostics,
		private readonly Queue $queue,
		private readonly SyncEngine $engine,
		private readonly Backfill $backfill
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_wpsb_diagnostics_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'tools.php',
			__( 'Supabase Sync Diagnostics', 'wp-supabase-sync' ),
			__( 'Supabase Sync', 'wp-supabase-sync' ),
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

		echo '<div class="wrap wpsb-diagnostics">';
		echo '<h1>' . esc_html__( 'Supabase Sync Diagnostics', 'wp-supabase-sync' ) . '</h1>';

		$this->render_notice();

		$results = $this->diagnostics->run();
		$counts  = DiagnosticsRunner::summarize( $results );

		$this->render_headline( $counts );
		$this->render_actions();
		$this->render_results( $results );
		$this->render_migration();

		echo '</div>';
	}

	/**
	 * One-line verdict at the top, so the answer is visible without scrolling.
	 *
	 * @param array<string, int> $counts Status counts.
	 */
	private function render_headline( array $counts ): void {
		if ( $counts[ Check::STATUS_FAIL ] > 0 ) {
			$class   = 'notice notice-error';
			$message = sprintf(
				/* translators: %d: number of failures. */
				_n(
					'%d check failed. The detail below names the layer at fault.',
					'%d checks failed. The detail below names the layer at fault.',
					$counts[ Check::STATUS_FAIL ],
					'wp-supabase-sync'
				),
				$counts[ Check::STATUS_FAIL ]
			);
		} elseif ( $counts[ Check::STATUS_WARN ] > 0 ) {
			$class   = 'notice notice-warning';
			$message = __( 'Everything works, with warnings worth reading.', 'wp-supabase-sync' );
		} else {
			$class   = 'notice notice-success';
			$message = __( 'All checks passed.', 'wp-supabase-sync' );
		}

		printf(
			'<div class="%s"><p><strong>%s</strong></p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	private function render_actions(): void {
		$stats = $this->queue->stats();

		echo '<div class="wpsb-panel">';
		echo '<h2>' . esc_html__( 'Queue', 'wp-supabase-sync' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: pending, 2: ready, 3: dead, 4: eligible posts. */
					__( '%1$d pending, %2$d ready now, %3$d dead-lettered. %4$d published posts are eligible to sync.', 'wp-supabase-sync' ),
					$stats['pending'],
					$stats['ready'],
					$stats['dead'],
					$this->backfill->eligible_count()
				)
			)
		);

		printf( '<p>%s</p>', esc_html( sprintf( /* translators: %s: description of the last run. */ __( 'Last run: %s', 'wp-supabase-sync' ), $this->engine->last_run_description() ) ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpsb_diagnostics_action" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		submit_button( __( 'Process queue now', 'wp-supabase-sync' ), 'primary', 'wpsb_process', false );
		echo ' ';
		submit_button( __( 'Queue a full resync', 'wp-supabase-sync' ), 'secondary', 'wpsb_backfill', false );
		echo ' ';
		submit_button( __( 'Retry dead-lettered', 'wp-supabase-sync' ), 'secondary', 'wpsb_retry', false );
		echo ' ';
		submit_button( __( 'Clear queue', 'wp-supabase-sync' ), 'delete', 'wpsb_clear', false );

		echo '</form>';

		if ( ! $this->settings->sync_enabled() ) {
			printf(
				'<p class="wpsb-warning">%s</p>',
				sprintf(
					/* translators: %s: link to the settings page. */
					wp_kses_post( __( 'Syncing is turned off, so processing the queue will do nothing. Enable it under %s.', 'wp-supabase-sync' ) ),
					'<a href="' . esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG ) ) . '">'
						. esc_html__( 'Settings → Supabase Sync', 'wp-supabase-sync' ) . '</a>'
				)
			);
		}

		echo '</div>';
	}

	/**
	 * The check list, with copy-pasteable SQL for anything that failed.
	 *
	 * @param Check[] $results Results.
	 */
	private function render_results( array $results ): void {
		echo '<h2>' . esc_html__( 'Checks', 'wp-supabase-sync' ) . '</h2>';
		echo '<ul class="wpsb-results-list">';

		foreach ( $results as $check ) {
			printf(
				'<li class="wpsb-result wpsb-result--%s">',
				esc_attr( $check->status )
			);

			printf(
				'<span class="wpsb-badge" aria-hidden="true">%s</span><strong>%s</strong>',
				esc_html( $this->badge( $check->status ) ),
				esc_html( $check->label )
			);

			printf( '<p>%s</p>', esc_html( $check->detail ) );

			if ( '' !== $check->fix ) {
				echo '<div class="wpsb-fix">';
				printf( '<p><strong>%s</strong></p>', esc_html__( 'How to fix it', 'wp-supabase-sync' ) );

				// The fix often contains SQL across several lines. Rendering it in
				// a <pre> keeps it copy-pasteable, which is the entire point.
				if ( str_contains( $check->fix, "\n" ) ) {
					printf( '<pre>%s</pre>', esc_html( $check->fix ) );
				} else {
					printf( '<p>%s</p>', esc_html( $check->fix ) );
				}

				echo '</div>';
			}

			if ( '' !== $check->doc_url ) {
				printf(
					'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
					esc_url( $check->doc_url ),
					esc_html__( 'Background reading on this failure mode', 'wp-supabase-sync' )
				);
			}

			echo '</li>';
		}//end foreach

		echo '</ul>';
	}

	private function render_migration(): void {
		echo '<h2>' . esc_html__( 'Migration SQL', 'wp-supabase-sync' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Generated from your current settings and the plugin\'s field list, so it cannot drift from what the plugin actually writes. Run it in the Supabase SQL editor. The plugin never executes schema changes itself.',
			'wp-supabase-sync'
		) . '</p>';

		printf(
			'<textarea class="large-text code" rows="26" readonly onclick="this.select()">%s</textarea>',
			esc_textarea( ( new Schema( $this->settings ) )->migration() )
		);
	}

	private function badge( string $status ): string {
		$badges = array(
			Check::STATUS_PASS => '✓',
			Check::STATUS_WARN => '!',
			Check::STATUS_FAIL => '✗',
			Check::STATUS_SKIP => '–',
		);

		return $badges[ $status ] ?? '?';
	}

	/**
	 * Handle the queue action buttons.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-supabase-sync' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$notice = '';

		if ( isset( $_POST['wpsb_process'] ) ) {
			$result = $this->engine->process_queue();
			$notice = sprintf( 'processed-%d-%d', (int) $result['succeeded'], (int) $result['failed'] );
		} elseif ( isset( $_POST['wpsb_backfill'] ) ) {
			$this->backfill->reset();

			$total = 0;

			// Bounded so a very large site cannot exhaust the request. Whatever is
			// left is picked up by the next click or the next cron run.
			for ( $i = 0; $i < 25; $i++ ) {
				$batch  = $this->backfill->run_batch();
				$total += $batch['enqueued'];

				if ( $batch['done'] ) {
					break;
				}
			}

			$notice = sprintf( 'queued-%d', $total );
		} elseif ( isset( $_POST['wpsb_retry'] ) ) {
			$notice = sprintf( 'retried-%d', $this->queue->retry_all() );
		} elseif ( isset( $_POST['wpsb_clear'] ) ) {
			$notice = sprintf( 'cleared-%d', $this->queue->clear() );
		}//end if

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'wpsb_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);

		exit;
	}

	private function render_notice(): void {
		/*
		 * Read-only display of the outcome of a redirect that already passed
		 * check_admin_referer in handle_action(). No state changes here, and the
		 * value is sanitized and only ever compared against a fixed list below,
		 * so there is nothing for a nonce to protect.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['wpsb_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wpsb_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$parts = explode( '-', $notice );
		$verb  = $parts[0];

		switch ( $verb ) {
			case 'processed':
				$message = sprintf(
					/* translators: 1: succeeded count, 2: failed count. */
					__( 'Queue processed: %1$d succeeded, %2$d failed.', 'wp-supabase-sync' ),
					(int) ( $parts[1] ?? 0 ),
					(int) ( $parts[2] ?? 0 )
				);
				break;

			case 'queued':
				$message = sprintf(
					/* translators: %d: number of posts queued. */
					__( 'Queued %d post(s) for resync.', 'wp-supabase-sync' ),
					(int) ( $parts[1] ?? 0 )
				);
				break;

			case 'retried':
				$message = sprintf(
					/* translators: %d: number of rows reset. */
					__( 'Reset %d dead-lettered item(s) for retry.', 'wp-supabase-sync' ),
					(int) ( $parts[1] ?? 0 )
				);
				break;

			case 'cleared':
				$message = sprintf(
					/* translators: %d: number of rows removed. */
					__( 'Removed %d queue row(s).', 'wp-supabase-sync' ),
					(int) ( $parts[1] ?? 0 )
				);
				break;

			default:
				return;
		}//end switch

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
