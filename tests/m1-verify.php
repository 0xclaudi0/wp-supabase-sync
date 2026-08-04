<?php
/**
 * M1 verification script.
 *
 * Not a unit test suite — that arrives with the milestones that need one. This
 * exercises the M1 code paths against the real local Supabase stack and prints
 * what actually happened, so the claims in CONTEXT.md are reproducible rather
 * than asserted.
 *
 * Run inside the wp-env container:
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/wp-supabase-sync/tests/m1-verify.php
 *
 * Exits non-zero if any check fails.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Tests;

use WPSupabaseSync\Client\SupabaseClient;
use WPSupabaseSync\Installer;
use WPSupabaseSync\Settings\KeyInspector;
use WPSupabaseSync\Settings\Settings;
use WPSupabaseSync\Settings\SettingsPage;
use WPSupabaseSync\Support\Logger;
use WPSupabaseSync\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Keys used by the suite.
 *
 * The two JWTs are the well-known Supabase CLI development keys: identical on
 * every machine, published in Supabase's own local-development docs, and
 * worthless against anything but a local stack. The suite genuinely authenticates
 * with them, so they have to be real.
 *
 * The `sb_` pair is deliberately fake. Those are only fed to KeyInspector to check
 * that a role is inferred from the prefix, and to Redactor to check that such a
 * key cannot survive a round trip — neither needs a working key, and committing a
 * real `sb_secret_` value would trip secret scanning for no benefit.
 */
const SERVICE_JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU';
const ANON_JWT    = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0';
const SECRET_KEY  = 'sb_secret_EXAMPLENOTAREALSECRETKEY';
const PUBLIC_KEY  = 'sb_publishable_EXAMPLENOTAREALKEY';

$failures = 0;
$checks   = 0;

/**
 * Report one assertion.
 */
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

/* -------------------------------------------------------------------------
 * 0. Where is the Supabase host from inside this container?
 * ---------------------------------------------------------------------- */

$heading( 'Host networking discovery' );

$candidates = array(
	'http://host.docker.internal:54321',
	'http://host-gateway:54321',
	'http://192.168.5.2:54321',
	'http://172.17.0.1:54321',
	'http://localhost:54321',
);

$base_url = '';

foreach ( $candidates as $candidate ) {
	$probe = wp_remote_get( $candidate . '/rest/v1/', array( 'timeout' => 5 ) );

	if ( is_wp_error( $probe ) ) {
		printf( "  ....  %s -> %s\n", $candidate, $probe->get_error_code() );

		continue;
	}

	$status = (int) wp_remote_retrieve_response_code( $probe );

	printf( "  ....  %s -> HTTP %d\n", $candidate, $status );

	if ( 200 === $status && '' === $base_url ) {
		$base_url = $candidate;
	}
}

$check( 'a reachable Supabase base URL was found from inside the container', '' !== $base_url, 'using: ' . $base_url );

if ( '' === $base_url ) {
	echo "\nCannot continue without a reachable stack. Is `supabase start` running in ../supabase-gotchas?\n";
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * 1. Installer created both tables.
 * ---------------------------------------------------------------------- */

$heading( 'Installer' );

global $wpdb;

foreach ( array( Installer::queue_table(), Installer::log_table() ) as $table ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	$check( sprintf( 'table %s exists', $table ), $table === $found );
}

$queue_columns = $wpdb->get_col( 'DESC `' . Installer::queue_table() . '`', 0 );
$expected      = array( 'id', 'object_type', 'object_id', 'action', 'status', 'attempts', 'last_error', 'available_at', 'claimed_at', 'created_at' );

$check(
	'queue table has the expected columns',
	empty( array_diff( $expected, (array) $queue_columns ) ),
	'found: ' . implode( ', ', (array) $queue_columns )
);

$queue_keys = $wpdb->get_results( 'SHOW INDEX FROM `' . Installer::queue_table() . '`', ARRAY_A );
$unique     = false;

foreach ( $queue_keys as $key ) {
	if ( 'object' === $key['Key_name'] && '0' === (string) $key['Non_unique'] ) {
		$unique = true;
	}
}

$check( 'queue has a UNIQUE key on (object_type, object_id) for coalescing', $unique );

$check(
	'schema version option was written',
	(int) get_option( Installer::VERSION_OPTION ) === Installer::SCHEMA_VERSION
);

/* -------------------------------------------------------------------------
 * 2. KeyInspector against all four real key formats the CLI emits.
 * ---------------------------------------------------------------------- */

$heading( 'KeyInspector' );

$service_info = KeyInspector::inspect( SERVICE_JWT );
$check(
	'legacy service JWT: role claim read as service_role',
	'service_role' === $service_info['role'] && KeyInspector::ROLE_FROM_CLAIM === $service_info['role_source'],
	KeyInspector::describe( $service_info )
);
$check( 'legacy service JWT: recognised as service key', KeyInspector::is_service_key( $service_info ) );
$check( 'legacy service JWT: not expired', false === $service_info['is_expired'] );
$check( 'legacy service JWT: issuer read', 'supabase-demo' === $service_info['issuer'], 'iss=' . $service_info['issuer'] );

$anon_info = KeyInspector::inspect( ANON_JWT );
$check(
	'legacy anon JWT: role claim read as anon',
	'anon' === $anon_info['role'],
	KeyInspector::describe( $anon_info )
);
$check( 'legacy anon JWT: flagged as a public key', KeyInspector::is_public_key( $anon_info ) );

$secret_info = KeyInspector::inspect( SECRET_KEY );
$check(
	'sb_secret_ key: inferred as service_role from prefix',
	'service_role' === $secret_info['role'] && KeyInspector::ROLE_FROM_PREFIX === $secret_info['role_source'],
	KeyInspector::describe( $secret_info )
);
$check( 'sb_secret_ key: recognised as service key', KeyInspector::is_service_key( $secret_info ) );

$publishable_info = KeyInspector::inspect( PUBLIC_KEY );
$check(
	'sb_publishable_ key: flagged as a public key',
	KeyInspector::is_public_key( $publishable_info ),
	KeyInspector::describe( $publishable_info )
);

$garbage_info = KeyInspector::inspect( 'definitely-not-a-key' );
$check(
	'garbage key: shape unknown, no exception',
	KeyInspector::SHAPE_UNKNOWN === $garbage_info['shape'],
	$garbage_info['note']
);

$empty_info = KeyInspector::inspect( '' );
$check( 'empty key: shape empty', KeyInspector::SHAPE_EMPTY === $empty_info['shape'] );

// A payload with exp in the past. Signature is irrelevant: we read the payload.
$expired_payload = rtrim(
	strtr(
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- constructing a JWT payload to test expiry handling, not obfuscating code.
		base64_encode(
			(string) wp_json_encode(
				array(
					'iss'  => 'supabase',
					'role' => 'service_role',
					'exp'  => 1000000000,
				)
			)
		),
		'+/',
		'-_'
	),
	'='
);
$expired_jwt  = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.' . $expired_payload . '.notarealsignature';
$expired_info = KeyInspector::inspect( $expired_jwt );
$check( 'expired JWT: is_expired is true', true === $expired_info['is_expired'], $expired_info['note'] );

/* -------------------------------------------------------------------------
 * 3. Redactor never lets a key through.
 * ---------------------------------------------------------------------- */

$heading( 'Redactor' );

Redactor::protect( SERVICE_JWT );

$leaky = sprintf(
	'apikey=%s and secret=%s and publishable=%s and dsn=postgresql://postgres:hunter2@db.example.co:5432/postgres',
	SERVICE_JWT,
	SECRET_KEY,
	PUBLIC_KEY
);

$redacted = Redactor::redact( $leaky );

$check( 'legacy JWT does not survive redaction', ! str_contains( $redacted, SERVICE_JWT ) );
$check( 'sb_secret_ key does not survive redaction', ! str_contains( $redacted, SECRET_KEY ) );
$check( 'sb_publishable_ key does not survive redaction', ! str_contains( $redacted, PUBLIC_KEY ) );
$check( 'database password does not survive redaction', ! str_contains( $redacted, 'hunter2' ) );
printf( "       redacted form: %s\n", $redacted );

$deep = Redactor::redact_deep( array( 'outer' => array( 'key' => SERVICE_JWT ) ) );
$check( 'nested arrays are redacted', ! str_contains( (string) wp_json_encode( $deep ), SERVICE_JWT ) );

$fingerprint = Redactor::fingerprint( SERVICE_JWT );
$check(
	'fingerprint is 8 characters plus an ellipsis',
	'eyJhbGci…' === $fingerprint,
	'fingerprint: ' . $fingerprint
);
$check( 'short strings are masked entirely rather than fingerprinted', Redactor::PLACEHOLDER === Redactor::fingerprint( 'short' ) );

$headers = Redactor::redact_headers(
	array(
		'apikey'        => SERVICE_JWT,
		'Authorization' => 'Bearer ' . SERVICE_JWT,
		'Content-Type'  => 'application/json',
	)
);
$check(
	'credential headers are stripped for logging',
	Redactor::PLACEHOLDER === $headers['apikey']
		&& Redactor::PLACEHOLDER === $headers['Authorization']
		&& 'application/json' === $headers['Content-Type']
);

/* -------------------------------------------------------------------------
 * 4. Settings: sanitization and constant precedence.
 * ---------------------------------------------------------------------- */

$heading( 'Settings' );

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => $base_url . '/',
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => 'Local WP Test',
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'synced_meta_keys'  => "subtitle\n\n  featured_blurb  \nsubtitle",
			'batch_size'        => 9999,
			'sync_enabled'      => '1',
		)
	)
);

$settings = new Settings();

$check( 'project URL normalised (trailing slash stripped)', $base_url === $settings->project_url(), $settings->project_url() );
$check( 'rest_base appends /rest/v1/', $base_url . '/rest/v1/' === $settings->rest_base(), $settings->rest_base() );
$check( 'site_id sanitised to a URL-safe slug', 'local-wp-test' === $settings->site_id(), $settings->site_id() );
$check( 'batch size clamped to the maximum', Settings::MAX_BATCH_SIZE === $settings->batch_size(), (string) $settings->batch_size() );
$check( 'meta keys trimmed, de-duplicated, blanks dropped', array( 'subtitle', 'featured_blurb' ) === $settings->synced_meta_keys(), implode( ',', $settings->synced_meta_keys() ) );
$check( 'is_configured() is true', $settings->is_configured() );
$check( 'service key source reported as option', Settings::SOURCE_OPTION === $settings->service_key_source(), $settings->service_key_source() );

// A bare host should gain a scheme rather than being rejected. project_url()
// does the normalising, so check it through a Settings instance.
update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'      => 'Example.Supabase.co',
			'service_role_key' => SERVICE_JWT,
		)
	)
);
$bare_settings = new Settings();
$check(
	'a bare host gains an https scheme',
	'https://example.supabase.co' === $bare_settings->project_url(),
	$bare_settings->project_url()
);

$check( 'a table name with a quote is rejected', '' === Settings::sanitize_table_name( 'wp_content"; drop table x --' ) );
$check( 'a valid table name is accepted', 'wp_content' === Settings::sanitize_table_name( 'WP_Content' ) );

// Blank credential submission must mean "unchanged", not "delete".
$kept = Settings::sanitize(
	array(
		'project_url'      => $base_url,
		'service_role_key' => '',
	)
);
$check( 'blank key submission preserves the stored key', SERVICE_JWT === $kept['service_role_key'] );

$cleared = Settings::sanitize(
	array(
		'project_url'            => $base_url,
		'service_role_key'       => '',
		'clear_service_role_key' => '1',
	)
);
$check( 'the clear checkbox does remove the stored key', '' === $cleared['service_role_key'] );

// Restore the working configuration after the sanitize experiments above.
update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => $base_url,
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => 'local-wp-test',
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'batch_size'        => 50,
			'sync_enabled'      => '1',
		)
	)
);

/* -------------------------------------------------------------------------
 * 5. SupabaseClient::ping() — the happy path and every failure mode.
 * ---------------------------------------------------------------------- */

$heading( 'SupabaseClient::ping()' );

$logger   = new Logger();
$settings = new Settings();
$client   = new SupabaseClient( $settings, $logger );

$pong = $client->ping( SupabaseClient::ROLE_SERVICE );
$check( 'ping with the service key succeeds', $pong->is_success(), $pong->summary() );
$check( 'ping returned HTTP 200', 200 === $pong->status, (string) $pong->status );

$anon_pong = $client->ping( SupabaseClient::ROLE_ANON );
$check( 'ping with the anon key succeeds', $anon_pong->is_success(), $anon_pong->summary() );

// Mangled signature: PostgREST verifies a JWT whenever one is present.
$settings_bad_key = new Settings();
update_option(
	Settings::OPTION,
	array_merge(
		(array) get_option( Settings::OPTION ),
		array( 'service_role_key' => substr( SERVICE_JWT, 0, -8 ) . 'AAAAAAAA' )
	)
);
$settings_bad_key->refresh();
$bad_key_response = ( new SupabaseClient( $settings_bad_key, $logger ) )->ping();

$check( 'a key with a bad signature is rejected', ! $bad_key_response->is_success(), $bad_key_response->summary() );
$check( 'rejection is HTTP 401', 401 === $bad_key_response->status, (string) $bad_key_response->status );
$check( 'rejection carries code PGRST301', 'PGRST301' === $bad_key_response->error_code(), $bad_key_response->error_code() );

// Malformed key: a different PGRST301 message, worth recording separately.
update_option(
	Settings::OPTION,
	array_merge( (array) get_option( Settings::OPTION ), array( 'service_role_key' => 'not-a-jwt-at-all' ) )
);
$settings_malformed = new Settings();
$malformed_response = ( new SupabaseClient( $settings_malformed, $logger ) )->ping();
$check( 'a malformed key is rejected with PGRST301', 401 === $malformed_response->status && 'PGRST301' === $malformed_response->error_code(), $malformed_response->summary() );

// Unreachable host: a transport error, not an HTTP status.
update_option(
	Settings::OPTION,
	array_merge(
		(array) get_option( Settings::OPTION ),
		array(
			'project_url'      => 'http://127.0.0.1:1',
			'service_role_key' => SERVICE_JWT,
		)
	)
);
$settings_dead = new Settings();
$dead_response = ( new SupabaseClient( $settings_dead, $logger ) )->ping();
$check( 'an unreachable host is a transport error, not an HTTP status', $dead_response->is_transport_error(), $dead_response->summary() );
$check( 'transport error reports status 0', 0 === $dead_response->status );

// No key configured at all: ApiException before any request is attempted.
update_option(
	Settings::OPTION,
	array_merge( (array) get_option( Settings::OPTION ), array( 'service_role_key' => '' ) )
);
$settings_nokey = new Settings();
$threw          = false;

try {
	( new SupabaseClient( $settings_nokey, $logger ) )->ping();
} catch ( \WPSupabaseSync\Client\ApiException $e ) {
	$threw = 'missing_key' === $e->reason();
}

$check( 'a missing key throws ApiException before any request is made', $threw );

/* -------------------------------------------------------------------------
 * 6. The Test Connection button's own code path.
 * ---------------------------------------------------------------------- */

$heading( 'Test Connection results' );

/**
 * Run the private result builder the AJAX handler uses.
 *
 * @return array<int, array<string, string>>
 */
$run_test = static function ( array $overrides ) use ( $base_url, $logger ): array {
	update_option(
		Settings::OPTION,
		Settings::sanitize(
			array_merge(
				array(
					'project_url'      => $base_url,
					'service_role_key' => SERVICE_JWT,
					'anon_key'         => ANON_JWT,
					'table_name'       => 'wp_content',
					'site_id'          => 'local-wp-test',
				),
				$overrides
			)
		)
	);

	$settings = new Settings();
	$page     = new SettingsPage( $settings, new SupabaseClient( $settings, $logger ), $logger );

	$method = new \ReflectionMethod( $page, 'run_connection_test' );
	$method->setAccessible( true );

	return $method->invoke( $page );
};

/**
 * Print a result set and return it keyed by check id.
 *
 * @return array<string, string>
 */
$show = static function ( array $results ): array {
	$statuses = array();

	foreach ( $results as $result ) {
		$statuses[ $result['id'] ] = $result['status'];
		printf( "       [%-4s] %s\n              %s\n", $result['status'], $result['label'], $result['detail'] );
	}

	return $statuses;
};

echo "\n  -- correctly configured --\n";
$good = $show( $run_test( array() ) );
$check( 'configuration passes', 'pass' === ( $good['settings'] ?? '' ) );
$check( 'key shape passes', 'pass' === ( $good['key_shape'] ?? '' ) );
$check( 'service reachability passes', 'pass' === ( $good['service_reachable'] ?? '' ) );
$check( 'anon key passes', 'pass' === ( $good['anon_reachable'] ?? '' ) );

echo "\n  -- anon key pasted into the service field --\n";
$swapped = $show( $run_test( array( 'service_role_key' => ANON_JWT ) ) );
$check( 'the anon-key-in-service-field mistake is caught before any request', 'fail' === ( $swapped['key_shape'] ?? '' ) );

echo "\n  -- unreachable URL --\n";
$unreachable = $show( $run_test( array( 'project_url' => 'http://127.0.0.1:1' ) ) );
$check( 'unreachable URL fails reachability', 'fail' === ( $unreachable['service_reachable'] ?? '' ) );
$check( 'anon check is skipped rather than reported as a second failure', 'skip' === ( $unreachable['anon_reachable'] ?? '' ) );

// A rejected service key must NOT suppress the anon check: the two are separate
// credentials, and "your service key is wrong but your anon key is fine" is a
// more useful answer than one failure and one skip.
echo "\n  -- valid URL, corrupted service key, valid anon key --\n";
$bad_service = $show( $run_test( array( 'service_role_key' => substr( SERVICE_JWT, 0, -8 ) . 'AAAAAAAA' ) ) );
$check( 'a rejected service key fails its own check', 'fail' === ( $bad_service['service_reachable'] ?? '' ) );
$check( 'a rejected service key does not suppress the anon check', 'pass' === ( $bad_service['anon_reachable'] ?? '' ) );

echo "\n  -- no URL configured --\n";
$unconfigured = $show( $run_test( array( 'project_url' => '' ) ) );
$check( 'missing URL fails at the configuration check', 'fail' === ( $unconfigured['settings'] ?? '' ) );
$check( 'nothing downstream is reported when unconfigured', 1 === count( $unconfigured ) );

/* -------------------------------------------------------------------------
 * 7. The log table has rows, and none of them contain a key.
 * ---------------------------------------------------------------------- */

$heading( 'Logging' );

$rows = $logger->recent( 200 );
$check( 'failures were written to the log table', count( $rows ) > 0, count( $rows ) . ' rows' );

$dump = (string) wp_json_encode( $rows );
$check( 'no log row contains the service key', ! str_contains( $dump, SERVICE_JWT ) );
$check( 'no log row contains the anon key', ! str_contains( $dump, ANON_JWT ) );

$has_client_error = false;

foreach ( $rows as $row ) {
	if ( 'client' === $row['channel'] && 'error' === $row['level'] ) {
		$has_client_error = true;
		printf( "       sample: %s\n", $row['message'] );
		break;
	}
}

$check( 'a client error was logged with a readable message', $has_client_error );

/* -------------------------------------------------------------------------
 * Restore a working configuration and report.
 * ---------------------------------------------------------------------- */

update_option(
	Settings::OPTION,
	Settings::sanitize(
		array(
			'project_url'       => $base_url,
			'service_role_key'  => SERVICE_JWT,
			'anon_key'          => ANON_JWT,
			'site_id'           => 'local-wp-test',
			'table_name'        => 'wp_content',
			'synced_post_types' => array( 'post', 'page' ),
			'batch_size'        => 50,
		)
	)
);
delete_option( Settings::OPTION . '_probe' );

printf( "\n%s\n", str_repeat( '-', 60 ) );
printf( "%d checks, %d failures\n", $checks, $failures );
printf( "WordPress %s, PHP %s\n", get_bloginfo( 'version' ), PHP_VERSION );
printf( "Supabase base URL from container: %s\n", $base_url );

exit( $failures > 0 ? 1 : 0 );
