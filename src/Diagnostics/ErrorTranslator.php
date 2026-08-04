<?php
/**
 * Translates a Postgres/PostgREST error into something a human can act on.
 *
 * This is the most reusable part of the plugin and maps almost one-to-one onto
 * the companion Supabase Gotchas playbook.
 *
 * The important case is `42501`, which covers two completely different problems
 * that need opposite fixes, and which are only distinguishable by the message
 * text:
 *
 *   - "permission denied for table"            → the GRANT is missing. Row level
 *                                                security was never consulted.
 *   - "new row violates row-level security…"   → the GRANT is fine; a policy's
 *                                                WITH CHECK rejected the row.
 *
 * Telling a user to fix their policies when the real problem is a missing grant
 * sends them digging in exactly the wrong layer, which is the failure this class
 * exists to prevent.
 *
 * @package WPSupabaseSync
 */

declare( strict_types=1 );

namespace WPSupabaseSync\Diagnostics;

use WPSupabaseSync\Client\Response;

defined( 'ABSPATH' ) || exit;

final class ErrorTranslator {

	/**
	 * Where a failed check sends the reader for background.
	 *
	 * Supabase's own documentation rather than anything of ours, deliberately: a
	 * link the plugin controls is a link the plugin can break, and these pages
	 * explain the underlying Postgres behaviour better than a restatement would.
	 */
	public const DOCS_BASE = 'https://supabase.com/docs/guides/';

	public const DOC_RLS      = self::DOCS_BASE . 'database/postgres/row-level-security';
	public const DOC_AUTH_UID = self::DOCS_BASE . 'api/api-keys';
	public const DOC_STORAGE  = self::DOCS_BASE . 'storage/security/access-control';
	public const DOC_POOLING  = self::DOCS_BASE . 'database/connecting-to-postgres';

	/**
	 * Private: instances come from translate(), which does the classifying.
	 *
	 * @param string $code        PostgREST code or Postgres SQLSTATE.
	 * @param string $explanation What went wrong, in plain language.
	 * @param string $fix         What to do about it.
	 * @param string $doc_url     Relevant playbook entry, if any.
	 * @param bool   $retryable   Whether retrying could plausibly succeed.
	 */
	private function __construct(
		private readonly string $code,
		private readonly string $explanation,
		private readonly string $fix,
		private readonly string $doc_url = '',
		private readonly bool $retryable = false
	) {}

	public function code(): string {
		return $this->code;
	}

	public function explanation(): string {
		return $this->explanation;
	}

	public function fix(): string {
		return $this->fix;
	}

	public function doc_url(): string {
		return $this->doc_url;
	}

	public function retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Explanation and fix as one line, for a queue row's last_error.
	 */
	public function to_sentence(): string {
		$sentence = $this->explanation;

		if ( '' !== $this->fix ) {
			$sentence .= ' ' . $this->fix;
		}

		return $sentence;
	}

	/**
	 * Whether a failed response is worth retrying.
	 *
	 * Only 5xx, timeouts and 429. A 401 will still be a 401 in an hour, and
	 * burning eight retries on a configuration error just delays the moment the
	 * user finds out about it.
	 */
	public static function is_retryable( Response $response ): bool {
		return self::translate( $response )->retryable();
	}

	/**
	 * Translate a response.
	 *
	 * @param string $table Table name, so the suggested SQL is copy-pasteable.
	 */
	public static function translate( Response $response, string $table = 'wp_content' ): self {
		if ( $response->is_transport_error() ) {
			return new self(
				$response->transport_code,
				sprintf(
					/* translators: %s: transport error message. */
					__( 'WordPress could not reach Supabase at all: %s', 'wp-supabase-sync' ),
					$response->transport_error
				),
				__( 'Check the project URL, and whether this host allows outbound HTTPS. If WordPress runs in a container, the host machine is not "localhost" from inside it. WP_HTTP_BLOCK_EXTERNAL in wp-config.php will also do this.', 'wp-supabase-sync' ),
				self::DOC_POOLING,
				// A network blip is exactly the case retries exist for.
				true
			);
		}

		$code    = $response->error_code();
		$message = $response->error_message();
		$status  = $response->status;

		// The 42501 fork. Order matters: check the RLS phrasing first, because the
		// grant phrasing is the more generic of the two.
		if ( '42501' === $code ) {
			if ( false !== stripos( $message, 'row-level security' ) || false !== stripos( $message, 'row level security' ) ) {
				return new self(
					$code,
					__( 'A row level security policy rejected the row. The privileges are fine — a policy\'s WITH CHECK condition refused this particular data.', 'wp-supabase-sync' ),
					__( 'Either loosen the policy, or write with the service role key, which bypasses row level security. Confirm the key in settings is the service role key and not the anon key.', 'wp-supabase-sync' ),
					self::DOC_RLS
				);
			}

			return new self(
				$code,
				__( 'Permission denied on the table. This is a missing GRANT, not a policy problem: without table privileges Postgres never even consults row level security, so adding or fixing policies will change nothing.', 'wp-supabase-sync' ),
				self::grant_fix( $table ),
				self::DOC_RLS
			);
		}

		switch ( $code ) {
			case '42P01':
				return new self(
					$code,
					sprintf(
						/* translators: %s: table name. */
						__( 'The table public.%s does not exist.', 'wp-supabase-sync' ),
						$table
					),
					self::migration_fix(),
					self::DOC_RLS
				);

			case 'PGRST205':
				return new self(
					$code,
					sprintf(
						/* translators: %s: table name. */
						__( 'PostgREST cannot find public.%s in its schema cache. Either the migration has not been applied, or it has and PostgREST has not noticed yet.', 'wp-supabase-sync' ),
						$table
					),
					__( 'Apply the migration (wp supabase schema --print). If you just applied it, reload the schema cache — on hosted Supabase this happens automatically within a few seconds, or run: notify pgrst, \'reload schema\';', 'wp-supabase-sync' ),
					self::DOC_RLS
				);

			case 'PGRST204':
				return new self(
					$code,
					sprintf(
						/* translators: %s: the error message naming the column. */
						__( 'A column the plugin tried to write does not exist in the table: %s', 'wp-supabase-sync' ),
						$message
					),
					__( 'The table and the plugin\'s field list have drifted apart. Regenerate the migration with `wp supabase schema --print` and apply the difference.', 'wp-supabase-sync' )
				);

			case '42703':
				return new self(
					$code,
					sprintf(
						/* translators: %s: the error message naming the column. */
						__( 'Postgres rejected a column that does not exist: %s', 'wp-supabase-sync' ),
						$message
					),
					__( 'Regenerate the migration with `wp supabase schema --print` and apply the difference.', 'wp-supabase-sync' )
				);

			case 'PGRST301':
				return new self(
					$code,
					self::describe_jwt_failure( $message ),
					__( 'Copy the service role key again from Settings → API Keys in your Supabase dashboard, and confirm it belongs to the same project as the configured URL.', 'wp-supabase-sync' ),
					self::DOC_AUTH_UID
				);

			case 'PGRST302':
				return new self(
					$code,
					__( 'The JWT has expired.', 'wp-supabase-sync' ),
					__( 'Copy a current key from Settings → API Keys.', 'wp-supabase-sync' ),
					self::DOC_AUTH_UID
				);

			case '23505':
				return new self(
					$code,
					__( 'A unique constraint was violated, so the upsert was treated as a plain insert.', 'wp-supabase-sync' ),
					sprintf(
						/* translators: %s: table name. */
						__( 'The request needs on_conflict=site_id,wp_id together with the header Prefer: resolution=merge-duplicates. Also confirm public.%s still has its unique (site_id, wp_id) constraint — without it there is nothing for on_conflict to target.', 'wp-supabase-sync' ),
						$table
					)
				);

			case '23503':
				return new self(
					$code,
					__( 'A foreign key constraint was violated: a row this one points at does not exist.', 'wp-supabase-sync' ),
					__( 'Sync the parent record first. The default mirror table has no foreign keys, so this suggests the table was customised.', 'wp-supabase-sync' )
				);

			case '23502':
				return new self(
					$code,
					sprintf(
						/* translators: %s: the error message naming the column. */
						__( 'A NOT NULL column received no value: %s', 'wp-supabase-sync' ),
						$message
					),
					__( 'Either the table has columns the plugin does not know about, or a required field mapped to null. Regenerate the schema with `wp supabase schema --print`.', 'wp-supabase-sync' )
				);

			case '22P02':
				return new self(
					$code,
					sprintf(
						/* translators: %s: the error message. */
						__( 'Postgres could not parse a value the plugin sent: %s', 'wp-supabase-sync' ),
						$message
					),
					__( 'Usually a column type mismatch — for example a text column where the mapper writes jsonb. Compare the table against `wp supabase schema --print`.', 'wp-supabase-sync' )
				);

			case '53300':
				return new self(
					$code,
					__( 'Postgres refused the connection: too many clients already.', 'wp-supabase-sync' ),
					__( 'This plugin talks to PostgREST over HTTPS and does not hold Postgres connections itself, so the pressure is coming from elsewhere in your project. Consider the connection pooler for whatever does.', 'wp-supabase-sync' ),
					self::DOC_POOLING,
					true
				);
		}//end switch

		// No structured code. Fall back to the HTTP status, which is still enough
		// to distinguish "your key is wrong" from "their server is unwell".
		if ( 401 === $status || 403 === $status ) {
			return new self(
				(string) $status,
				'' !== $message
					? sprintf(
						/* translators: 1: HTTP status, 2: error message. */
						__( 'Supabase rejected the request with HTTP %1$d: %2$s', 'wp-supabase-sync' ),
						$status,
						$message
					)
					: sprintf(
						/* translators: %d: HTTP status. */
						__( 'Supabase rejected the request with HTTP %d and no explanation, which usually means the API gateway refused the key before PostgREST saw it.', 'wp-supabase-sync' ),
						$status
					),
				__( 'Check that the API key is correct and belongs to the project in the configured URL.', 'wp-supabase-sync' ),
				self::DOC_AUTH_UID
			);
		}

		if ( 404 === $status ) {
			return new self(
				'404',
				__( 'The endpoint was not found. The host answered, but not with PostgREST.', 'wp-supabase-sync' ),
				__( 'Check the project URL is the API URL, not the dashboard or Studio URL. The plugin appends /rest/v1 itself, so the setting should be the bare project URL.', 'wp-supabase-sync' )
			);
		}

		if ( 429 === $status ) {
			return new self(
				'429',
				__( 'Supabase is rate limiting these requests.', 'wp-supabase-sync' ),
				__( 'Lower the batch size, or sync less often. The queue will retry with a growing delay on its own.', 'wp-supabase-sync' ),
				'',
				true
			);
		}

		if ( $status >= 500 ) {
			return new self(
				(string) $status,
				sprintf(
					/* translators: %d: HTTP status. */
					__( 'Supabase returned a server error (HTTP %d).', 'wp-supabase-sync' ),
					$status
				),
				__( 'Nothing to change on this side. The queue will retry with a growing delay; check status.supabase.com if it persists.', 'wp-supabase-sync' ),
				'',
				true
			);
		}

		return new self(
			'' !== $code ? $code : (string) $status,
			sprintf(
				/* translators: %s: response summary. */
				__( 'Unexpected response from Supabase: %s', 'wp-supabase-sync' ),
				$response->summary()
			),
			__( 'Check the logs page for the full response, and the diagnostics page for which layer is at fault.', 'wp-supabase-sync' )
		);
	}

	/**
	 * PGRST301 covers several distinct key problems, and the message text is the
	 * only way to tell them apart. Naming the specific one saves the user from
	 * re-copying a key that was never the problem.
	 */
	private static function describe_jwt_failure( string $message ): string {
		if ( false !== stripos( $message, 'expired' ) ) {
			return __( 'The API key has expired.', 'wp-supabase-sync' );
		}

		if ( false !== stripos( $message, 'parts in JWT' ) ) {
			return sprintf(
				/* translators: %s: the raw error message. */
				__( 'The API key is not a JWT at all — Supabase could not even split it into its three parts (%s). This is usually a truncated or partial paste.', 'wp-supabase-sync' ),
				$message
			);
		}

		if ( false !== stripos( $message, 'no suitable key' ) || false !== stripos( $message, 'wrong key type' ) ) {
			return __( 'The API key is well-formed but was signed by a different project, so this project cannot verify it. The key and the project URL do not belong together.', 'wp-supabase-sync' );
		}

		return sprintf(
			/* translators: %s: the raw error message. */
			__( 'Supabase rejected the API key: %s', 'wp-supabase-sync' ),
			$message
		);
	}

	/**
	 * The GRANT block, ready to paste into the SQL editor.
	 */
	private static function grant_fix( string $table ): string {
		return sprintf(
			/* translators: %s: SQL statements. */
			__( "Run this in the Supabase SQL editor:\n\n%s", 'wp-supabase-sync' ),
			sprintf(
				"grant select on public.%1\$s to anon, authenticated;\ngrant select, insert, update, delete on public.%1\$s to service_role;",
				$table
			)
		);
	}

	private static function migration_fix(): string {
		return __( 'Apply the migration. Generate it with `wp supabase schema --print`, or copy it from the Diagnostics page, and run it in the Supabase SQL editor.', 'wp-supabase-sync' );
	}
}
