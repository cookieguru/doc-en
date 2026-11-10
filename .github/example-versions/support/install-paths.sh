#!/bin/sh
# Usage: install-paths.sh <support-dir>
# POSIX sh so it runs on the oldest PHP images.
set -e
support="$1"
grep -v '^#' "$support/paths.txt" | while read -r target source; do
    [ -n "$target" ] || continue
    if [ -z "$source" ]; then
        mkdir -p "$target"
        chmod 777 "$target"
    else
        mkdir -p "$(dirname "$target")"
        rm -rf "$target"
        cp -R "$support/files/$source" "$target"
        chmod -R a+rwX "$target"
    fi
done
