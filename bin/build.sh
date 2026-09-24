#!/usr/bin/env bash
# Builds the WordPress.org-ready packages of the free plugins into dist/.
#
#   bin/build.sh                 build every free plugin
#   bin/build.sh translator-free build one (repo folder name)
#
# Each package is dist/<slug>/ plus dist/<slug>-<version>.zip, with the
# development-only files listed in .distignore stripped out.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"

# repo folder => WordPress.org slug (the slug is permanent once approved and
# must equal the plugin's Text Domain).
declare -A SLUGS=(
	[clean-urls-free]=dosieci-clean-urls
	[ebay-connector-free]=dosieci-ebay-connector
	[instant-search-free]=dosieci-instant-search
	[seo-doctor-free]=dosieci-seo-doctor
	[translator-free]=dosieci-translator
	[wp-doctor-free]=dosieci-wp-doctor
)

build_one() {
	local folder="$1"
	local slug="${SLUGS[$folder]:-}"

	if [[ -z "$slug" ]]; then
		echo "Unknown plugin folder: $folder" >&2
		exit 1
	fi

	local src="$ROOT/$folder"
	local main="$src/$slug.php"
	local version
	version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$main" | head -1 | tr -d '\r')"

	rm -rf "${DIST:?}/$slug" "$DIST/$slug-"*.zip
	mkdir -p "$DIST/$slug"
	rsync -a --exclude-from="$ROOT/.distignore" "$src/" "$DIST/$slug/"

	(cd "$DIST" && zip -qr "$slug-$version.zip" "$slug")
	echo "built $slug $version -> dist/$slug-$version.zip"
}

if [[ $# -gt 0 ]]; then
	for folder in "$@"; do
		build_one "$folder"
	done
else
	for folder in "${!SLUGS[@]}"; do
		build_one "$folder"
	done
fi
