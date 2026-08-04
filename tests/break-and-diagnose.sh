#!/bin/zsh
#
# End-to-end diagnostics test: break one layer at a time and confirm
# `wp supabase doctor` names that layer and not another.
#
#   tests/break-and-diagnose.sh
#
# This is the test SPEC §9.2 calls the single most important one. Dropping the
# service_role GRANT must produce the *GRANT* diagnosis, not the RLS one — the
# two share SQLSTATE 42501 and need opposite fixes, so getting this wrong sends
# the user to the policy editor when their problem is a privilege.
#
# Requires: the local Supabase stack running, the WordPress harness up, and the
# migration applied.
#
set -e

DB="supabase_db_supabase-gotchas"
HERE="${0:A:h}"
WP="${HERE}/wp.sh"
TABLE="wp_content"

typeset -i CHECKS=0
typeset -i FAILURES=0

psql() {
	docker exec -i "$DB" psql -U postgres -d postgres -X -q "$@"
}

pass() {
	CHECKS+=1
	print "  PASS $1"
}

fail() {
	CHECKS+=1
	FAILURES+=1
	print "  FAIL $1"
	[[ -n "$2" ]] && print "       $2"
}

assert() {
	# assert <label> <condition-exit-code> [detail]
	if [[ "$2" == "0" ]]; then pass "$1"; else fail "$1" "$3"; fi
}

# Run doctor and cache the JSON.
#
# Pick the line that starts with '[' rather than the last line: WP-CLI appends
# its own "Success:" / "Error:" summary after the payload.
run_doctor() {
	DOCTOR_JSON=$("$WP" wp supabase doctor --format=json 2>/dev/null | grep '^\[' | head -1) || true

	if [[ -z "$DOCTOR_JSON" ]]; then
		fail "doctor produced no JSON output" "check \`$WP wp supabase doctor\` manually"
		DOCTOR_JSON='[]'
	fi
}

# Read one check's field out of the cached JSON.
field() {
	# field <check-id> <field>
	print -r "$DOCTOR_JSON" | python3 -c "
import json,sys
try:
    checks = json.load(sys.stdin)
except Exception:
    print('__PARSE_ERROR__'); sys.exit()
for c in checks:
    if c['id'] == '$1':
        print(c.get('$2',''))
        sys.exit()
print('__NOT_FOUND__')
"
}

# doctor exits 1 whenever a check fails, which is the point of it — but under
# `set -e` a bare call would abort this script on exactly the runs we are testing.
doctor_exit_code() {
	local code=0
	"$WP" wp supabase doctor >/dev/null 2>&1 || code=$?
	print "$code"
}

summary() {
	print -r "$DOCTOR_JSON" | python3 -c "
import json,sys
checks = json.load(sys.stdin)
print('  ' + '  '.join(f\"{c['id']}={c['status']}\" for c in checks))
"
}

restore_schema() {
	# Every column the mapper writes, so a test that drops one cannot leave the
	# table broken for the next run. `if not exists` makes this idempotent.
	psql >/dev/null <<SQL
-- The add-column statements are idempotent by design, so their "already exists"
-- notices are expected noise rather than information.
set client_min_messages = warning;
alter table public.${TABLE} add column if not exists title text;
alter table public.${TABLE} add column if not exists excerpt text;
alter table public.${TABLE} add column if not exists content_html text;
alter table public.${TABLE} add column if not exists author_name text;
alter table public.${TABLE} add column if not exists featured_image_url text;
alter table public.${TABLE} add column if not exists url text;
alter table public.${TABLE} add column if not exists published_at timestamptz;
alter table public.${TABLE} add column if not exists modified_at timestamptz;
grant select on public.${TABLE} to anon, authenticated;
grant select, insert, update, delete on public.${TABLE} to service_role;
drop policy if exists "Published content is publicly readable" on public.${TABLE};
create policy "Published content is publicly readable"
  on public.${TABLE} for select to anon, authenticated
  using ( status = 'publish' );
notify pgrst, 'reload schema';
SQL
	# PostgREST reloads its schema cache asynchronously. Two seconds was enough in
	# practice but a suite running immediately afterwards once saw a transient
	# failure, so this is deliberately unhurried — the script is not on a
	# deadline and a flaky test costs more than two seconds.
	sleep 5
}

# Always leave the database in a working state, even if a check aborts.
trap 'print ""; print "Restoring schema..."; restore_schema; print "Done."' EXIT

print "=== Setup: healthy stack with one published row in the mirror ==="

restore_schema

# WP-Cron runs every minute and may have dead-lettered rows while a previous
# suite had deliberately broken credentials configured. Start clean.
"$WP" wp supabase queue clear >/dev/null 2>&1 || true

# Configure the plugin and sync a published post so rls_anon has data to see.
"$WP" wp eval '
$o = get_option("wpsb_settings");
$o["project_url"] = "http://host.docker.internal:54321";
$o["service_role_key"] = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU";
$o["anon_key"] = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0";
$o["site_id"] = "break-test";
$o["table_name"] = "wp_content";
$o["sync_enabled"] = true;
update_option("wpsb_settings", $o);
$id = wp_insert_post(array("post_title"=>"Break Test Post","post_content"=>"Body","post_status"=>"publish","post_type"=>"post"));
echo "post $id\n";
' >/dev/null 2>&1

"$WP" wp supabase sync --all >/dev/null 2>&1 || true

print ""
print "=== 1. Healthy stack ==="
run_doctor
summary

[[ "$(field grants status)" == "pass" ]]; assert "healthy: grants passes" "$?" "$(field grants detail)"
[[ "$(field write status)" == "pass" ]]; assert "healthy: write passes" "$?" "$(field write detail)"
[[ "$(field rls_anon status)" == "pass" ]]; assert "healthy: rls_anon passes" "$?" "$(field rls_anon detail)"
[[ "$(field rls_leak status)" == "pass" ]]; assert "healthy: rls_leak passes" "$?" "$(field rls_leak detail)"

[[ "$(doctor_exit_code)" == "0" ]]; assert "healthy: doctor exits 0" "$?"

print ""
print "=== 2. THE KEY TEST: drop the service_role GRANT (SPEC §9.2) ==="
psql -c "revoke all on public.${TABLE} from service_role;" >/dev/null

run_doctor
summary

GRANTS_STATUS=$(field grants status)
GRANTS_DETAIL=$(field grants detail)
GRANTS_FIX=$(field grants fix)

[[ "$GRANTS_STATUS" == "fail" ]]; assert "grants check FAILS" "$?" "got: $GRANTS_STATUS"

print -r -- "$GRANTS_DETAIL" | grep -qi '42501'
assert "detail names SQLSTATE 42501" "$?" "$GRANTS_DETAIL"

print -r -- "$GRANTS_DETAIL" | grep -qi 'missing GRANT'
assert "detail says it is a missing GRANT" "$?" "$GRANTS_DETAIL"

print -r -- "$GRANTS_DETAIL" | grep -qi 'never reaches row level security\|never even consult'
assert "detail explains RLS was never consulted" "$?" "$GRANTS_DETAIL"

# The disambiguation: it must NOT blame the policy.
print -r -- "$GRANTS_DETAIL" | grep -qiv 'new row violates'
assert "detail does NOT quote the RLS with-check message" "$?" "$GRANTS_DETAIL"

if print -r -- "$GRANTS_DETAIL" | grep -qi 'policy.*rejected the row'; then
	fail "detail must not say a policy rejected the row" "$GRANTS_DETAIL"
else
	pass "detail does NOT say a policy rejected the row"
fi

print -r -- "$GRANTS_FIX" | grep -q "grant select, insert, update, delete on public.${TABLE} to service_role"
assert "fix contains the exact GRANT statement" "$?" "$GRANTS_FIX"

print -r -- "$GRANTS_FIX" | grep -qi 'bypasses row level security, but not table privileges'
assert "fix explains service_role still needs the grant" "$?" "$GRANTS_FIX"

[[ "$(doctor_exit_code)" != "0" ]]; assert "doctor exits non-zero (usable in CI)" "$?"

# Downstream write must not invent a second, different story.
[[ "$(field write status)" == "skip" ]]; assert "write is skipped, not separately failed" "$?" "$(field write status)"

print ""
print "=== 3. Restore the grant, drop the public read policy ==="
restore_schema
psql -c "drop policy if exists \"Published content is publicly readable\" on public.${TABLE};" >/dev/null

run_doctor
summary

[[ "$(field grants status)" == "pass" ]]; assert "grants passes again once restored" "$?" "$(field grants detail)"
[[ "$(field rls_anon status)" == "fail" ]]; assert "rls_anon FAILS with no read policy" "$?" "$(field rls_anon status): $(field rls_anon detail)"

RLS_DETAIL=$(field rls_anon detail)
print -r -- "$RLS_DETAIL" | grep -qi 'empty array\|\[\]'
assert "rls_anon explains anon gets an empty array" "$?" "$RLS_DETAIL"

print -r -- "$RLS_DETAIL" | grep -qi 'no policy grants anon a read'
assert "rls_anon names the missing policy as the cause" "$?" "$RLS_DETAIL"

print -r -- "$(field rls_anon fix)" | grep -qi 'create policy'
assert "rls_anon fix contains the CREATE POLICY statement" "$?" "$(field rls_anon fix)"

# A missing read policy is not a leak, so the leak check must not also cry wolf.
[[ "$(field rls_leak status)" == "pass" ]]; assert "rls_leak still passes (nothing is leaking)" "$?" "$(field rls_leak status)"

print ""
print "=== 4. SECURITY: a policy that is too permissive ==="
psql >/dev/null <<SQL
drop policy if exists "Published content is publicly readable" on public.${TABLE};
create policy "Published content is publicly readable"
  on public.${TABLE} for select to anon, authenticated
  using ( true );
SQL

run_doctor
summary

[[ "$(field rls_leak status)" == "fail" ]]; assert "rls_leak FAILS on an over-permissive policy" "$?" "$(field rls_leak status): $(field rls_leak detail)"

LEAK_DETAIL=$(field rls_leak detail)
print -r -- "$LEAK_DETAIL" | grep -q 'SECURITY'
assert "rls_leak flags it as a security finding" "$?" "$LEAK_DETAIL"

print -r -- "$LEAK_DETAIL" | grep -qi 'anon key'
assert "rls_leak explains the anon key is public by design" "$?" "$LEAK_DETAIL"

# rls_anon should still pass: reading published rows genuinely works.
[[ "$(field rls_anon status)" == "pass" ]]; assert "rls_anon still passes (published reads do work)" "$?" "$(field rls_anon status)"

print ""
print "=== 5. Column drift ==="
restore_schema
psql -c "alter table public.${TABLE} drop column if exists excerpt;" >/dev/null
# PostgREST caches the schema; nudge it so the drift is visible immediately.
psql -c "notify pgrst, 'reload schema';" >/dev/null
sleep 3

run_doctor
summary

[[ "$(field columns status)" == "fail" ]]; assert "columns FAILS when a mapped column is missing" "$?" "$(field columns status): $(field columns detail)"
print -r -- "$(field columns detail)" | grep -q 'excerpt'
assert "columns names the missing column" "$?" "$(field columns detail)"

psql -c "alter table public.${TABLE} add column if not exists excerpt text;" >/dev/null
psql -c "notify pgrst, 'reload schema';" >/dev/null
sleep 3

print ""
print "=== 6. Missing table ==="
psql -c "alter table public.${TABLE} rename to ${TABLE}_stashed;" >/dev/null
psql -c "notify pgrst, 'reload schema';" >/dev/null
sleep 3

run_doctor
summary

[[ "$(field table_exists status)" == "fail" ]]; assert "table_exists FAILS when the table is gone" "$?" "$(field table_exists status)"
print -r -- "$(field table_exists detail)" | grep -qi 'PGRST205\|schema cache'
assert "table_exists names PGRST205 / the schema cache" "$?" "$(field table_exists detail)"
print -r -- "$(field table_exists fix)" | grep -qi 'create table'
assert "table_exists fix includes the full migration" "$?" ""

[[ "$(field grants status)" == "skip" ]]; assert "grants is skipped when there is no table" "$?" "$(field grants status)"
[[ "$(field write status)" == "skip" ]]; assert "write is skipped when there is no table" "$?" "$(field write status)"

psql -c "alter table public.${TABLE}_stashed rename to ${TABLE};" >/dev/null
psql -c "notify pgrst, 'reload schema';" >/dev/null
sleep 3

print ""
print "=== 7. Everything restored ==="
restore_schema
psql -c "notify pgrst, 'reload schema';" >/dev/null
sleep 3

run_doctor
summary

for id in reachable auth table_exists columns grants write rls_anon rls_leak; do
	[[ "$(field $id status)" == "pass" ]]; assert "restored: $id passes" "$?" "$(field $id status): $(field $id detail)"
done

[[ "$(doctor_exit_code)" == "0" ]]; assert "restored: doctor exits 0 again" "$?"

# Clean up the test row.
psql -c "delete from public.${TABLE} where site_id = 'break-test';" >/dev/null

print ""
print "------------------------------------------------------------"
print "${CHECKS} checks, ${FAILURES} failures"

[[ "$FAILURES" -eq 0 ]] || exit 1
