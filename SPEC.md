# WP Supabase Sync — Build Specification

**Status:** M1–M6 all built and verified 2026-08-04. 287 automated checks pass
against a running local Supabase stack; WPCS clean. Corrections found by testing
are marked inline; see `CONTEXT.md` for the full record of what was run.
**Audience:** the developer (or AI agent) implementing this from scratch.

---

## 1. What this is

A WordPress plugin that mirrors WordPress content into a Supabase Postgres table,
so a separate frontend (Next.js, mobile app, whatever) can query WP content
straight from Supabase instead of hitting the WP REST API.

WordPress stays the source of truth and the editing experience. Supabase becomes
the fast, queryable read layer with RLS on it.

### Why this project exists

It's a portfolio piece supporting an application for a **technical support role
at Supabase**. That shapes every design decision. The plugin must demonstrate:

- Correct RLS + GRANT design (the two-layer permission model)
- Safe `service_role` key handling — server-side only, never exposed
- Idempotent batch upserts through PostgREST
- Real reliability engineering: queueing, retries, backoff, dead-letter
- **Above all: outstanding diagnostics.** See §6. This is the differentiator.

A plugin that syncs data is ordinary. A plugin that, when it breaks, tells the
user *exactly* what's wrong in plain English and how to fix it — that's the
artifact that says "I think like a support engineer." Build the sync engine well,
but make the diagnostics panel the thing people remember.

### Companion project

This is a sibling to `../supabase-gotchas` (the Supabase Gotchas playbook). The
error translator in §6.3 should link to the relevant gotcha entries. Read that
repo's `CONTEXT.md` first — it contains **verified** findings about RLS, GRANTs,
storage, and pooling that this plugin depends on. In particular: the baseline
schema there was initially broken because migrations run as `postgres`, whose
default privileges do **not** grant DML to `anon`/`authenticated`/`service_role`
in `public`. Explicit `GRANT`s are mandatory. Do not repeat that mistake here.

---

## 2. Naming and conventions

| Thing | Value |
|---|---|
| Plugin name | WP Supabase Sync |
| Slug / directory | `wp-supabase-sync` |
| Main file | `wp-supabase-sync.php` |
| Namespace | `WPSupabaseSync` |
| Text domain | `wp-supabase-sync` |
| DB table prefix | `{$wpdb->prefix}wpsb_` |
| Option prefix | `wpsb_` |
| Hook prefix | `wpsb_` |
| Constant prefix | `WPSB_` |

**Match the code conventions in `../ticket-marketplace-wp` exactly** — that's the
same author's existing plugin and the style should be consistent:

- `declare( strict_types=1 );` at the top of every PHP file
- Namespaced classes under `src/`, one class per file, PSR-4-style paths
- A hand-rolled autoloader in `src/Support/Autoloader.php` — **no Composer**
- WordPress Coding Standards: tabs for indent, Yoda conditions, spaces inside
  parens `function_name( $arg )`
- File-level docblock with `@package WPSupabaseSync` on every file
- `defined( 'ABSPATH' ) || exit;` guard on every file

**Requirements:** PHP 8.1+, WordPress 6.4+.

> **Naming caution:** do not imply official affiliation with Supabase anywhere —
> not in the plugin name, description, readme, or admin UI. Phrase it as "connects
> WordPress to Supabase," never "official" or "by Supabase." Same framing
> discipline as the gotchas README. If this ever goes to the WP.org directory,
> the trademark rules there are stricter still.

---

## 3. Architecture

### 3.1 Transport decision

Talk to Supabase over **PostgREST via the WP HTTP API** (`wp_remote_post`,
`wp_remote_request`). Do **not** open a raw Postgres connection from PHP.

Reasons, and state these in the README because they're good support answers:

- Shared WP hosting can't reliably hold Postgres connections, and PHP's
  request-per-process model would exhaust the connection pool fast — exactly the
  failure documented in gotcha 04.
- PostgREST over HTTPS needs no extra PHP extension (`pdo_pgsql` is often absent).
- It keeps the plugin firewall-friendly (443 only).

### 3.2 Data flow

```
WP post saved/deleted
      ↓  (hooks, §5.1)
  queue table  ({$wpdb->prefix}wpsb_queue)   ← coalesced, idempotent
      ↓  (WP-Cron every minute, or "Sync now", or WP-CLI)
  Sync engine: claim batch → map → POST/DELETE to PostgREST
      ↓
  Supabase public.wp_content
      ↓
  success → delete queue row      failure → backoff, retry, eventually dead-letter
```

### 3.3 File tree

```
wp-supabase-sync/
├── wp-supabase-sync.php          Plugin header, constants, bootstrap
├── uninstall.php                 Drop tables + options (only if "remove data" is on)
├── README.md                     GitHub-facing
├── readme.txt                    WP.org format
├── CONTEXT.md                    Handoff doc — create in M1, keep current
├── SPEC.md                       This file
├── src/
│   ├── Plugin.php                Service wiring, hook registration
│   ├── Installer.php             dbDelta for queue + log tables, activation/deactivation
│   ├── Support/
│   │   ├── Autoloader.php
│   │   ├── Logger.php            Writes to log table; redacts keys
│   │   └── Redactor.php          Key/secret redaction used everywhere
│   ├── Settings/
│   │   ├── SettingsPage.php      Admin UI
│   │   ├── Settings.php          Typed accessor over options + constants
│   │   └── KeyInspector.php      Decodes JWT payload to check the role claim
│   ├── Client/
│   │   ├── SupabaseClient.php    HTTP client: upsert, delete, select, ping
│   │   ├── Response.php          Normalized response value object
│   │   └── ApiException.php
│   ├── Sync/
│   │   ├── PostMapper.php        WP_Post -> row array
│   │   ├── Schema.php            [added] Generates the migration from the mapper,
│   │   │                         so the table and the mapper cannot drift
│   │   ├── Queue.php             Enqueue, claim batch, complete, fail, dead-letter
│   │   ├── SyncEngine.php        Batch processing loop
│   │   ├── SyncResult.php        [added] Outcome of syncing one object
│   │   ├── Hooks.php             WP hook handlers -> Queue
│   │   └── Backfill.php          Paginated full resync
│   ├── Diagnostics/
│   │   ├── DiagnosticsRunner.php Ordered checks, short-circuits
│   │   ├── Check.php             Value object: id, label, status, detail, fix, doc link
│   │   ├── Context.php           [added] What a check needs + prior results
│   │   ├── Checks/               One class per check (§6.2), plus a
│   │   │                         DiagnosticCheck interface they implement
│   │   └── ErrorTranslator.php   PG/PostgREST error -> human explanation
│   ├── Admin/
│   │   ├── DiagnosticsPage.php
│   │   ├── LogsPage.php
│   │   └── Notices.php           Admin warnings (e.g. key in DB not wp-config)
│   └── Cli/
│       └── Commands.php          WP-CLI, registered only if defined( 'WP_CLI' )
├── assets/
│   ├── admin.css
│   └── admin.js
├── supabase/
│   └── migrations/
│       └── 0001_wp_content.sql   Generated by `wp supabase schema --print`
└── tests/
    ├── wp.sh                     Local WordPress harness (docker)
    ├── m1-verify.php             63 checks
    ├── sync-verify.php           78 checks
    ├── diagnostics-verify.php    105 checks
    ├── break-and-diagnose.sh     41 checks, breaks one layer at a time
    ├── capture-error-fixtures.sh Re-records fixtures from a live stack
    └── fixtures/errors/          11 real recorded error bodies
```

> **As built.** Three files were added beyond this tree — `Sync/Schema.php`,
> `Sync/SyncResult.php`, `Diagnostics/Context.php` — each noted above. There is no
> `tests/bootstrap.php`: the harnesses run through `wp eval` against a live stack
> rather than PHPUnit. A PHPUnit suite is listed as a gap in `CONTEXT.md`.
> `phpcs.xml.dist`, `LICENSE` and `languages/` also exist and are not in this tree.

---

## 4. Supabase schema

Ship this as `supabase/migrations/0001_wp_content.sql`. It must be testable
against the local stack in `../supabase-gotchas` (`supabase start`).

```sql
-- WP Supabase Sync — content mirror table.
create table if not exists public.wp_content (
  id                 bigint generated always as identity primary key,
  site_id            text        not null,
  wp_id              bigint      not null,
  post_type          text        not null,
  status             text        not null,
  slug               text        not null,
  title              text,
  excerpt            text,
  content_html       text,
  author_name        text,
  featured_image_url text,
  url                text,
  published_at       timestamptz,
  modified_at        timestamptz,
  meta               jsonb       not null default '{}'::jsonb,
  terms              jsonb       not null default '[]'::jsonb,
  content_hash       text        not null,
  synced_at          timestamptz not null default now(),
  unique ( site_id, wp_id )
);

create index if not exists wp_content_lookup_idx
  on public.wp_content ( site_id, post_type, status );
create index if not exists wp_content_slug_idx
  on public.wp_content ( site_id, slug );
create index if not exists wp_content_terms_idx
  on public.wp_content using gin ( terms );

alter table public.wp_content enable row level security;

-- Layer 1: table privileges. RLS is never consulted without these.
revoke all on public.wp_content from anon, authenticated;
grant select on public.wp_content to anon, authenticated;
grant select, insert, update, delete on public.wp_content to service_role;

-- Layer 2: row policies. service_role bypasses RLS, so it needs no policy —
-- but it DID need the grant above.
create policy "Published content is publicly readable"
  on public.wp_content for select
  to anon, authenticated
  using ( status = 'publish' );
```

**Design notes to preserve:**

- `site_id` is a plugin setting (default: sanitized `home_url()` host). It lets
  several WP sites — or a multisite network — share one Supabase project without
  key collisions.
- `unique (site_id, wp_id)` is the upsert conflict target.
- `content_hash` lets the engine skip no-op syncs.
- Only `status = 'publish'` rows are publicly readable. Non-published posts are
  **deleted** from Supabase rather than synced with a private status — see §5.2.

**Verified 2026-08-04 — and the belief was right, though the first measurement
was misleading.** Postgres's default privileges had already given `service_role`
`w` (UPDATE) on `wp_content_id_seq`, and `nextval()` accepts USAGE *or* UPDATE, so
a naive test proves nothing. Revoking every sequence privilege and comparing both
column styles side by side settles it:

```
IDENTITY column insert with NO sequence privileges: SUCCEEDED
SERIAL   column insert with NO sequence privileges: FAILED
  -> permission denied for sequence wpsb_serial_probe_id_seq
```

An identity column's sequence is owned by the table and its privileges are not
checked; a `serial` column's is a separate object needing its own grant. Keep
`generated always as identity`. This is a strong candidate gotcha for the sibling
repo — a one-word schema change that silently decides whether inserts work.

---

## 5. Sync engine

### 5.1 Hooks

Register in `Sync/Hooks.php`. Every handler must bail early for irrelevant events.

| Hook | Action |
|---|---|
| `transition_post_status` | Primary trigger. Decides upsert vs delete from old/new status. |
| `before_delete_post` | Enqueue delete (must read post data before it's gone). |
| `trashed_post` | Enqueue delete. |
| `untrashed_post` | Enqueue upsert if it lands on a synced status. |
| `set_object_terms` | Enqueue upsert (taxonomy changed). |
| `added_post_meta` / `updated_post_meta` / `deleted_post_meta` | Enqueue upsert **only** if the meta key is in the allowlist. |
| `attachment_updated` | Enqueue upsert of any post using it as featured image (best-effort; may defer to M6). |

**Mandatory guards** in every post handler — these are the classic WP bugs:

```php
if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
	return;
}
if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
	return;
}
if ( 'auto-draft' === $post->post_status ) {
	return;
}
if ( ! in_array( $post->post_type, $settings->synced_post_types(), true ) ) {
	return;
}
```

Prefer `transition_post_status` over `save_post` as the main trigger — it gives
you both old and new status, which is what drives the upsert/delete decision.

### 5.2 Status semantics

This is the subtle part; get it right and document it.

| Transition | Queue action |
|---|---|
| any → `publish` | upsert |
| `publish` → `draft` / `pending` / `private` / `trash` | **delete** |
| `publish` → `publish` (edit) | upsert |
| any → `auto-draft` | ignore |
| deleted | delete |

Rationale: the mirror holds only what the public frontend may read. Leaving an
unpublished row behind with `status = 'draft'` means the row still exists in the
table and is one bad policy away from leaking. Deleting is the safer default.

Make this configurable later if wanted, but ship the safe default.

### 5.3 Queue table

`{$wpdb->prefix}wpsb_queue`, created via `dbDelta` in `Installer.php`:

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint unsigned auto_increment` | PK |
| `object_type` | `varchar(32)` | `post` for now |
| `object_id` | `bigint unsigned` | |
| `action` | `varchar(16)` | `upsert` \| `delete` |
| `attempts` | `smallint unsigned` | default 0 |
| `last_error` | `text` | redacted |
| `available_at` | `datetime` | backoff gate |
| `claimed_at` | `datetime` NULL | crash recovery |
| `created_at` | `datetime` | |
| `status` | `varchar(16)` | added in M1: `pending` \| `dead`, the dead-letter flag §5.4 allows for. Claim index is `(status, claimed_at, available_at)`. Created now so M4 needs no schema bump. |

Unique key on `(object_type, object_id)` — a newer event **replaces** the pending
one (update `action`, reset `attempts`, clear `last_error`). Use
`INSERT ... ON DUPLICATE KEY UPDATE`. This coalescing is what keeps a bulk edit
of 500 posts from creating 5,000 queue rows.

**Claiming a batch:** `UPDATE ... SET claimed_at = NOW() WHERE claimed_at IS NULL
AND available_at <= NOW() LIMIT n`, then select the claimed rows. Rows with
`claimed_at` older than 10 minutes are considered abandoned and get reclaimed.

> **Corrected in M4: that is a race.** Selecting on `claimed_at` alone is unsafe —
> two overlapping cron runs can stamp the same second, and each would then read
> the other's rows. The implementation adds `claim_token varchar(32)`, unique to
> one `claim()` call, and reads back on the token.
>
> The token earns its keep twice: `complete()` and `fail()` are also scoped by it,
> so a post edited *while its batch is in flight* is not lost. `enqueue()` clears
> the token, and the completing worker then cannot delete the re-queued row.
>
> Also note: use `UTC_TIMESTAMP()`, not `NOW()`. `NOW()` returns session-local
> time and mis-compares against the UTC datetimes WordPress writes.

### 5.4 Processing

- Batch size: setting, default **50**, max 500.
- Group by action: one batched upsert request, one batched delete request.
- **Upsert:** `POST /rest/v1/wp_content?on_conflict=site_id,wp_id`
  with header `Prefer: resolution=merge-duplicates,return=minimal`
  and a JSON **array** body.
- **Delete:** `DELETE /rest/v1/wp_content?site_id=eq.{site}&wp_id=in.({ids})`
- Headers on every request:
  `apikey: <service key>`, `Authorization: Bearer <service key>`,
  `Content-Type: application/json`, `Accept: application/json`.
- Timeout 15s, and set a descriptive `user-agent`.

**Retry policy:** on failure, `attempts++` and
`available_at = NOW() + LEAST( POW( 2, attempts ) * 60, 3600 )` seconds.
After **8** attempts, mark dead-letter (set `available_at` far future or move to a
`dead` flag) and raise an admin notice. Never retry a 4xx that can't succeed —
`401`/`403`/`404`/`42501`/`42P01` are configuration errors, so fail fast, surface
them in diagnostics, and don't burn retries. Only retry 5xx, timeouts, and 429.

**Content hash:** `sha1` of the mapped row array with `synced_at` removed, stored
in post meta `_wpsb_hash`. If unchanged, skip the request entirely and complete
the queue row. `--force` bypasses this.

### 5.5 Cron and backfill

- Custom schedule `wpsb_minute` (60s), event `wpsb_process_queue`.
- Register/unregister in activation/deactivation. Clean up in `uninstall.php`.
- Note in the README that WP-Cron only fires on traffic; recommend a real cron
  hitting `wp-cron.php` (or WP-CLI) for reliable sync on low-traffic sites. This
  is itself a great support answer.
- **Backfill** (`Sync/Backfill.php`): paginate with `WP_Query` using
  `posts_per_page` + `paged`, `no_found_rows => true`, `fields => 'ids'`, and
  `wp_suspend_cache_addition` for memory. Enqueue rather than sync inline. Store
  progress in an option so it survives a timeout and resumes.

---

## 6. Diagnostics — the centerpiece

Two surfaces: an admin page (**Tools → Supabase Sync**) and `wp supabase doctor`.
Both run the same `DiagnosticsRunner`.

> **Menu placement, settled in M1.** The WordPress admin menu has no third level,
> so "Tools → Supabase Sync → Diagnostics" is not buildable as written. Settings
> live at **Settings → Supabase Sync** (`options-general.php?page=wpsb-settings`),
> where a WordPress user looks for plugin settings; Diagnostics and Logs go under
> **Tools → Supabase Sync** in M5.

### 6.1 Output contract

Every check returns a `Check` value object:

```php
final class Check {
	public string $id;        // 'grants', 'rls_read', ...
	public string $label;     // 'Table privileges (GRANT)'
	public string $status;    // 'pass' | 'warn' | 'fail' | 'skip'
	public string $detail;    // What was observed, in plain language
	public string $fix;       // What to do about it — copy-pasteable SQL where useful
	public string $doc_url;   // Link into ../supabase-gotchas where relevant
}
```

Rules:

- Checks run **in dependency order** and short-circuit: if reachability fails,
  skip everything downstream and mark it `skip`, not `fail`. Cascading red is
  noise; one accurate red is a diagnosis.
- `detail` must state what was actually observed ("`/rest/v1/wp_content` returned
  HTTP 401 with code `PGRST301`"), never a generic "something went wrong."
- `fix` must be actionable, and where the fix is SQL, print the exact SQL.
- Never print the key. Show `eyJhbGciOi…` (first 8 chars) via `Redactor`.

### 6.2 The checks, in order

| # | id | What it verifies | Failure means |
|---|---|---|---|
| 1 | `settings` | URL + key present, URL well-formed | Not configured |
| 2 | `key_shape` | Key's role is `service_role` — from the JWT `role` claim on legacy keys, or from the `sb_secret_` / `sb_publishable_` prefix on current ones (**corrected in M1, see below**) | Anon key pasted into the service field — warn loudly |
| 3 | `reachable` | `GET /rest/v1/` responds at all | Wrong URL, DNS, firewall, or WP can't make outbound requests |
| 4 | `auth` | The key we sent was not rejected (not 401/403) | Bad or revoked key |

> **Corrected in M1 against the running stack.** Two beliefs above were wrong:
>
> - **Check 2 cannot assume the key is a JWT.** Supabase CLI 2.111.0 emits both
>   generations of key from one `supabase start`: legacy JWTs (`eyJ…`, with a
>   readable `role` claim) *and* current opaque keys (`sb_secret_…`,
>   `sb_publishable_…`, with no claims at all). A JWT-only check reports
>   "malformed key" for a perfectly valid secret key on any modern project.
>   `KeyInspector` reports whether the role came from a `claim` or a `prefix`, and
>   the UI wording follows.
> - **Checks 3 and 4 are not separable at `/rest/v1/`.** With *no* API key that
>   endpoint returns **HTTP 200** and the full OpenAPI document — the local
>   gateway does not require a key there, so a 200 does not prove authentication.
>   What holds is that PostgREST validates a JWT *whenever one is present*, so
>   since the plugin always sends a key, a 401 `PGRST301` is a real signal.
>   A 200 proves reachability **and** that the key parses and verifies; it does
>   **not** prove the key is a *service* key (a valid anon key also returns 200),
>   and it proves nothing about table privileges. Keep checks 7 and 8 for that.
>   Note this differs on hosted Supabase, which returns 401
>   `No API key found in request` when the key is absent — a good candidate
>   gotcha entry for the sibling repo.
| 5 | `table_exists` | `GET /rest/v1/wp_content?limit=0` | Migration not applied, or schema cache stale |
| 6 | `columns` | Compare returned columns vs `PostMapper` fields | Schema drift — mapper and table disagree |
| 7 | `grants` | Service key can `select` | `42501` → GRANT missing (see gotchas CONTEXT.md) |
| 8 | `write` | Insert a probe row (`wp_id = 0`, `post_type = '_wpsb_probe'`), then delete it | Write path broken |
| 9 | `rls_anon` | With the **anon** key, read published rows | `[]` → the public read policy is missing |
| 10 | `rls_leak` | With the anon key, confirm non-published rows are **not** visible | Policy too permissive — a real security finding |
| 11 | `cron` | `wpsb_process_queue` is scheduled and has run recently | Cron disabled or stalled |
| 12 | `queue_health` | Depth, oldest item age, dead-letter count | Backlog or stuck items |

Check 10 is worth the extra effort — a diagnostic that catches a *security*
misconfiguration, not just a broken one, is exactly the instinct the role wants.

The probe row in check 8 needs care: it must not be publicly readable. Give it
`status = '_probe'` so the `status = 'publish'` policy excludes it, and always
delete it in a `finally`.

### 6.3 Error translator

`Diagnostics/ErrorTranslator.php` maps a Postgres/PostgREST error to a plain
explanation. This is the most reusable piece of the plugin, and it maps almost
one-to-one onto the gotchas playbook.

**Critical nuance:** `42501` covers two completely different problems, and you
must disambiguate on the message text:

| Code | Message contains | Real cause | Fix |
|---|---|---|---|
| `42501` | `permission denied for table` | **GRANT** missing — RLS was never even consulted | `grant select, insert, update, delete on <t> to service_role;` |
| `42501` | `new row violates row-level security policy` | **RLS** `with check` rejected the row | Fix the policy, or write with the service key |
| `42P01` | `relation ... does not exist` | Table missing | Apply the migration |
| `PGRST205` ✅ | `Could not find the table` | Table missing, or PostgREST schema cache stale | Apply migration; reload schema cache |
| `PGRST204` | `Could not find the ... column` | Schema drift | Update the migration or the mapper |
| `PGRST301` ✅ | JWT-related | Key invalid/expired | Re-copy the service key |
| `23505` | `duplicate key value` | Unique violation | Ensure `on_conflict=site_id,wp_id` + `merge-duplicates` |
| `23503` | `violates foreign key` | FK missing | Sync the parent first |
| `42703` | `column ... does not exist` | Mapper drift | Regenerate schema |
| HTTP 401/403 | `Invalid API key` | Wrong key entirely | Check project + key |

> **Verify every row of this table against the local stack before shipping.**
> The codes above are believed correct but were written from knowledge, not from a
> live run. Trigger each error deliberately against `supabase start` and paste the
> real response body into a fixture in `tests/fixtures/errors/`. Then the
> translator is tested against reality, and you can say so honestly in the README.
>
> **Rows marked ✅ were confirmed against the live stack during M1:**
>
> - `PGRST205` → **HTTP 404**,
>   `Could not find the table 'public.wp_content' in the schema cache`.
> - `PGRST301` → **HTTP 401**, with two distinct messages worth translating
>   separately, since they point at different user mistakes:
>   `Expected 3 parts in JWT; got 1` (the value is not a JWT at all — often a
>   truncated paste) and `No suitable key or wrong key type`, details
>   `None of the keys was able to decode the JWT` (well-formed but signed by a
>   different project, i.e. the key belongs to the wrong project).
>
> **Confirmed in M5, all recorded as fixtures** in `tests/fixtures/errors/`:
> `PGRST205`, `PGRST204`, `PGRST301` (two variants), `42501` (two variants),
> `23505`, `23502`, `22P02`, `42703`.
>
> Two things the table did not predict:
>
> - The two `42501` variants differ in **HTTP status** as well as message:
>   **401** for a missing GRANT, **403** for a policy rejection. Useful, but do not
>   rely on it alone — disambiguate on the message text as this table says.
> - **`42P01` cannot be provoked through PostgREST.** It checks its own schema
>   cache first and returns `PGRST205` before Postgres sees the query, so
>   `relation … does not exist` never surfaces over the Data API. The translator
>   still handles it (it can arrive from an RPC into a function referencing a
>   dropped table) but it has **no recorded fixture**, and that row remains an
>   untested belief.

Each translated error should expose `->explanation()`, `->fix()`, and
`->doc_url()`, and the queue's `last_error` should store the translated form so
the logs page is readable by a non-expert.

---

## 7. Settings and security

### 7.1 Key handling — non-negotiable

- The service role key **bypasses RLS**. Treat it like a root password.
- Preferred source: a constant in `wp-config.php`.
  ```php
  define( 'WPSB_SERVICE_ROLE_KEY', '...' );
  ```
- Fallback: the options table, but then show a **persistent admin notice**
  explaining the risk (DB dumps, backups, staging clones) and how to move it to
  `wp-config.php`. `Settings.php` reads the constant first, option second.
- The key must never be: printed in HTML, passed to `wp_localize_script`, written
  to a log, included in a diagnostics export, or sent in a support bundle. Route
  every log/display through `Support/Redactor.php`.
- `KeyInspector` base64-decodes the JWT **payload only** (no signature
  verification — that's not the point) to read the `role` claim, so the settings
  page can say "this looks like an **anon** key, not a service key" before the
  user wastes an hour. Wrap in try/catch; a malformed key is a `warn`, not a fatal.

### 7.2 Standard WP hardening

- `current_user_can( 'manage_options' )` on every admin screen and action.
- Nonces on every form; `check_admin_referer()` on every POST handler.
- `sanitize_text_field` / `esc_url_raw` on input; `esc_html` / `esc_attr` /
  `esc_url` on output. No exceptions.
- All SQL through `$wpdb->prepare()`. Table names interpolated only from
  `$wpdb->prefix`.
- `uninstall.php` guarded with `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;` and
  only removes data if the "delete data on uninstall" setting is on.

### 7.3 Settings fields

| Field | Type | Default |
|---|---|---|
| Project URL | url | — |
| Service role key | password (or constant) | — |
| Anon key | password | — (needed for checks 9/10) |
| Site ID | text | sanitized host of `home_url()` |
| Table name | text | `wp_content` |
| Synced post types | multi-check | `post`, `page` |
| Synced meta keys | textarea, one per line | empty |
| Batch size | int | 50 |
| Sync enabled | toggle | off until diagnostics pass |
| Delete data on uninstall | toggle | off |

"Sync enabled" defaults **off**, and the settings page should nudge the user to
run diagnostics first. Shipping a plugin that refuses to write until it has
verified its own configuration is, again, on-theme.

> Built as specified. Three constants are supported rather than the one named in
> §7.1: `WPSB_SERVICE_ROLE_KEY`, `WPSB_PROJECT_URL` and `WPSB_ANON_KEY`.
>
> One implementation detail worth knowing: the form never renders a stored key
> into the HTML, so it cannot round-trip one. An empty password field therefore
> means "unchanged", and clearing a key needs its own explicit checkbox.

---

## 8. WP-CLI

Register in `Cli/Commands.php`, only when `defined( 'WP_CLI' ) && WP_CLI`.

```bash
wp supabase doctor [--format=table|json]   # run diagnostics; exit 1 on any fail
wp supabase status                         # queue depth, last sync, dead-letter count
wp supabase sync --all [--post-type=post] [--force] [--dry-run]
wp supabase sync <post_id> [--force]
wp supabase queue list [--dead] [--format=]
wp supabase queue retry [--all|--id=]
wp supabase queue clear
wp supabase schema --print                 # emit the migration SQL for current settings
```

Two notes:

- `doctor` exiting non-zero makes it usable in CI or a monitoring cron. Say so in
  the README.
- `schema --print` generates the SQL matching the configured table name and
  mapper fields, so the user never hand-writes the migration and drift can't
  creep in. Write its output to `supabase/migrations/0001_wp_content.sql`.

---

## 9. Testing

### 9.1 Environment

- WordPress: `wp-env` (Node is available: v22). `.wp-env.json` with the plugin
  mapped in.
  - **Corrected in M1:** `wp-env` does not run on this machine. It shells out to
    `docker compose`, and this Docker (colima) has no compose plugin, so
    `wp-env start` fails with `unknown shorthand flag: 'f' in -f`.
    `.wp-env.json` is committed and correct for a machine that has compose;
    `tests/wp.sh` does the same job with plain `docker run` in the meantime.
    Versions are pinned to the stated minimums, WordPress 6.4 and PHP 8.1,
    because that is the boundary the "requires at least" header claims.
- Supabase: reuse the **already-verified** local stack in `../supabase-gotchas`
  (`supabase start`).
- **Networking gotcha:** from inside the container, the Supabase API on the host
  is *not* `localhost:54321`. Use `http://host.docker.internal:54321`.
  - **Verified in M1:** the `localhost` half is right — it fails from inside the
    container. The suspicion that colima needs `--network-address` is **wrong**:
    `host.docker.internal` resolves to `192.168.5.2` and works with no colima
    flags and no `--add-host`. `192.168.5.2` and `172.17.0.1` also work;
    `localhost` and `host-gateway` do not.

### 9.2 What to test

Unit (no WP bootstrap needed where possible):

- `PostMapper` — field mapping, hash stability, HTML/excerpt handling
- `ErrorTranslator` — one case per row in §6.3, against **recorded real
  fixtures**, not invented strings
- `Redactor` — keys never survive a round trip
- Backoff math

Integration (against the live local stack):

- Publish a post → row appears in `wp_content` with matching fields
- Edit → row updates, `content_hash` changes
- Unpublish → row is deleted
- Delete → row is deleted
- Bulk-edit 100 posts → queue coalesces, all 100 land, request count stays sane
- Anon key sees published rows only (checks 9 + 10)
- Drop the `service_role` GRANT → `doctor` reports check 7 fail with the *GRANT*
  explanation, not the RLS one. **This is the single most important test** — it
  proves the disambiguation in §6.3 actually works.

### 9.3 Definition of done for any milestone

No milestone is complete until its behavior is verified against the running
stack and the result is written into `CONTEXT.md`. Follow the sibling repo's
discipline: record what was tested, on what versions, and what the actual output
was. Never mark something verified that wasn't run.

---

## 10. Milestones

Each milestone must leave the plugin installable and non-broken.

**M1 — Scaffold** ✅ *complete, verified 2026-08-04*
Plugin header, autoloader, `Plugin.php` wiring, `Installer.php` with both tables,
`Settings` + settings page, `SupabaseClient` with a `ping()`, "Test connection"
button. Create `CONTEXT.md`.
*Done when:* plugin activates cleanly on WP 6.4+/PHP 8.1+, settings save, and Test
Connection reports a real pass/fail against the local stack.

> Verified on WordPress 6.4.3 / PHP 8.1.34 against Supabase CLI 2.111.0:
> activation leaves the PHP error log empty with `WP_DEBUG` on, settings save
> through `options.php` with every sanitizer firing, and Test Connection returns
> a real pass over `admin-ajax.php` plus correct, non-cascading failures for an
> unreachable port, a corrupted key signature, and an anon key in the service
> field. `tests/wp.sh verify` runs 63 checks, 0 failures. `Redactor`, `Logger`,
> `KeyInspector` and `Notices` were built alongside, because §7's key-handling
> rules are not deferrable. See `CONTEXT.md` for the full record.

**M2 — Schema** ✅ *complete, verified 2026-08-04*
`PostMapper` field list, `wp supabase schema --print`, committed migration,
applied and verified on the local stack including the GRANT layer.
*Done when:* the migration applies clean and `service_role` can insert while
`anon` can only read published rows — both proven, not assumed.

> Generated SQL matches this spec exactly and applied clean. Over HTTP:
> `service_role` inserts; `service_role` sees published and unpublished rows while
> `anon` sees only published; `anon` INSERT and DELETE are both refused with
> `42501 permission denied for table wp_content`. Sequence-grant question settled
> in §4 above.

**M3 — Single-object sync** ✅ *complete, verified 2026-08-04*
Upsert and delete for one post. Hooks wired. Content hash. No queue yet — sync
inline on the hook, behind a "sync enabled" flag.
*Done when:* publish/edit/unpublish/delete of a single post all produce the
correct row state in Supabase.

> All four verified by reading the row back out of Supabase. Edit changes
> `content_hash` and leaves exactly one row, so the upsert is upserting rather
> than inserting. Unpublish deletes the row and clears the stored hash. An
> unchanged re-sync is skipped without a request; `--force` overrides it.
>
> Note the implementation went straight to the queue rather than syncing inline:
> M4's queue was built in the same pass, and `sync_post()` still provides the
> inline path used by `wp supabase sync <id>`.

**M4 — Queue and reliability** ✅ *complete, verified 2026-08-04*
Queue table, coalescing, cron, batching, backoff, dead-letter, backfill,
`wp supabase sync --all`.
*Done when:* 100 posts backfill correctly, a forced failure retries with growing
delay and lands in dead-letter after 8 attempts.

> 100 posts backfilled and synced in **4** `process_queue` calls at batch size 50.
> Creating them fired 200+ hooks (`wp_insert_post` assigns the default category,
> so `set_object_terms` fires as well as `transition_post_status`) and coalesced to
> exactly **100** rows. A forced transport failure produced attempts
> `1,2,3,4,5,6,7,8` and then dead-lettered; backoff measured 2/4/32 minutes at
> attempts 1/2/5, capped at 3600s. A rejected *key* dead-letters on the first
> attempt instead of burning eight. See §5.3 for the claim-token correction.

**M5 — Diagnostics** ✅ *complete, verified 2026-08-04* ← *the milestone that matters most*
All 12 checks, `ErrorTranslator` with real recorded fixtures, diagnostics admin
page, logs page, `wp supabase doctor`.
*Done when:* deliberately breaking each of — key, URL, table, GRANT, RLS policy —
produces a *specific, correct, non-cascading* diagnosis naming the right layer.

> `tests/break-and-diagnose.sh` breaks each layer in turn and asserts the wording:
> 41 checks, 0 failures. Dropping the `service_role` GRANT produces the **GRANT**
> diagnosis with the exact fix SQL and never mentions policies; `write` skips
> rather than inventing a second story; `doctor` exits 1. A too-permissive policy
> is caught as a `SECURITY` finding by `rls_leak` while `rls_anon` correctly still
> passes. A corrupted key fails `auth` while `reachable` still passes.
>
> One design change came out of this: `auth` now depends on `key_shape`, because an
> anon key in the service field otherwise made four downstream checks offer GRANT
> advice — accurate about what Postgres said, and the wrong fix. See `CONTEXT.md`.

**M6 — Polish and docs** ✅ *complete, verified 2026-08-04*
`README.md` with quickstart + architecture rationale, `readme.txt`, screenshots,
i18n pass, WPCS clean, security review against §7.
*Done when:* someone who has never seen the plugin can install and sync in under
ten minutes using only the README.

> Also verified on **WordPress 7.0.2 / PHP 8.3.33** in addition to the 6.4.3 /
> 8.1.34 minimum: 293 checks, 0 failures on both, no PHP 8.3 deprecations.
> `Tested up to` is 7.0 accordingly. Distribution is GitHub rather than the
> WP.org directory (decided 2026-08-04), so §10 M6's directory concerns are moot
> and `load_plugin_textdomain()` is required rather than redundant.
>
> `README.md` (five-step quickstart, frontend query examples, architecture
> rationale), `readme.txt` in WP.org format, `LICENSE` (GPL-2.0-or-later),
> `phpcs.xml.dist`, and a 264-string `.pot` from `wp i18n make-pot`. All 274
> translation calls carry the text domain. **WPCS is clean on all 44 files**,
> verified with phpcs 3.13.4 / WPCS 3.4.1 rather than asserted; every ruleset
> exclusion is justified inline. The §7 security review is recorded in
> `CONTEXT.md` and passes on all seven points.
>
> **Screenshots are the one gap:** `readme.txt` describes four that have not been
> taken, so it is not submittable to WP.org as-is. See `CONTEXT.md` "Next steps"
> for the trademark question too, which is the larger blocker.

---

## 11. Working agreements

- **Do not initialize git or push anything.** The author handles version control.
- **Verify, don't assume.** Anything in this spec marked "verify" is a belief, not
  a fact. There's a working local Supabase stack next door — use it. If reality
  contradicts this spec, reality wins: fix the code, then correct the spec and
  note it in `CONTEXT.md` (the sibling repo has a "Corrections to earlier notes"
  section — copy that pattern).
- **Keep `CONTEXT.md` current** as the durable handoff doc.
- Don't add dependencies without asking. No Composer, no build step, no npm in
  the shipped plugin.
- Ask before any large refactor of a completed milestone.
