#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
release_dir="$repo_root/dist"
temporary_root="$(mktemp -d)"
plugin_dir="$temporary_root/gym-venmo-checkout"

cleanup() {
  rm -rf "$temporary_root"
}
trap cleanup EXIT

mkdir -p "$release_dir" "$plugin_dir"
cp "$repo_root/gym-venmo-checkout.php" "$plugin_dir/"
cp "$repo_root/readme.txt" "$plugin_dir/"
cp -R "$repo_root/assets" "$plugin_dir/"
cp -R "$repo_root/config" "$plugin_dir/"

(cd "$temporary_root" && zip -qrFS "$release_dir/gym-venmo-checkout-2.2.0.zip" gym-venmo-checkout)
printf 'Created %s\n' "$release_dir/gym-venmo-checkout-2.2.0.zip"
