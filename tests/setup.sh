#!/usr/bin/env bash
# Builds the throwaway WordPress used by the harness (SQLite, no MySQL needed).
# Usage: tests/setup.sh [site-url]     default site URL: https://site.test
# Location: $R2T_HOME (default ~/.cache/r2-offload-tests), outside the repository.
# Public base of the fake bucket: $R2T_PUBLIC_URL (default https://cdn.test).
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=$(dirname "$here")
home=${R2T_HOME:-$HOME/.cache/r2-offload-tests}
url=${1:-https://site.test}
wp_version=${R2T_WP_VERSION:-7.1.3}
public_url=${R2T_PUBLIC_URL:-https://cdn.test}
sqlite_zip=https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
wp_dir=$home/wp
wp_bin=$(type -P wp)
wp() { php -d memory_limit=1G "$wp_bin" "$@"; }

rm -rf "$wp_dir" "$home/state"
mkdir -p "$wp_dir" "$home/state/db"

wp core download --path="$wp_dir" --version="$wp_version" --quiet

curl -sSfL -o "$home/sqlite.zip" "$sqlite_zip"
unzip -q -o "$home/sqlite.zip" -d "$wp_dir/wp-content/plugins"
sqlite_dir=$wp_dir/wp-content/plugins/sqlite-database-integration
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$sqlite_dir#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$sqlite_dir/db.copy" > "$wp_dir/wp-content/db.php"

wp config create --path="$wp_dir" --dbname=r2t --dbuser=r2t --dbpass=r2t --skip-check --quiet --extra-php <<PHP
define('DB_DIR', '$home/state/db');
define('DB_FILE', 'wp.sqlite');
define('NUMOS_R2_ACCOUNT_ID', 'test');
define('NUMOS_R2_ACCESS_KEY', 'test');
define('NUMOS_R2_SECRET_KEY', 'test');
define('NUMOS_R2_BUCKET', 'test-bucket');
define('NUMOS_R2_PUBLIC_URL', '$public_url');
PHP

wp core install --path="$wp_dir" --url="$url" --title=r2t --admin_user=admin \
    --admin_password="$(head -c 18 /dev/urandom | base64)" --admin_email=admin@example.com --skip-email --quiet

mkdir -p "$wp_dir/wp-content/mu-plugins"
ln -sfn "$here/harness-mu.php" "$wp_dir/wp-content/mu-plugins/harness-mu.php"
ln -sfn "$repo" "$wp_dir/wp-content/plugins/numos-r2-offload"
wp plugin activate numos-r2-offload --path="$wp_dir" --quiet
echo "WordPress $wp_version ready in $wp_dir ($url)"
