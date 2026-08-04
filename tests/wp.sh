#!/bin/zsh
#
# Local WordPress harness for this plugin.
#
#   tests/wp.sh up                 start WordPress 6.4 on PHP 8.1 at localhost:8888
#   tests/wp.sh wp <args...>       run wp-cli against it
#   tests/wp.sh verify             run tests/m1-verify.php inside the container
#   tests/wp.sh logs               tail the PHP error log
#   tests/wp.sh down               remove the containers
#
# Why not wp-env, which the spec asks for?
#
# wp-env shells out to `docker compose`, and this machine's Docker (colima) has
# no compose plugin installed, so `wp-env start` fails with
# "unknown shorthand flag: 'f' in -f". Rather than install a host-level
# dependency nobody asked for, this script does the same job with two
# `docker run` calls. `.wp-env.json` is still committed and correct, so
# `wp-env start` will work as-is on a machine that has compose available.
#
# Versions are pinned to the plugin's stated minimums — WordPress 6.4 and PHP
# 8.1 — because that is the boundary where "requires at least" either holds or
# does not. Testing only on latest proves the wrong thing.
#
set -e

# Overridable so the same harness can test more than one version pair. Defaults
# are the plugin's stated minimums; STACK suffixes the container names so a
# second stack runs alongside the first instead of clobbering it.
#
#   STACK=latest WP_IMAGE=wordpress:7.0.2-php8.3-apache \
#     CLI_IMAGE=wordpress:cli-php8.3 PORT=8899 tests/wp.sh up
#
STACK="${STACK:-}"
NETWORK="wpsb-net${STACK:+-$STACK}"
DB_NAME="wpsb-db${STACK:+-$STACK}"
WP_NAME="wpsb-wp${STACK:+-$STACK}"
WP_IMAGE="${WP_IMAGE:-wordpress:6.4-php8.1-apache}"
CLI_IMAGE="${CLI_IMAGE:-wordpress:cli-php8.1}"
PORT="${PORT:-8888}"
PLUGIN_DIR="${0:A:h:h}"
PLUGIN_SLUG="wp-supabase-sync"
SITE_URL="http://localhost:${PORT}"
ADMIN_USER="admin"
ADMIN_PASS="password"

# wp-cli in these images runs as uid 33 (www-data), matching the web container.
wp_cli() {
	docker run --rm \
		--network "$NETWORK" \
		--volumes-from "$WP_NAME" \
		--add-host host.docker.internal:host-gateway \
		-u 33 \
		-e WORDPRESS_DB_HOST="$DB_NAME" \
		-e WORDPRESS_DB_USER=wp \
		-e WORDPRESS_DB_PASSWORD=wp \
		-e WORDPRESS_DB_NAME=wordpress \
		"$CLI_IMAGE" wp "$@"
}

case "${1:-}" in
up)
	docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null

	if ! docker inspect "$DB_NAME" >/dev/null 2>&1; then
		echo "Starting MariaDB..."
		docker run -d --name "$DB_NAME" --network "$NETWORK" \
			-e MARIADB_ROOT_PASSWORD=root \
			-e MARIADB_DATABASE=wordpress \
			-e MARIADB_USER=wp \
			-e MARIADB_PASSWORD=wp \
			mariadb:10.6 >/dev/null
	else
		docker start "$DB_NAME" >/dev/null 2>&1 || true
	fi

	if ! docker inspect "$WP_NAME" >/dev/null 2>&1; then
		echo "Starting WordPress ($WP_IMAGE)..."
		# host.docker.internal is how the container reaches the Supabase stack
		# running on the host. It is not automatic on every Docker backend, so
		# it is mapped explicitly to the gateway.
		docker run -d --name "$WP_NAME" --network "$NETWORK" \
			-p "${PORT}:80" \
			--add-host host.docker.internal:host-gateway \
			-v "${PLUGIN_DIR}:/var/www/html/wp-content/plugins/${PLUGIN_SLUG}" \
			-e WORDPRESS_DB_HOST="$DB_NAME" \
			-e WORDPRESS_DB_USER=wp \
			-e WORDPRESS_DB_PASSWORD=wp \
			-e WORDPRESS_DB_NAME=wordpress \
			-e WORDPRESS_DEBUG=1 \
			-e WORDPRESS_CONFIG_EXTRA="define( 'WP_DEBUG_LOG', '/tmp/wp-errors.log' ); define( 'WP_DEBUG_DISPLAY', false ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );" \
			"$WP_IMAGE" >/dev/null
	else
		docker start "$WP_NAME" >/dev/null 2>&1 || true
	fi

	echo "Waiting for the database..."
	for i in {1..60}; do
		if docker exec "$DB_NAME" mariadb -uwp -pwp -e 'select 1' wordpress >/dev/null 2>&1; then
			break
		fi
		sleep 2
	done

	echo "Waiting for WordPress files..."
	for i in {1..60}; do
		if docker exec "$WP_NAME" test -f /var/www/html/wp-settings.php 2>/dev/null; then
			break
		fi
		sleep 2
	done

	if ! wp_cli core is-installed >/dev/null 2>&1; then
		echo "Installing WordPress..."
		wp_cli core install \
			--url="$SITE_URL" \
			--title="WP Supabase Sync Dev" \
			--admin_user="$ADMIN_USER" \
			--admin_password="$ADMIN_PASS" \
			--admin_email=dev@example.com \
			--skip-email
	fi

	wp_cli core version
	echo "Ready at $SITE_URL/wp-admin (${ADMIN_USER}/${ADMIN_PASS})"
	;;

wp)
	shift
	wp_cli "$@"
	;;

verify)
	# `wp eval-file` eval()s the file's contents, and a declare(strict_types=1)
	# cannot be the first statement of eval'd code. Requiring the file instead
	# compiles it as its own unit, so the declaration stays legal.
	wp_cli eval "require ABSPATH . 'wp-content/plugins/${PLUGIN_SLUG}/tests/m1-verify.php';"
	;;

sync-verify)
	wp_cli eval "require ABSPATH . 'wp-content/plugins/${PLUGIN_SLUG}/tests/sync-verify.php';"
	;;

diag-verify)
	wp_cli eval "require ABSPATH . 'wp-content/plugins/${PLUGIN_SLUG}/tests/diagnostics-verify.php';"
	;;

verify-all)
	"$0" verify && "$0" sync-verify && "$0" diag-verify
	;;

logs)
	docker exec "$WP_NAME" sh -c 'cat /tmp/wp-errors.log 2>/dev/null || echo "(no PHP errors logged)"'
	;;

reset-log)
	docker exec "$WP_NAME" sh -c ': > /tmp/wp-errors.log' 2>/dev/null || true
	;;

down)
	docker rm -f "$WP_NAME" "$DB_NAME" >/dev/null 2>&1 || true
	docker network rm "$NETWORK" >/dev/null 2>&1 || true
	echo "Removed."
	;;

*)
	print -u2 "usage: tests/wp.sh {up|wp <args>|verify|sync-verify|diag-verify|verify-all|logs|reset-log|down}"
	exit 1
	;;
esac
