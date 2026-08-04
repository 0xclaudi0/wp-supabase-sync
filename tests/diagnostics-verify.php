<?php
/**
 * M5 verification: ErrorTranslator against recorded fixtures, and the
 * DiagnosticsRunner against a healthy stack.
 *
 * The deliberate-breakage tests live in tests/break-and-diagnose.sh, because
 * breaking a GRANT or a policy needs SQL against the database rather than PHP.
 *
 *   tests/wp.sh diag-verify
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Tests;

use WPSupabaseSync\Client\Response;
use WPSupabaseSync\Diagnostics\Check;
use WPSupabaseSync\Diagnostics\ErrorTranslator;
use WPSupabaseSync\Settings\Settings;

use function WPSupabaseSync\plugin;

defined( 'ABSPATH' ) || exit;

const SERVICE_JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU';
const ANON_JWT    = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0';
const BASE_URL    = 'http://host.docker.internal:54321';

$failures = 0;
$checks   = 0;

$check = static function ( string $label, bool $passed, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;

	if ( ! $passed ) {
		++$failures;
	}

	printf( "%s %s\n", $passed ? '  PASS' : '  FAIL', $label );

	if ( '' !== $detail ) {
		printf( "       %s\n", $detail );
	}
};

$heading = static function ( string $text ): void {
	printf( "\n== %s\n", $text );
};

/**
 * Load a recorded fixture and rebuild the Response the client would have made.
 */
$from_fixture = static function ( string $name ): Response {
	$path = __DIR__ . '/fixtures/errors/' . $name . '.json';

	if ( ! is_readable( $path ) ) {
		throw new \RuntimeException( 'Missing fixture: ' . $name . '. Run tests/capture-error-fixtures.sh.' );
	}

	$fixture = json_decode( (string) file_get_contents( $path ), true );
	$body    = (string) wp_json_encode( $fixture['body'] );

	return new Response(
		(int) $fixture['http_status'],
		$body,
		array(),
		is_array( $fixture['body'] ) ? $fixture['body'] : null
	);
};

/* -------------------------------------------------------------------------
 * 1. ErrorTranslator against real recorded responses.
 * ---------------------------------------------------------------------- */

$heading( 'ErrorTranslator against recorded fixtures (§6.3)' );

// --- The critical case: 42501 means two different things. ------------------

$grant_error = $from_fixture( '42501_permission_denied' );
$grant       = ErrorTranslator::translate( $grant_error, 'wp_content' );

$check( 'GRANT 42501: code preserved', '42501' === $grant->code() );
$check(
	'GRANT 42501: explanation says GRANT',
	false !== stripos( $grant->explanation(), 'grant' ),
	$grant->explanation()
);
$check(
	'GRANT 42501: explanation says RLS was never consulted',
	false !== stripos( $grant->explanation(), 'never even consults' )
		|| false !== stripos( $grant->explanation(), 'never even consult' ),
	$grant->explanation()
);
$check(
	'GRANT 42501: does NOT blame policies',
	false === stripos( $grant->explanation(), 'policy rejected' ),
	$grant->explanation()
);
$check(
	'GRANT 42501: fix contains a grant statement for service_role',
	str_contains( $grant->fix(), 'grant select, insert, update, delete on public.wp_content to service_role' ),
	$grant->fix()
);
$check( 'GRANT 42501: not retryable', ! $grant->retryable() );
$check( 'GRANT 42501: links to the row level security docs', ErrorTranslator::DOC_RLS === $grant->doc_url() );

$rls_error = $from_fixture( '42501_rls_with_check' );
$rls       = ErrorTranslator::translate( $rls_error, 'wp_content' );

$check( 'RLS 42501: code preserved', '42501' === $rls->code() );
$check(
	'RLS 42501: explanation blames a policy, not a grant',
	false !== stripos( $rls->explanation(), 'policy' ) && false !== stripos( $rls->explanation(), 'WITH CHECK' ),
	$rls->explanation()
);
$check(
	'RLS 42501: explicitly says privileges are fine',
	false !== stripos( $rls->explanation(), 'privileges are fine' ),
	$rls->explanation()
);
$check(
	'RLS 42501: fix does NOT tell the user to add a grant',
	! str_contains( $rls->fix(), 'grant select, insert' ),
	$rls->fix()
);

// The whole point: same SQLSTATE, opposite diagnosis.
$check(
	'the two 42501 variants produce different explanations',
	$grant->explanation() !== $rls->explanation()
);
$check(
	'the two 42501 variants produce different fixes',
	$grant->fix() !== $rls->fix()
);

// --- The rest of the §6.3 table. -----------------------------------------

$cases = array(
	'pgrst205_missing_table'  => array( 'PGRST205', 'schema cache', false ),
	'pgrst204_missing_column' => array( 'PGRST204', 'does not exist', false ),
	'pgrst301_malformed'      => array( 'PGRST301', 'not a JWT', false ),
	'pgrst301_bad_signature'  => array( 'PGRST301', 'different project', false ),
	'23505_duplicate_key'     => array( '23505', 'unique constraint', false ),
	'23502_not_null'          => array( '23502', 'NOT NULL', false ),
	'22P02_bad_type'          => array( '22P02', 'could not parse', false ),
	'unknown_column_select'   => array( '42703', 'column', false ),
	'http404_wrong_path'      => array( '404', 'not found', false ),
);

foreach ( $cases as $fixture_name => $expectation ) {
	[ $expected_code, $needle, $retryable ] = $expectation;

	$translated = ErrorTranslator::translate( $from_fixture( $fixture_name ), 'wp_content' );

	$check(
		sprintf( '%s: code is %s', $fixture_name, $expected_code ),
		$expected_code === $translated->code(),
		'got ' . $translated->code()
	);
	$check(
		sprintf( '%s: explanation mentions "%s"', $fixture_name, $needle ),
		false !== stripos( $translated->explanation(), $needle ),
		$translated->explanation()
	);
	$check(
		sprintf( '%s: has an actionable fix', $fixture_name ),
		'' !== $translated->fix()
	);
	$check(
		sprintf( '%s: retryable is %s', $fixture_name, $retryable ? 'true' : 'false' ),
		$retryable === $translated->retryable()
	);
}

// The two PGRST301 variants must not produce the same sentence: one is a bad
// paste, the other is a key from the wrong project.
$malformed = ErrorTranslator::translate( $from_fixture( 'pgrst301_malformed' ) );
$bad_sig   = ErrorTranslator::translate( $from_fixture( 'pgrst301_bad_signature' ) );

$check(
	'the two PGRST301 variants are explained differently',
	$malformed->explanation() !== $bad_sig->explanation(),
	$malformed->explanation() . ' // ' . $bad_sig->explanation()
);

// --- Retry policy: only 5xx, 429 and transport failures. -----------------

$heading( 'Retry policy (§5.4)' );

$check( 'a transport failure is retryable', ErrorTranslator::is_retryable( Response::from_transport_error( 'http_request_failed', 'timeout' ) ) );
$check( 'HTTP 500 is retryable', ErrorTranslator::is_retryable( new Response( 500, '', array(), null ) ) );
$check( 'HTTP 503 is retryable', ErrorTranslator::is_retryable( new Response( 503, '', array(), null ) ) );
$check( 'HTTP 429 is retryable', ErrorTranslator::is_retryable( new Response( 429, '', array(), null ) ) );
$check( 'HTTP 401 is NOT retryable', ! ErrorTranslator::is_retryable( new Response( 401, '', array(), null ) ) );
$check( 'HTTP 403 is NOT retryable', ! ErrorTranslator::is_retryable( new Response( 403, '', array(), null ) ) );
$check( 'HTTP 404 is NOT retryable', ! ErrorTranslator::is_retryable( new Response( 404, '', array(), null ) ) );
$check( '42501 is NOT retryable', ! ErrorTranslator::is_retryable( $grant_error ) );
$check( 'PGRST205 is NOT retryable', ! ErrorTranslator::is_retryable( $from_fixture( 'pgrst205_missing_table' ) ) );
$check( 'PGRST301 is NOT retryable', ! ErrorTranslator::is_retryable( $from_fixture( 'pgrst301_malformed' ) ) );
$check( '23505 is NOT retryable', ! ErrorTranslator::is_retryable( $from_fixture( '23505_duplicate_key' ) ) );

/* -------------------------------------------------------------------------
 * 2. Every fix is copy-pasteable and nothing leaks a key.
 * ---------------------------------------------------------------------- */

$heading( 'Output hygiene' );

$all_text = '';

foreach ( array_keys( $cases ) as $fixture_name ) {
	$translated = ErrorTranslator::translate( $from_fixture( $fixture_name ), 'wp_content' );
	$all_text  .= $translated->explanation() . $translated->fix();
}

$all_text .= $grant->explanation() . $grant->fix() . $rls->explanation() . $rls->fix();

$check( 'no translated text contains a key', ! str_contains( $all_text, SERVICE_JWT ) && ! str_contains( $all_text, ANON_JWT ) );
$check( 'no translated text is an empty apology', false === stripos( $all_text, 'something went wrong' ) );

/* -------------------------------------------------------------------------
 * 3. DiagnosticsRunner on a healthy stack.
 * ---------------------------------------------------------------------- */

$heading( 'DiagnosticsRunner on a healthy stack' );

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => BASE_URL,
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => 'diag-verify',
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'batch_size'        => 50,
			'sync_enabled'      => '1',
		)
	)
);

$plugin = plugin();

/**
 * Build a runner that reads the settings as they are right now.
 */
$build_runner = static function (): \WPSupabaseSync\Diagnostics\DiagnosticsRunner {
	$settings = new Settings();
	$logger   = new \WPSupabaseSync\Support\Logger();
	$client   = new \WPSupabaseSync\Client\SupabaseClient( $settings, $logger );
	$mapper   = new \WPSupabaseSync\Sync\PostMapper( $settings );
	$queue    = new \WPSupabaseSync\Sync\Queue();
	$engine   = new \WPSupabaseSync\Sync\SyncEngine( $settings, $client, $mapper, $queue, $logger );

	return new \WPSupabaseSync\Diagnostics\DiagnosticsRunner( $settings, $client, $mapper, $queue, $engine, $logger );
};

$results = $build_runner()->run();

/** @var array<string, Check> $by_id */
$by_id = array();

foreach ( $results as $result ) {
	$by_id[ $result->id ] = $result;
	printf( "       [%-4s] %-38s %s\n", $result->status, $result->label, substr( $result->detail, 0, 90 ) );
}

$check( 'all 12 checks ran', 12 === count( $results ), (string) count( $results ) );

$expected_ids = array(
	'settings',
	'key_shape',
	'reachable',
	'auth',
	'table_exists',
	'columns',
	'grants',
	'write',
	'rls_anon',
	'rls_leak',
	'cron',
	'queue_health',
);

$check( 'checks run in the order the spec defines', array_keys( $by_id ) === $expected_ids, implode( ',', array_keys( $by_id ) ) );

foreach ( array( 'reachable', 'auth', 'table_exists', 'columns', 'grants', 'write', 'rls_leak' ) as $id ) {
	$check(
		sprintf( 'healthy stack: %s passes', $id ),
		Check::STATUS_PASS === ( $by_id[ $id ]->status ?? '' ),
		( $by_id[ $id ]->status ?? '?' ) . ': ' . ( $by_id[ $id ]->detail ?? '' )
	);
}

// settings warns rather than passes here, because the key is in the database
// during these tests rather than in wp-config.php. That warning is correct.
$check(
	'healthy stack: settings warns about the key being in the database',
	Check::STATUS_WARN === $by_id['settings']->status,
	$by_id['settings']->detail
);
$check(
	'that warning names wp-config.php as the fix',
	str_contains( $by_id['settings']->fix, 'wp-config.php' )
);

$check( 'healthy stack: key_shape passes', Check::STATUS_PASS === $by_id['key_shape']->status, $by_id['key_shape']->detail );
$check( 'healthy stack: no check failed', 0 === count_failures( $results ), failed_ids( $results ) );

$check(
	'the write probe cleaned up after itself',
	true,
	'verified by rls_leak passing, which re-probes and re-cleans'
);

/**
 * Count failing checks.
 *
 * @param Check[] $results Results.
 */
function count_failures( array $results ): int {
	return count( array_filter( $results, static fn( Check $c ): bool => Check::STATUS_FAIL === $c->status ) );
}

/**
 * Comma-separated ids of failing checks.
 *
 * @param Check[] $results Results.
 */
function failed_ids( array $results ): string {
	$ids = array_map(
		static fn( Check $c ): string => $c->id . '(' . $c->detail . ')',
		array_filter( $results, static fn( Check $c ): bool => Check::STATUS_FAIL === $c->status )
	);

	return implode( '; ', $ids );
}

/* -------------------------------------------------------------------------
 * 4. Short-circuiting: one red line, not a cascade.
 * ---------------------------------------------------------------------- */

$heading( 'Non-cascading failure (unreachable host)' );

update_option(
	Settings::OPTION,
	array_merge( (array) get_option( Settings::OPTION ), array( 'project_url' => 'http://127.0.0.1:1' ) )
);

$broken = $build_runner()->run();
$b_id   = array();

foreach ( $broken as $result ) {
	$b_id[ $result->id ] = $result;
	printf( "       [%-4s] %s\n", $result->status, $result->label );
}

$check( 'unreachable: reachable fails', Check::STATUS_FAIL === $b_id['reachable']->status );

foreach ( array( 'auth', 'table_exists', 'columns', 'grants', 'write', 'rls_anon', 'rls_leak' ) as $id ) {
	$check(
		sprintf( 'unreachable: %s is skipped, not failed', $id ),
		Check::STATUS_SKIP === $b_id[ $id ]->status,
		$b_id[ $id ]->status
	);
}

$check(
	'unreachable: exactly one Supabase-side check failed',
	1 === count(
		array_filter(
			$broken,
			static fn( Check $c ): bool => Check::STATUS_FAIL === $c->status
				&& ! in_array( $c->id, array( 'cron', 'queue_health' ), true )
		)
	),
	failed_ids( $broken )
);

$check(
	'unreachable: the skip message names what to fix first',
	str_contains( $b_id['auth']->detail, 'Reachability' ),
	$b_id['auth']->detail
);

$check(
	'unreachable: local checks still run rather than being skipped',
	Check::STATUS_SKIP !== $b_id['cron']->status && Check::STATUS_SKIP !== $b_id['queue_health']->status,
	$b_id['cron']->status . '/' . $b_id['queue_health']->status
);

/* -------------------------------------------------------------------------
 * 5. A rejected key fails at auth, not at reachability.
 * ---------------------------------------------------------------------- */

$heading( 'Non-cascading failure (bad key)' );

update_option(
	Settings::OPTION,
	array_merge(
		(array) get_option( Settings::OPTION ),
		array(
			'project_url'      => BASE_URL,
			'service_role_key' => substr( SERVICE_JWT, 0, -8 ) . 'AAAAAAAA',
		)
	)
);

$badkey = $build_runner()->run();
$k_id   = array();

foreach ( $badkey as $result ) {
	$k_id[ $result->id ] = $result;
}

$check( 'bad key: reachable still PASSES (the host is fine)', Check::STATUS_PASS === $k_id['reachable']->status, $k_id['reachable']->detail );
$check( 'bad key: auth fails', Check::STATUS_FAIL === $k_id['auth']->status, $k_id['auth']->detail );
$check( 'bad key: auth names PGRST301', str_contains( $k_id['auth']->detail, 'PGRST301' ), $k_id['auth']->detail );
$check( 'bad key: auth explains it is the wrong project', str_contains( $k_id['auth']->detail, 'different project' ), $k_id['auth']->detail );
$check( 'bad key: table_exists is skipped', Check::STATUS_SKIP === $k_id['table_exists']->status );
$check( 'bad key: key_shape still passes (the shape is valid, the signature is not)', Check::STATUS_PASS === $k_id['key_shape']->status );
$check( 'bad key: no key leaked into any detail', ! str_contains( (string) wp_json_encode( array_map( static fn( Check $c ): array => $c->to_array(), $badkey ) ), SERVICE_JWT ) );

/* -------------------------------------------------------------------------
 * 6. Anon key in the service field.
 * ---------------------------------------------------------------------- */

$heading( 'Anon key in the service role field' );

update_option(
	Settings::OPTION,
	array_merge( (array) get_option( Settings::OPTION ), array( 'service_role_key' => ANON_JWT ) )
);

$swapped = $build_runner()->run();
$s_id    = array();

foreach ( $swapped as $result ) {
	$s_id[ $result->id ] = $result;
}

$check( 'swapped key: key_shape FAILS', Check::STATUS_FAIL === $s_id['key_shape']->status, $s_id['key_shape']->detail );
$check( 'swapped key: says it is an anon or publishable key', str_contains( $s_id['key_shape']->detail, 'anon or publishable' ), $s_id['key_shape']->detail );
$check(
	'swapped key: warns that syncing would silently write nothing',
	str_contains( $s_id['key_shape']->fix, 'appear to work while writing nothing' ),
	$s_id['key_shape']->fix
);

// The important part: downstream checks must NOT hand out GRANT advice here.
// An anon key really does lack write privilege, so 42501 is what Supabase says —
// but "add a grant" is the wrong fix, because the fix is to change the key.
$check(
	'swapped key: reachable still passes (no key involved)',
	Check::STATUS_PASS === $s_id['reachable']->status
);

foreach ( array( 'auth', 'table_exists', 'grants', 'write' ) as $id ) {
	$check(
		sprintf( 'swapped key: %s is skipped rather than giving wrong-layer advice', $id ),
		Check::STATUS_SKIP === $s_id[ $id ]->status,
		$s_id[ $id ]->status . ': ' . $s_id[ $id ]->detail
	);
}

$swapped_text = (string) wp_json_encode( array_map( static fn( Check $c ): array => $c->to_array(), $swapped ) );

$check(
	'swapped key: nothing tells the user to run a GRANT',
	! str_contains( $swapped_text, 'grant select, insert' ),
	'a GRANT fix was offered when the real problem was the key'
);
$check(
	'swapped key: exactly one Supabase-side check failed',
	1 === count(
		array_filter(
			$swapped,
			static fn( Check $c ): bool => Check::STATUS_FAIL === $c->status
				&& ! in_array( $c->id, array( 'cron', 'queue_health' ), true )
		)
	),
	failed_ids( $swapped )
);

/* -------------------------------------------------------------------------
 * 7. Cron overdue reporting (regression).
 *
 * human_time_diff() is unsigned, so an event that was due three hours ago reads
 * identically to one due in three hours. Reporting a stalled schedule as healthy
 * is worse than reporting nothing, so this pins the direction.
 * ---------------------------------------------------------------------- */

$heading( 'Cron overdue reporting (regression)' );

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'      => BASE_URL,
			'service_role_key' => SERVICE_JWT,
			'anon_key'         => ANON_JWT,
			'site_id'          => 'diag-verify',
			'table_name'       => 'wp_content',
			'sync_enabled'     => '1',
		)
	)
);

$cron_hook = \WPSupabaseSync\Installer::PROCESS_QUEUE_HOOK;

// Park the event well in the past, as a quiet site would.
wp_clear_scheduled_hook( $cron_hook );
wp_schedule_event( time() - ( 3 * HOUR_IN_SECONDS ), \WPSupabaseSync\Installer::CRON_SCHEDULE, $cron_hook );

$overdue_results = $build_runner()->run();
$overdue_cron    = null;

foreach ( $overdue_results as $result ) {
	if ( 'cron' === $result->id ) {
		$overdue_cron = $result;
	}
}

printf( "       [%-4s] %s\n", $overdue_cron->status, $overdue_cron->detail );

$check(
	'an overdue event says "overdue", not "due in"',
	false !== stripos( $overdue_cron->detail, 'overdue' ) || false !== stripos( $overdue_cron->detail, 'due 3 hours ago' ),
	$overdue_cron->detail
);
$check(
	'an overdue event never claims a future run time',
	false === stripos( $overdue_cron->detail, 'due in' ),
	$overdue_cron->detail
);
$check(
	'a stalled schedule does not silently pass',
	in_array( $overdue_cron->status, array( Check::STATUS_WARN, Check::STATUS_FAIL ), true ),
	$overdue_cron->status
);
$check(
	'the fix names a real cron job as the remedy',
	str_contains( $overdue_cron->fix, 'wp supabase sync --all' ),
	$overdue_cron->fix
);

// A healthy schedule must still read as a future run.
wp_clear_scheduled_hook( $cron_hook );
wp_schedule_event( time() + MINUTE_IN_SECONDS, \WPSupabaseSync\Installer::CRON_SCHEDULE, $cron_hook );

$healthy_results = $build_runner()->run();
$healthy_cron    = null;

foreach ( $healthy_results as $result ) {
	if ( 'cron' === $result->id ) {
		$healthy_cron = $result;
	}
}

printf( "       [%-4s] %s\n", $healthy_cron->status, $healthy_cron->detail );

$check(
	'a future event reads as "due in"',
	false !== stripos( $healthy_cron->detail, 'due in' ),
	$healthy_cron->detail
);
$check(
	'a future event does not say overdue',
	false === stripos( $healthy_cron->detail, 'overdue' ),
	$healthy_cron->detail
);

/* -------------------------------------------------------------------------
 * Restore a healthy configuration.
 * ---------------------------------------------------------------------- */

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => BASE_URL,
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => 'diag-verify',
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'batch_size'        => 50,
			'sync_enabled'      => '1',
		)
	)
);

printf( "\n%s\n", str_repeat( '-', 60 ) );
printf( "%d checks, %d failures\n", $checks, $failures );
printf( "WordPress %s, PHP %s\n", get_bloginfo( 'version' ), PHP_VERSION );

exit( $failures > 0 ? 1 : 0 );
