# Context for anyone (or any AI) picking up this repo

## What this is

A WordPress plugin that mirrors published WordPress content into a Supabase
Postgres table, so a separate frontend can read it from Supabase instead of the
WP REST API. WordPress stays the source of truth; Supabase is the read layer.

It is a portfolio piece supporting an application for a **technical support role
at Supabase**, and a sibling to `../supabase-gotchas`. That shapes the
priorities: correct RLS + GRANT handling, safe `service_role` key handling, and
above all **diagnostics that name the actual broken layer in plain English**.

**Current state: M1–M6 complete.** All six milestones verified against a running
local Supabase stack on 2026-08-04, on both ends of the supported version range.

**Distribution: GitHub, not the WordPress.org directory.** Decided 2026-08-04.
That removes the trademark constraint that shaped several earlier notes — the
name "WP Supabase Sync" and artwork combining both marks are ordinary
attributed use for a self-hosted repo; it is the *directory's* rules that are
stricter. Two consequences:

- `load_plugin_textdomain()` in `Plugin.php` is **required**, not redundant.
  Directory-hosted plugins get translations from translate.wordpress.org
  automatically; this one does not.
- Users get no update notifications. `readme.txt` is kept anyway: it is accurate,
  costs nothing, and means a later submission is mostly a naming decision.

## Layout

```
wp-supabase-sync.php          Plugin header, namespaced constants, bootstrap
uninstall.php                 Guarded teardown; only deletes when opted in
README.md / readme.txt        GitHub-facing and WP.org-format docs
LICENSE                       GPL-2.0-or-later
phpcs.xml.dist                WPCS ruleset; every exclusion is justified inline
.wp-env.json                  Committed and correct, but see "wp-env" below
SPEC.md                       The build specification, corrected inline
CONTEXT.md                    This file
languages/                    264-string .pot
supabase/migrations/          0001_wp_content.sql, generated not hand-written
src/
├── Plugin.php                Lazy service wiring, cron registration
├── Installer.php             Table definitions, dbDelta, activation, teardown
├── Support/                  Autoloader, Redactor, Logger
├── Settings/                 Settings, KeyInspector, SettingsPage
├── Client/                   SupabaseClient, Response, ApiException
├── Sync/                     PostMapper, Schema, Queue, SyncEngine, SyncResult,
│                             Hooks, Backfill
├── Diagnostics/              Check, Context, DiagnosticsRunner, ErrorTranslator
│   └── Checks/               DiagnosticCheck interface + the 12 checks
├── Admin/                    DiagnosticsPage, LogsPage, Notices
└── Cli/Commands.php          doctor, status, sync, queue, schema
assets/admin.css, assets/admin.js
tests/
├── wp.sh                     Local WordPress harness (docker)
├── m1-verify.php             63 checks — scaffold, keys, redaction, settings
├── sync-verify.php           78 checks — mapper, lifecycle, queue, backfill
├── diagnostics-verify.php    105 checks — translator vs fixtures, runner
├── break-and-diagnose.sh     41 checks — break each layer, assert the diagnosis
├── capture-error-fixtures.sh Re-records the fixtures from a live stack
└── fixtures/errors/          11 real recorded PostgREST/Postgres error bodies
```

7,695 lines of PHP across 39 files in `src/`, 2,359 lines of test harness.

## How to run

```bash
# 1. Supabase, in the sibling repo
cd ../supabase-gotchas && supabase start

# 2. WordPress 6.4 / PHP 8.1 at localhost:8888 (admin/password)
cd ../wp-supabase-sync
tests/wp.sh up
tests/wp.sh wp plugin activate wp-supabase-sync

# 3. Apply the migration
tests/wp.sh wp supabase schema --print > supabase/migrations/0001_wp_content.sql
docker exec -i supabase_db_supabase-gotchas psql -U postgres -d postgres \
  < supabase/migrations/0001_wp_content.sql

# 4. Prove it works
tests/wp.sh verify-all              # 246 checks across three suites
tests/break-and-diagnose.sh         # 41 more, breaking one layer at a time
tests/wp.sh logs                    # PHP error log; should be empty
tests/wp.sh down                    # tear down
```

Settings: **Settings → Supabase Sync**. Diagnostics and logs: **Tools → Supabase
Sync** and **Tools → Supabase Sync Logs**.

### wp-env does not work on this machine

`SPEC.md` §9.1 asks for `wp-env`. It cannot run here: it shells out to
`docker compose`, and this machine's Docker (colima) has no compose plugin, so
`wp-env start` fails with `unknown shorthand flag: 'f' in -f`. Installing
`docker-compose` was not in scope, so `tests/wp.sh` does the same job with two
`docker run` calls.

`.wp-env.json` is still committed and correct, so `wp-env start` should work
unchanged on a machine that has compose.

`tests/wp.sh` pins **WordPress 6.4 and PHP 8.1** — the plugin's stated minimums.
Testing only on latest would not prove "requires at least 6.4".

### Running WPCS

`phpcs` is not installed here as a project dependency, and must not be: the
plugin ships without Composer. It was installed into `/tmp/wpcs-tools` for the
audit:

```bash
export COMPOSER_HOME=/tmp/wpcs-tools/.composer
cd /tmp/wpcs-tools && composer require --dev \
  squizlabs/php_codesniffer wp-coding-standards/wpcs \
  dealerdirect/phpcodesniffer-composer-installer
vendor/bin/phpcs --config-set installed_paths \
  /tmp/wpcs-tools/vendor/wp-coding-standards/wpcs,/tmp/wpcs-tools/vendor/phpcsstandards/phpcsutils,/tmp/wpcs-tools/vendor/phpcsstandards/phpcsextra

cd /path/to/wp-supabase-sync && /tmp/wpcs-tools/vendor/bin/phpcs
```

Herd's PHP has a broken `auto_prepend_file` pointing at a missing Valet loader,
which breaks Composer. Work around it with
`php -d auto_prepend_file='' $(which composer) …`.

---

## Status: what is verified

Everything below was **run** on **2026-08-04** against these versions. Nothing is
inferred.

| Component | Version |
|---|---|
| Supabase CLI | 2.111.0 |
| PostgREST (reported by `/rest/v1/`) | 14.15 |
| WordPress | 6.4.3 (minimum) and 7.0.2 (current) |
| PHP (container) | 8.1.34 (minimum) and 8.3.33 |
| Docker | 29.5.2 on colima 0.10.3 (macOS Virtualization.framework, virtiofs) |
| PHP (host, lint + phpcs only) | 8.4.13 (Laravel Herd) |
| PHPCS / WPCS | 3.13.4 / 3.4.1 |

### Totals

**293 automated checks, 0 failures — on both version pairs.** WPCS clean across
all 44 PHP files. The PHP error log is empty after a full run with `WP_DEBUG` and
`WP_DEBUG_LOG` on, including no PHP 8.3 deprecation notices.

| Suite | Checks | Covers |
|---|---|---|
| `tests/wp.sh verify` | 63 | Installer, KeyInspector, Redactor, Settings, ping, Test Connection |
| `tests/wp.sh sync-verify` | 78 | PostMapper, sync lifecycle, hooks, queue, backoff, backfill |
| `tests/wp.sh diag-verify` | 111 | ErrorTranslator vs 11 real fixtures, runner, short-circuiting, cron wording |
| `tests/break-and-diagnose.sh` | 41 | Breaking GRANT / policy / table / column / key end to end |

| WordPress | PHP | Result |
|---|---|---|
| 6.4.3 | 8.1.34 | 293 checks, 0 failures, empty error log |
| 7.0.2 | 8.3.33 | 293 checks, 0 failures, empty error log |

`tests/wp.sh` takes `STACK`, `WP_IMAGE`, `CLI_IMAGE` and `PORT` from the
environment so a second stack runs alongside the first:

```bash
STACK=latest WP_IMAGE=wordpress:7.0.2-php8.3-apache \
  CLI_IMAGE=wordpress:cli-php8.3 PORT=8899 tests/wp.sh up
```

Keep the same variables set for every subsequent call, including `verify`,
`down`, and `break-and-diagnose.sh`.

### Milestone "done when" criteria

**M1 — Scaffold.** ✅ Activates cleanly on WP 6.4.3 / PHP 8.1.34 with an empty
error log. Settings save through the real form POSTed to `options.php`
(302 → `settings-updated=true`) with every sanitizer firing: `site_id`
`"Saved From The Form!!"` → `saved-from-the-form`, `batch_size` 4000 → 500,
duplicate meta keys collapsed, a non-public post type dropped. Test Connection
returns a real pass through `admin-ajax.php` and correct, specific failures for
an unreachable port, a corrupted signature, and an anon key in the service field.

**M2 — Schema.** ✅ `wp supabase schema --print` generates SQL matching SPEC §4
exactly; it applied clean to the live stack. Proven over HTTP:
- `service_role` inserts successfully.
- `service_role` sees published *and* unpublished rows; `anon` sees only
  published ones.
- `anon` INSERT and DELETE are both refused with
  `42501 permission denied for table wp_content`.

**M3 — Single-object sync.** ✅ Publish → row appears with matching title, terms
and meta. Edit → row updates and `content_hash` changes, still exactly one row
(so the upsert is upserting). Unpublish → row **deleted** and the stored hash
cleared. Republish → row returns. Delete → row gone. A second sync with nothing
changed is skipped without a request; `--force` overrides it.

**M4 — Queue and reliability.** ✅
- 15 edits plus term and meta changes on one post coalesce to **one** queue row.
- 100 posts, whose creation fired 200+ hooks, coalesce to exactly **100** rows.
- 100 posts backfilled and synced in **4** `process_queue` calls at batch size 50.
- Attempts increment `1,2,3,4,5,6,7,8`, then the row is dead-lettered. Backoff is
  2/4/32 minutes at attempts 1/2/5, capped at 3600s, and does not overflow at
  attempt 999.
- A rejected key dead-letters on the **first** attempt instead of burning eight.
- `retry --all` resets dead rows to pending with attempts back to 0.
- A revision does not enqueue. Non-allowlisted meta does not enqueue. Writing the
  plugin's own `_wpsb_hash` meta does not enqueue (no sync loop).
- A draft that was never published never enters the queue at all.

**M5 — Diagnostics.** ✅ All 12 checks run in the order §6.2 defines. Breaking
each layer produces a specific, correct, non-cascading diagnosis:

| Broken | Result |
|---|---|
| `revoke all … from service_role` | `grants` **fail** naming **GRANT**, quoting 42501, with the exact `grant …` SQL. `write` skips. `doctor` exits 1. |
| Read policy dropped | `rls_anon` **fail** explaining anon gets `[]`, with `create policy`. `rls_leak` still passes — nothing is leaking. |
| Policy changed to `using (true)` | `rls_leak` **fail** flagged `SECURITY`. `rls_anon` still passes, because published reads genuinely work. |
| `excerpt` column dropped | `columns` **fail** naming `excerpt`. |
| Table renamed away | `table_exists` **fail** naming PGRST205, fix includes the full migration. `columns`/`grants`/`write`/`rls_anon`/`rls_leak` all skip. |
| Corrupted key signature | `auth` **fail** naming PGRST301 and "different project". `reachable` still **passes**. |
| Anon key in service field | `key_shape` **fail**; `auth`/`table_exists`/`grants`/`write` all **skip**. |
| Unreachable host | `reachable` **fail**; seven downstream checks skip; `cron` and `queue_health` still run. |

**M6 — Polish and docs.** ✅ `README.md`, `readme.txt`, `LICENSE`, a 264-string
`.pot` generated by `wp i18n make-pot`, `phpcs.xml.dist`, and a WPCS-clean
codebase. All 274 translation calls use the `wp-supabase-sync` text domain; none
is missing it.

### Security properties, verified not assumed

- The service role key **never appears** in the settings page, diagnostics page,
  logs page, or the AJAX response. Confirmed by grepping each rendered page for
  the key: absent from all three. Pages show `eyJhbGci…` instead.
- **No log row contains either key** after deliberately provoking failures.
- `admin-ajax.php` rejects a bad nonce (403) and an unauthenticated request (400).
- All six admin entry points (`render` and `handle_*` on three screens) check
  `manage_options`; all three POST handlers verify a nonce.
- No raw `echo $var` anywhere in `src/`.
- Every `$wpdb` statement goes through `prepare()`. Table names use the `%i`
  identifier placeholder (WordPress 6.2+), so even they are escaped by
  WordPress rather than interpolated and justified in a comment.
- No key is passed to `wp_localize_script`; JavaScript receives only an action
  name and a nonce.
- The HTTP client sets `redirection => 0`, so a redirect cannot forward the
  service role key to another host.
- With `WPSB_SERVICE_ROLE_KEY` in `wp-config.php`: the constant wins, the
  editable input is not rendered, and the "key is in the database" warning is
  correctly suppressed.
- `uninstall.php` with "delete data" off leaves everything intact; with it on it
  removes both tables, the options and the `_wpsb_hash` post meta. Supabase is
  never touched.

### Recorded error fixtures

`tests/fixtures/errors/` holds 11 **real** response bodies, captured by
provoking each error against the live stack with
`tests/capture-error-fixtures.sh`. The translator is tested against these, not
against invented strings.

| Fixture | HTTP | Code |
|---|---|---|
| `42501_permission_denied` | **401** | `42501` — `permission denied for table wp_content` |
| `42501_rls_with_check` | **403** | `42501` — `new row violates row-level security policy…` |
| `pgrst301_malformed` | 401 | `PGRST301` — `Expected 3 parts in JWT; got 1` |
| `pgrst301_bad_signature` | 401 | `PGRST301` — `No suitable key or wrong key type` |
| `pgrst205_missing_table` | 404 | `PGRST205` |
| `pgrst204_missing_column` | 400 | `PGRST204` |
| `23505_duplicate_key` | 409 | `23505` |
| `23502_not_null` | 400 | `23502` |
| `22P02_bad_type` | 400 | `22P02` |
| `unknown_column_select` | 400 | `42703` |
| `http404_wrong_path` | 404 | none — Kong's `no Route matched` |

Note the first two: the **same SQLSTATE** with **different HTTP statuses**, which
the spec did not predict. 401 for a missing grant, 403 for a policy rejection.

---

## Corrections to `SPEC.md`

Per §11 ("reality wins"), these were tested and the spec was wrong or
incomplete. `SPEC.md` is annotated inline; the reasoning is here.

### 1. `GET /rest/v1/` does not distinguish reachability from authentication

§6.2 lists check 3 `reachable` and check 4 `auth` as separable at that endpoint.
They are not: with **no API key at all** it returns **HTTP 200** and the full
OpenAPI document. `GET /rest/v1/posts?limit=1` with no key also returns rows — no
key behaves as `anon` locally. Hosted Supabase returns 401
`No API key found in request`, so this is a **local-vs-hosted difference** and a
good candidate gotcha for the sibling repo.

**How the plugin resolves it**, which is better than what the spec described:
`reachable` sends **no credentials** and passes on *any* HTTP answer, including a
401. `auth` sends the key and fails on 401/403. That cleanly separates "wrong
URL" from "wrong key" and behaves identically on local and hosted. Verified: a
corrupted key gives `reachable=pass, auth=fail`.

PostgREST validates a JWT whenever one is present, so since the client always
sends a key, a 401 is a real signal:

| Key sent | Result |
|---|---|
| Valid service JWT / anon JWT / `sb_secret_…` | 200 |
| Well-formed JWT, wrong signature | 401 `PGRST301` |
| `not-a-key` | 401 `PGRST301` |

### 2. Check 2 `key_shape` cannot assume the key is a JWT

§6.2 said "Key is a JWT; decode payload; `role` claim is `service_role`". Only
true of **legacy** keys. CLI 2.111.0 emits both generations from one
`supabase start`:

```
ANON_KEY:         eyJhbGciOiJIUzI1NiIs…      (JWT, role claim readable)
SERVICE_ROLE_KEY: eyJhbGciOiJIUzI1NiIs…      (JWT, role claim readable)
PUBLISHABLE_KEY:  sb_publishable_…            (opaque, no claims)
SECRET_KEY:       sb_secret_…                 (opaque, no claims)
```

The `sb_*` keys are not JWTs. A JWT-only check would report "malformed key" for a
perfectly good secret key on any modern project. `KeyInspector` reports a
`role_source` of `claim` or `prefix` and the UI wording follows. All four formats
are covered by tests.

### 3. `host.docker.internal` works under colima with no extra configuration

§9.1 suspected colima might need `--network-address`. It does not:
`host.docker.internal` resolves to `192.168.5.2` and works with no colima flags
and no `--add-host`. Measured from inside the WordPress container:

```
http://host.docker.internal:54321  -> HTTP 200
http://192.168.5.2:54321           -> HTTP 200
http://172.17.0.1:54321            -> HTTP 200
http://localhost:54321             -> http_request_failed
http://host-gateway:54321          -> http_request_failed
```

The spec's underlying warning is right: `localhost` does **not** work from inside
the container.

### 4. `service_role` needs no sequence grant — confirmed, but not for the stated reason

§4 asked to confirm `service_role` can insert without an explicit sequence grant
because `id` is `generated always as identity` rather than `serial`. **Confirmed**,
and worth recording precisely because the first measurement was misleading.

Postgres's default privileges had already granted `service_role` `w` (UPDATE) on
`wp_content_id_seq`, and `nextval()` accepts USAGE *or* UPDATE — so a naive test
proves nothing. Revoking every sequence privilege and comparing both column
styles side by side:

```
IDENTITY column insert with NO sequence privileges: SUCCEEDED
SERIAL   column insert with NO sequence privileges: FAILED
  -> permission denied for sequence wpsb_serial_probe_id_seq
```

So the distinction is real: an identity column's sequence is owned by the table
and its privileges are not checked; a `serial` column's is a separate object that
needs its own grant. **This is a strong candidate gotcha for the sibling repo** —
it is a one-word schema change that silently decides whether your inserts work.

### 5. Confirmed §6.3 rows, and one that cannot be triggered

Confirmed against the live stack and recorded as fixtures: `PGRST205`,
`PGRST204`, `PGRST301` (both variants), `42501` (both variants), `23505`,
`23502`, `22P02`, `42703`.

**`42P01` could not be provoked through PostgREST.** PostgREST checks its own
schema cache first and returns `PGRST205` before Postgres ever sees a query, so
`42P01 relation … does not exist` does not surface over the Data API. The
translator still handles it — it can arrive from an RPC call to a function that
references a dropped table — but it has **no recorded fixture**, and that is
flagged in `SPEC.md` rather than quietly claimed.

---

## Deliberate design decisions not spelled out in the spec

- **Menu placement.** §6 says "Tools → Supabase Sync → Diagnostics", which the
  WordPress admin menu cannot express (there is no third level). Settings are at
  **Settings → Supabase Sync**; diagnostics and logs are under **Tools**.
- **Constants.** Internal constants are namespaced (`WPSupabaseSync\VERSION`)
  exactly like `../ticket-marketplace-wp`; the `WPSB_` prefix is reserved for the
  globals a *user* defines in `wp-config.php`. Three are supported where the spec
  named one: `WPSB_SERVICE_ROLE_KEY`, `WPSB_PROJECT_URL`, `WPSB_ANON_KEY`.
- **Two extra queue columns.** §5.3's list has neither a dead-letter marker nor a
  claim token. `status varchar(16)` is the dead flag §5.4 permits.
  `claim_token varchar(32)` is a correctness fix: the spec's "UPDATE … SET
  claimed_at = NOW() … then select the claimed rows" is a race, because two
  overlapping cron runs can stamp the same second and each would then read the
  other's rows. A token unique to one `claim()` call makes the read exact.
- **`complete()` and `fail()` are scoped by claim token.** If a post is edited
  while its batch is in flight, `enqueue()` clears the token, and the completing
  worker then cannot delete the row. Without this, an edit during a slow sync
  would be silently lost.
- **Batched failures fail as a group.** PostgREST answers per request, not per
  row, so the engine cannot tell which row of a rejected batch was at fault. It
  retries the group, and a genuinely poisonous row eventually dead-letters with
  its batch — surfaced by `queue_health` rather than hidden.
- **A queued upsert for a no-longer-eligible post becomes a delete.** Otherwise
  unpublishing a post while its upsert was queued would leave the old row behind.
- **`auth` depends on `key_shape`.** Found by testing. With an anon key in the
  service field, every downstream check fails with `42501` and offers **GRANT**
  advice — accurate about what Postgres said, and completely wrong as a fix,
  because the request is not authenticating as `service_role` at all. Wrong-layer
  advice is the failure mode this plugin exists to prevent, so a definitely-wrong
  key stops the chain. A key that is merely *unreadable* returns `warn`, which
  does not block.
- **All datetimes in the queue are UTC.** MySQL's `NOW()` returns session-local
  time and would mis-compare against the UTC values WordPress writes, so every
  comparison uses `UTC_TIMESTAMP()`.
- **The plugin never runs DDL.** `schema --print` emits SQL for review. Giving a
  WordPress plugin authority to alter the schema with a key that bypasses RLS is
  more power than it needs.
- **`%i` for table names.** WordPress 6.2 added an identifier placeholder for
  `$wpdb->prepare()`. Using it removed 14 `phpcs:ignore` comments and is
  genuinely safer than interpolating a "trusted" name.

## Real bugs found by testing

1. **`esc_url_raw()` silently downgraded the scheme.** It defaults a scheme-less
   URL to `http://`, so pasting a bare `yourproject.supabase.co` produced an
   `http` project URL and the normaliser's `https` default never ran. On an
   https-only service that surfaces as a baffling connection failure. The scheme
   is now supplied *before* `esc_url_raw()`, restricted to `http`/`https`.
2. **`add_settings_error()` is not available outside admin context.** It lives in
   `wp-admin/includes/template.php`, so the sanitize callback fataled under
   WP-CLI. Now guarded with `function_exists()`.
3. **A rejected service key wrongly suppressed the anon check.** The two are
   separate credentials; "your service key is wrong but your anon key is fine" is
   a more useful answer than one failure and one skip. Only a *transport* failure
   skips it now.
4. **`wp eval-file` cannot run a file with `declare(strict_types=1)`** because it
   `eval()`s the contents, and the declaration must be the first statement of a
   *file*. The harness uses `wp eval "require …"` instead, which compiles the file
   as its own unit.

5. **`CronCheck` reported a stalled schedule as healthy.** `human_time_diff()`
   returns an *unsigned* difference, so an event that was due three hours ago
   rendered as "Next run due in 3 hours" — a stopped scheduler described as a
   working one, which is exactly the failure this plugin exists to prevent. Found
   by reading a screenshot, not by a test. It now checks the direction, says "was
   due N ago and has not run, so WP-Cron is not firing", and warns (or fails, when
   items are waiting) past a 15-minute grace. Pinned by four regression assertions
   in `diagnostics-verify.php`.

## Packaging

`.distignore` keeps development files out of the distributed ZIP. Without it every
install carried the test harness, `SPEC.md`, and the banner PNGs:

| | files | size |
|---|---|---|
| Ships to users | 47 | 310 KB |
| Excluded | 33 | 3,315 KB |

The plugin's own `assets/` is deliberately **not** excluded — `admin.css` and
`admin.js` are enqueued at runtime. `README.md` and `readme.txt` also ship;
WordPress.org parses the latter.

## Test-isolation caveats, learned the hard way

Two harness bugs surfaced only when the suites were run back to back, and both are
worth knowing before adding tests:

- **`tests/break-and-diagnose.sh` uses `set -e`, and `doctor` exits 1 by design.**
  A bare `wp supabase doctor` call therefore killed the script mid-run — after it
  had dropped the `excerpt` column and before it put it back, leaving the table
  broken for everything afterwards. Fixed two ways: exit codes now go through a
  `doctor_exit_code()` helper that cannot abort the script, and `restore_schema()`
  re-adds every mapper column with `add column if not exists` rather than only
  restoring privileges. Verified idempotent by running it twice in a row.

- **WP-Cron interferes between suites.** `wpsb_process_queue` runs every minute, so
  it can fire while `diagnostics-verify.php` has deliberately broken credentials
  configured — which dead-letters whatever was queued and makes the *next* suite's
  "healthy stack" assertions fail for unrelated reasons. `break-and-diagnose.sh`
  now clears the queue during setup.

  Worth noting that this was the fast-fail path working correctly: a rejected key
  dead-letters immediately instead of retrying. It was the test that was wrong, not
  the plugin. If you add suites, clear the queue in setup or set
  `DISABLE_WP_CRON`.

- **One unexplained flake, seen once.** `diag-verify` reported 1 failure out of 111
  in a run immediately following `break-and-diagnose.sh`, then passed four
  consecutive times including a deliberate attempt to reproduce the same ordering.
  The cause is **unconfirmed**. The likeliest candidate is PostgREST's schema-cache
  reload being asynchronous: `break-and-diagnose.sh` restores the schema, issues
  `notify pgrst, 'reload schema'`, and the next suite may query before the cache
  has caught up — which would surface as a transient `columns` or `table_exists`
  failure. The restore now waits 5 seconds instead of 2 as cheap insurance, but
  that is a mitigation for a guess, not a fix for a diagnosed bug.

  Treat "293 checks, 0 failures" as reliable rather than guaranteed. If you see a
  single unexplained failure, note which check it was before re-running — that is
  the information this entry is missing.

## WPCS: what is excluded and why

`phpcs.xml.dist` is clean on all 44 files. Every exclusion is documented inline;
the substantive ones:

- **`WordPress.Files.FileName`** — disabled outright. WPCS expects
  `class-my-thing.php` in a flat directory; SPEC §2 mandates PSR-4 paths under
  `src/`. Both cannot hold, and file naming is not a correctness property.
- **`Squiz.Commenting.FunctionComment.Missing` / `MissingParamTag`** — disabled.
  Constructor property promotion and typed signatures already carry what a
  `@param` tag would repeat, and PHPCS predates both.
- **`IncorrectTypeHint` / `ParamNameNoMatch`** — disabled. These read
  `array<string, mixed>` generics as malformed; the annotations are correct.
- **`/tests/*`** — excluded from output-escaping, direct-query, globals and
  documentation sniffs. Those harnesses print to a terminal, not a browser, and
  query the database on purpose.

Everything else was **fixed**, not silenced. Where an ignore remains in `src/` it
names the specific sniff and carries a reason on the same line.

## Next steps

The spec's milestones are done. Candidates beyond it:

1. **Version control and a release.** Git is deliberately not initialised here.
   Distribution is GitHub, so the remaining work is: init a repo, tag `0.1.0`, and
   attach a ZIP built to `.distignore` (47 files, 310 KB — not the 3.6 MB the
   working tree contains).

   If you ever reconsider the directory, the blocker is naming, not code: the slug
   is permanent once assigned and "Content Sync for Supabase" is the form reviewers
   accept. `.wordpress-org/README.md` has the fallback artwork brief. The listing
   assets themselves are done bar `icon-256x256.png`, which needs a re-export at
   256+ because the source art is 128×128 and upscaling flat graphics looks bad.
2. **Two gotcha entries for the sibling repo**, both discovered here:
   identity-vs-serial sequence grants, and no-API-key behaving as `anon` locally
   while hosted returns 401.
3. **PHPUnit.** §9.2 asks for unit tests; what exists are integration harnesses
   that need the live stack. `PostMapper`, `Redactor`, `ErrorTranslator` and the
   backoff maths are all pure enough to test without WordPress.
4. **`attachment_updated`** (§5.1) — re-syncing posts that use a changed image as
   their featured image. The spec allows deferring this, and it is deferred.
5. **Multisite.** Untested. `site_id` was designed for it, but nothing here has
   run on a network install.

## Conventions

- `declare( strict_types=1 );`, a file-level docblock with `@package
  WPSupabaseSync`, and `defined( 'ABSPATH' ) || exit;` on **every** PHP file.
- Namespaced classes under `src/`, one per file, PSR-4 paths, hand-rolled
  autoloader, **no Composer**, no build step, nothing from npm in the shipped
  plugin.
- WordPress Coding Standards, verified with phpcs rather than asserted.
- Comments explain **why**, not what. Match the voice of the sibling repo: plain,
  direct, no marketing.
- Never imply official affiliation with Supabase.
- **Verify, don't assume.** Anything in `SPEC.md` marked "verify" is a belief. If
  reality disagrees, fix the code, correct the spec, and record it above.
- **Do not initialize git or push anything.** The author handles version control.
- No milestone is done until its behaviour is verified against the running stack
  and written down here, with versions and actual output.

## Known gaps

- No PHPUnit suite; the harnesses need a live Supabase stack and a running
  WordPress.
- `icon-256x256.png` is missing: the source icon is 128×128 and upscaling flat
  art looks bad. Everything else in `.wordpress-org/` is present and
  correctly sized. Note that directory assets must **not** go in the plugin's own
  `assets/`, which ships to users and holds `admin.css` / `admin.js`.
- `42P01` has no recorded fixture — see correction 5 above.
- Multisite, and `attachment_updated`, are both untested and unimplemented.
- The `ErrorTranslator::DOCS_BASE` URL points at a GitHub repo path for the
  sibling playbook that is not published yet, so the diagnostics' "background
  reading" links will 404 until it is.
