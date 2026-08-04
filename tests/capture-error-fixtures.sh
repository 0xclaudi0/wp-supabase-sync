#!/bin/zsh
#
# Record real PostgREST/Postgres error bodies as test fixtures.
#
#   tests/capture-error-fixtures.sh
#
# SPEC §6.3 requires the ErrorTranslator to be tested against recorded responses
# rather than strings someone imagined. This script deliberately provokes each
# error against the running local stack and writes the exact response body to
# tests/fixtures/errors/<code>.json, along with the HTTP status.
#
# Requires the local stack in ../supabase-gotchas to be running, and the
# wp_content migration to have been applied.
#
set -e

DB_CONTAINER="supabase_db_supabase-gotchas"
BASE="http://127.0.0.1:54321"
OUT="${0:A:h}/fixtures/errors"

SVC="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.EGIM96RAZx35lJzdJsyH-qQwv8Hdp7fsn3W0YpN81IU"
ANON="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6ImFub24iLCJleHAiOjE5ODM4MTI5OTZ9.CRXP1A7WOeoJeXxjNni43kdQwgnWNReilDMblYTn_I0"

mkdir -p "$OUT"

psql() {
	docker exec -i "$DB_CONTAINER" psql -U postgres -d postgres -X -q "$@"
}

# Capture one request: name, then curl args.
capture() {
	local name="$1"
	shift

	# Not named `status`: that is a read-only variable in zsh.
	local body http_code
	body=$(curl -s -w '\n%{http_code}' "$@")
	http_code="${body##*$'\n'}"
	body="${body%$'\n'*}"

	print "  ${name}: HTTP ${http_code}"
	print -r "    ${body}"

	# `print -r` / printf, never plain `print`: these bodies contain backslash
	# escapes such as \"wp_content\", and zsh's print would interpret them and
	# write JSON that no longer parses.
	printf '{"http_status": %s, "body": %s}\n' "$http_code" "$body" > "${OUT}/${name}.json"
}

print "Capturing real error fixtures..."

# --- PGRST301: malformed key (not a JWT at all) -----------------------------
capture pgrst301_malformed "$BASE/rest/v1/" \
	-H "apikey: not-a-jwt" -H "Authorization: Bearer not-a-jwt"

# --- PGRST301: well-formed JWT signed by another project -------------------
BADSIG="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZS1kZW1vIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImV4cCI6MTk4MzgxMjk5Nn0.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
capture pgrst301_bad_signature "$BASE/rest/v1/" \
	-H "apikey: $BADSIG" -H "Authorization: Bearer $BADSIG"

# --- PGRST205: table not in the schema cache -------------------------------
capture pgrst205_missing_table "$BASE/rest/v1/definitely_not_a_table?limit=1" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC"

# --- PGRST204: column the table does not have ------------------------------
capture pgrst204_missing_column -X POST "$BASE/rest/v1/wp_content" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC" \
	-H "Content-Type: application/json" \
	-d '[{"site_id":"fixture","wp_id":5001,"post_type":"post","status":"publish","slug":"s","content_hash":"h","column_that_does_not_exist":"x"}]'

# --- 42501: GRANT missing (row level security never consulted) -------------
# Revoke from anon, then read as anon. This is the symptom the plugin's most
# important disambiguation depends on.
psql -c "revoke all on public.wp_content from anon;" >/dev/null
capture 42501_permission_denied "$BASE/rest/v1/wp_content?limit=1" \
	-H "apikey: $ANON" -H "Authorization: Bearer $ANON"
psql -c "grant select on public.wp_content to anon;" >/dev/null

# --- 42501: RLS WITH CHECK rejected the row --------------------------------
# A completely different problem behind the same SQLSTATE. Give authenticated
# insert privileges plus a policy that refuses everything, then insert as
# authenticated so RLS — not the grant — is what fails.
psql >/dev/null <<'SQL'
grant insert, select on public.wp_content to authenticated;
drop policy if exists "fixture_reject_all" on public.wp_content;
create policy "fixture_reject_all" on public.wp_content
  for insert to authenticated with check ( false );
SQL

AUTHED=$(docker exec -i "$DB_CONTAINER" psql -U postgres -d postgres -tAc \
	"select 1" >/dev/null && print ok)

# Build an authenticated JWT with the local demo secret so PostgREST accepts it.
AUTH_JWT=$(docker exec -i "$DB_CONTAINER" psql -U postgres -d postgres -tAc \
	"select extensions.sign('{\"role\":\"authenticated\",\"sub\":\"00000000-0000-0000-0000-000000000001\",\"exp\":1983812996}'::json, 'super-secret-jwt-token-with-at-least-32-characters-long');" 2>/dev/null | tr -d '[:space:]')

if [[ -n "$AUTH_JWT" ]]; then
	capture 42501_rls_with_check -X POST "$BASE/rest/v1/wp_content" \
		-H "apikey: $AUTH_JWT" -H "Authorization: Bearer $AUTH_JWT" \
		-H "Content-Type: application/json" \
		-d '[{"site_id":"fixture","wp_id":5002,"post_type":"post","status":"publish","slug":"s2","content_hash":"h2"}]'
else
	print "  42501_rls_with_check: SKIPPED (could not mint an authenticated JWT)"
fi

psql >/dev/null <<'SQL'
drop policy if exists "fixture_reject_all" on public.wp_content;
revoke insert on public.wp_content from authenticated;
SQL

# --- 23505: unique violation with no conflict resolution -------------------
curl -s -o /dev/null -X POST "$BASE/rest/v1/wp_content" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC" -H "Content-Type: application/json" \
	-d '[{"site_id":"fixture","wp_id":5003,"post_type":"post","status":"publish","slug":"dupe","content_hash":"h"}]'

capture 23505_duplicate_key -X POST "$BASE/rest/v1/wp_content" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC" -H "Content-Type: application/json" \
	-d '[{"site_id":"fixture","wp_id":5003,"post_type":"post","status":"publish","slug":"dupe","content_hash":"h"}]'

# --- 23502: NOT NULL violation --------------------------------------------
capture 23502_not_null -X POST "$BASE/rest/v1/wp_content" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC" -H "Content-Type: application/json" \
	-d '[{"site_id":"fixture","wp_id":5004,"post_type":"post","status":"publish","slug":"s"}]'

# --- 22P02: unparseable value --------------------------------------------
capture 22P02_bad_type -X POST "$BASE/rest/v1/wp_content" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC" -H "Content-Type: application/json" \
	-d '[{"site_id":"fixture","wp_id":"not-a-number","post_type":"post","status":"publish","slug":"s","content_hash":"h"}]'

# --- 42703 / PGRST100: selecting a column that does not exist -------------
capture unknown_column_select "$BASE/rest/v1/wp_content?select=no_such_column&limit=1" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC"

# --- HTTP 404: right host, wrong path ------------------------------------
capture http404_wrong_path "$BASE/rest/v1/../nope" \
	-H "apikey: $SVC" -H "Authorization: Bearer $SVC"

# Clean up fixture rows.
psql -c "delete from public.wp_content where site_id = 'fixture';" >/dev/null

print ""
print "Fixtures written to ${OUT}:"
ls -1 "$OUT"
