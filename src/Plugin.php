<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * Lazy accessors rather than a container: the dependency graph is small enough to
 * read top to bottom, and a front-end request that syncs nothing should not
 * construct the diagnostics stack to find that out.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync;

use WPSupabaseSync\Admin\DiagnosticsPage;
use WPSupabaseSync\Admin\LogsPage;
use WPSupabaseSync\Admin\Notices;
use WPSupabaseSync\Cli\Commands;
use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Diagnostics\DiagnosticsRunner;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Settings\SettingsPage;
use WPSupabaseSync\Support\Logger;
use WPSupabaseSync\Sync\Backfill;
use WPSupabaseSync\Sync\Hooks;
use WPSupabaseSync\Sync\PostMapper;
use WPSupabaseSync\Sync\Queue;
use WPSupabaseSync\Sync\SyncEngine;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private ?Settings $settings             = null;
	private ?Logger $logger                 = null;
	private ?SupabaseClient $client         = null;
	private ?PostMapper $mapper             = null;
	private ?Queue $queue                   = null;
	private ?SyncEngine $engine             = null;
	private ?Backfill $backfill             = null;
	private ?DiagnosticsRunner $diagnostics = null;

	/**
	 * Register everything the plugin needs at runtime.
	 */
	public function boot(): void {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute is deliberate; see register_cron_schedule().
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( Installer::PROCESS_QUEUE_HOOK, array( $this, 'run_queue' ) );
	}

	public function on_plugins_loaded(): void {
		load_plugin_textdomain(
			'wp-supabase-sync',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);

		Installer::maybe_upgrade();

		// Hooks are registered unconditionally: content changes just as often
		// through WP-CLI, the REST API and cron as through the admin.
		( new Hooks( $this->settings(), $this->queue(), $this->engine() ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $this->settings(), $this->client(), $this->logger() ) )->register();
			( new Notices( $this->settings() ) )->register();
			( new DiagnosticsPage(
				$this->settings(),
				$this->diagnostics(),
				$this->queue(),
				$this->engine(),
				$this->backfill()
			) )->register();
			( new LogsPage( $this->logger(), $this->queue() ) )->register();
		}

		Commands::register();
	}

	/**
	 * Add the one-minute schedule the queue processor runs on.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		/*
		 * One minute is deliberate and cheap. WP-Cron only fires on a page view,
		 * so a longer interval does not reduce load — it just adds latency
		 * between publishing and the content appearing. A run with an empty queue
		 * costs a single indexed COUNT and no HTTP request at all.
		 */
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		$schedules[ Installer::CRON_SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (WP Supabase Sync)', 'wp-supabase-sync' ),
		);

		return $schedules;
	}

	/**
	 * Cron callback.
	 */
	public function run_queue(): void {
		$this->engine()->process_queue();
	}

	public function settings(): Settings {
		return $this->settings ??= new Settings();
	}

	public function logger(): Logger {
		return $this->logger ??= new Logger();
	}

	public function client(): SupabaseClient {
		return $this->client ??= new SupabaseClient( $this->settings(), $this->logger() );
	}

	public function mapper(): PostMapper {
		return $this->mapper ??= new PostMapper( $this->settings() );
	}

	public function queue(): Queue {
		return $this->queue ??= new Queue();
	}

	public function engine(): SyncEngine {
		return $this->engine ??= new SyncEngine(
			$this->settings(),
			$this->client(),
			$this->mapper(),
			$this->queue(),
			$this->logger()
		);
	}

	public function backfill(): Backfill {
		return $this->backfill ??= new Backfill( $this->settings(), $this->queue() );
	}

	public function diagnostics(): DiagnosticsRunner {
		return $this->diagnostics ??= new DiagnosticsRunner(
			$this->settings(),
			$this->client(),
			$this->mapper(),
			$this->queue(),
			$this->engine(),
			$this->logger()
		);
	}
}
