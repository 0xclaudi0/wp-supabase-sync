=== WP Supabase Sync ===
Contributors: claudiothedev
Tags: supabase, postgres, headless, rest-api, sync
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mirrors published WordPress content into a Supabase Postgres table so a separate frontend can read it directly, with diagnostics that name what is broken.

== Description ==

WP Supabase Sync copies your published posts and pages into a table in your own
Supabase project. A separate frontend — Next.js, a mobile app, anything that
speaks HTTP — can then read your content straight from Supabase instead of
calling the WordPress REST API.

WordPress stays the source of truth and the editing experience. Supabase becomes
the read layer.

This plugin is not affiliated with or endorsed by Supabase. It connects WordPress
to a Supabase project that you own and configure.

= Diagnostics that tell you which layer is broken =

Supabase has two independent permission layers — table privileges (GRANT) and row
level security (policies) — and they fail in ways that look the same from
outside. The most common wasted afternoon is debugging the wrong one.

SQLSTATE 42501 covers two completely different problems:

* "permission denied for table" means a GRANT is missing, and row level security
  was never consulted at all — so editing policies will change nothing.
* "new row violates row-level security policy" means the privileges are fine and
  a policy refused the row.

The built-in diagnostics tell these apart, quote what Postgres actually returned,
and print the exact SQL that fixes it. Twelve checks run in dependency order and
short-circuit, so a single root cause produces one clear failure rather than eight
cascading ones.

One of those checks is a security test: it writes an unpublished probe row and
confirms your anon key cannot read it. If your read policy is too permissive, you
find out from the diagnostics page rather than from someone else.

= Safe key handling =

The service role key bypasses row level security, so the plugin treats it like a
database password:

* It is read from wp-config.php first, the options table second.
* If it is in the database, you get a permanent notice explaining the risk.
* It is never printed in HTML, never sent to JavaScript, and never written to a
  log. Every message is redacted before storage, not before display.

= Reliability =

* Changes are queued and coalesced, so bulk-editing 100 posts produces 100 queue
  rows rather than thousands.
* Batched upserts through the Data API.
* Exponential backoff, capped at an hour, with dead-lettering after 8 attempts.
* Configuration errors fail immediately instead of burning retries.
* Paginated backfill that survives a timeout and resumes.

= External services =

This plugin sends data to one place: the Supabase project whose URL and API keys
you enter in its settings. It contacts no other service, phones home to nobody,
and makes no requests at all until you supply a project URL and enable syncing.

What is sent: the published content you choose to mirror — title, excerpt,
rendered content, author display name, featured image URL, permalink, dates, the
post meta keys you explicitly allowlist, and terms from public taxonomies.

Supabase is a third-party service governed by its own terms
(https://supabase.com/terms) and privacy policy (https://supabase.com/privacy).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-supabase-sync` and activate it.
2. Add your credentials to wp-config.php (recommended):

   `define( 'WPSB_PROJECT_URL', 'https://yourproject.supabase.co' );`
   `define( 'WPSB_SERVICE_ROLE_KEY', 'your-service-role-key' );`
   `define( 'WPSB_ANON_KEY', 'your-anon-key' );`

   Or enter them under Settings > Supabase Sync.

3. Create the table. Run `wp supabase schema --print` to generate the migration
   for your settings, or copy it from the bottom of Tools > Supabase Sync, then
   run it in the Supabase SQL editor.
4. Run the diagnostics: Tools > Supabase Sync, or `wp supabase doctor`.
5. Enable syncing under Settings > Supabase Sync, then backfill with
   `wp supabase sync --all`.

Syncing is off until you turn it on, so nothing is written before you have
confirmed the connection works.

== Frequently Asked Questions ==

= Why does it use the Data API instead of connecting to Postgres directly? =

Three reasons. PHP's request-per-process model on shared hosting exhausts a
Postgres connection pool quickly. The pdo_pgsql extension is often absent on
managed WordPress hosts. And HTTPS on port 443 works behind restrictive egress
firewalls. The plugin holds no database connections.

= Why is my content not syncing? =

Run `wp supabase doctor`, or open Tools > Supabase Sync. It will tell you which
layer is at fault rather than making you guess.

The most common answer is WP-Cron: it only fires when someone visits your site,
so on a low-traffic site the queue can sit for hours. The `cron` check reports
this. For reliable syncing use a real cron job running
`wp supabase sync --all` and set `DISABLE_WP_CRON` to true.

= I pasted my anon key into the service role field. What happens? =

The diagnostics catch it before any request is made. This mistake is worth
catching early because it does not fail loudly: row level security silently
filters every write, so syncing appears to work while writing nothing at all.

= What happens when I unpublish a post? =

The row is deleted from Supabase rather than kept with a draft status. The mirror
holds only what the public may read, so leaving unpublished content in the table
would be one bad policy away from leaking.

= Can several WordPress sites share one Supabase project? =

Yes. Each site needs a distinct Site ID, which is half of the (site_id, wp_id)
uniqueness constraint used for upserts.

= Does uninstalling delete my Supabase data? =

No. Uninstalling never touches your Supabase project. If you enable "delete data
on uninstall", it removes only this plugin's own WordPress tables, options and
post meta.

= Does it sync custom post types and custom fields? =

Public custom post types, yes — choose them in the settings. Post meta is an
allowlist you fill in yourself, deliberately empty by default, because post meta
often holds page-builder data and plugin state that should not be published.

== Screenshots ==

1. Settings, with the connection test and a warning that the service role key is
   stored in the database rather than wp-config.php.
2. Diagnostics: twelve checks in dependency order, each naming what was observed.
3. A failing check with copy-pasteable SQL, distinguishing a missing GRANT from a
   row level security policy.
4. Logs and queue state, with every credential redacted at write time.

== Changelog ==

= 0.1.0 =
* Initial release.
* Mirrors published posts and pages into a Supabase table with batched, coalesced
  upserts through the Data API.
* Twelve diagnostic checks with a Postgres/PostgREST error translator, including
  disambiguation of SQLSTATE 42501 between a missing GRANT and a row level
  security policy.
* Security check that confirms unpublished rows are not readable with the anon
  key.
* Queue with exponential backoff, dead-lettering, crash recovery and a resumable
  backfill.
* WP-CLI: doctor, status, sync, queue and schema commands.
* Service role key read from wp-config.php in preference to the database, and
  redacted everywhere.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
