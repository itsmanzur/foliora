#!/usr/bin/env bash
#
# Installs the WordPress test suite (wordpress-tests-lib + WP core).
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}

TMPDIR=${TMPDIR:-/tmp}
TMPDIR=${TMPDIR%/}
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sL "$1" -o "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "curl or wget is required" >&2
		exit 1
	fi
}

if [ "$WP_VERSION" = "latest" ]; then
	download "https://api.wordpress.org/core/version-check/1.7/" "$TMPDIR/wp-latest.json"
	if command -v php >/dev/null 2>&1; then
		WP_VERSION=$(php -r 'echo json_decode(file_get_contents($argv[1]))->offers[0]->version;' "$TMPDIR/wp-latest.json")
	else
		WP_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | head -1 | cut -d'"' -f4)
	fi
	WP_TESTS_TAG="tags/${WP_VERSION}"
elif [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/${WP_VERSION}"
else
	WP_TESTS_TAG="tags/${WP_VERSION}"
fi

install_wp() {
	if [ -f "$WP_CORE_DIR/wp-includes/version.php" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$TMPDIR/wordpress.tar.gz"
	tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
}

install_test_suite() {
	if [ ! -d "$WP_TESTS_DIR/includes" ]; then
		mkdir -p "$WP_TESTS_DIR"
		svn co --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn co --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data" || mkdir -p "$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
	fi

	local CONFIG="$WP_TESTS_DIR/wp-tests-config.php"
	cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$CONFIG"
	sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
	sed -i "s:dirname( __DIR__ ) . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
	sed -i "s:dirname( __DIR__ ) . '/build/':'$WP_CORE_DIR/':" "$CONFIG"
	sed -i "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$CONFIG"
	sed -i "s/yourusernamehere/$DB_USER/" "$CONFIG"
	sed -i "s/yourpasswordhere/$DB_PASS/" "$CONFIG"
	sed -i "s|localhost|${DB_HOST}|" "$CONFIG"
}

install_db() {
	local EXTRA=()
	if [ -n "$DB_PASS" ]; then
		EXTRA+=(--password="$DB_PASS")
	fi
	mysqladmin create "$DB_NAME" --user="$DB_USER" --host="$DB_HOST" "${EXTRA[@]}" 2>/dev/null || true
}

install_wp
install_test_suite
install_db
