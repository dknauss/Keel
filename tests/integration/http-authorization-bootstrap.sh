#!/usr/bin/env bash
# Build the two disposable WordPress installs the HTTP authorization lab needs:
# a single site and a multisite network, sharing one database by table prefix.
# Never point this at an existing install; it refuses a directory that has one.
#
# The single site links this checkout as plugins/keel-defaults and the network as
# plugins/keel, so every run also proves the harness does not depend on the
# plugin's folder name.
set -euo pipefail

lab_root="${1:?Pass a new absolute directory for the two lab installs}"
lab_version="${2:-latest}"
lab_repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
single_url="${KEEL_HTTP_LAB_SINGLE_URL:-http://127.0.0.1:8889}"
multi_url="${KEEL_HTTP_LAB_MULTI_URL:-http://127.0.0.1:8890}"

if [[ "$lab_root" != /* || "$lab_root" == / || -e "$lab_root/single" || -e "$lab_root/multi" ]]; then
  echo 'Refusing a non-absolute path or a directory that already holds a lab.' >&2
  exit 2
fi
for url in "$single_url" "$multi_url"; do
  if [[ ! "$url" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]; then
    echo "Lab URLs must be plain http on localhost or 127.0.0.1: $url" >&2
    exit 2
  fi
done
mkdir -p "$lab_root"
lab_root="$(cd -- "$lab_root" && pwd)"

# The admin account WordPress requires at install is never used by the lab; the
# setup script creates its own owner. A random password keeps it unusable.
admin_password="$(php -r 'echo bin2hex(random_bytes(24));')"

build() {
  local name="$1" url="$2" prefix="$3" folder="$4"
  local path="$lab_root/$name"
  mkdir -p "$path"
  # KEEL_HTTP_LAB_CORE: a local WordPress tree to copy instead of downloading,
  # for runners that cannot reach wordpress.org.
  if [[ -n "${KEEL_HTTP_LAB_CORE:-}" ]]; then
    cp -R "$KEEL_HTTP_LAB_CORE/." "$path/"
    rm -rf "$path/.git"
  else
    wp core download --path="$path" --version="$lab_version" --locale=en_US --allow-root
  fi
  wp config create --path="$path" --dbname="${KEEL_LAB_DB_NAME:-wordpress}" \
    --dbuser="${KEEL_LAB_DB_USER:-root}" --dbpass="${KEEL_LAB_DB_PASSWORD:-root}" \
    --dbhost="${KEEL_LAB_DB_HOST:-127.0.0.1:3306}" --dbprefix="$prefix" --skip-check --allow-root
  if [[ "$name" == multi ]]; then
    wp core multisite-install --path="$path" --url="$url" --title='Keel HTTP lab network' \
      --admin_user=lab_install --admin_password="$admin_password" --admin_email=install@example.test \
      --skip-email --allow-root
  else
    wp core install --path="$path" --url="$url" --title='Keel HTTP lab' \
      --admin_user=lab_install --admin_password="$admin_password" --admin_email=install@example.test \
      --skip-email --allow-root
  fi
  ln -s "$lab_repo" "$path/wp-content/plugins/$folder"
  if [[ "$name" == multi ]]; then
    wp plugin activate "$folder" --network --path="$path" --allow-root
  else
    wp plugin activate "$folder" --path="$path" --allow-root
  fi
}

build single "$single_url" wp_single_ keel-defaults
build multi "$multi_url" wp_multi_ keel
echo "HTTP authorization lab installs ready under $lab_root (WordPress $(wp core version --path="$lab_root/single" --allow-root))."
