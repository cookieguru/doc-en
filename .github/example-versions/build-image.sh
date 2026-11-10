#!/bin/bash
# Usage: build-image.sh <base-image> <output-image> [extension...]
# Installs the support paths and any extensions the base image lacks, then
# commits the result as <output-image>.
set -euo pipefail

base_image="$1"
output_image="$2"
shift 2
example_versions_dir="$(cd "$(dirname "$0")" && pwd)"
installer="$PWD/install-php-extensions"

if [ ! -x "$installer" ]; then
    curl -fsSL --retry 3 -o "$installer" \
        https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions
    chmod +x "$installer"
fi

container="$(docker run -d \
    -v "$installer:/usr/local/bin/install-php-extensions:ro" \
    -v "$example_versions_dir:/ev:ro" \
    "$base_image" sleep infinity)"
trap 'docker rm -f "$container" >/dev/null' EXIT

docker exec "$container" sh /ev/support/install-paths.sh /ev/support

for extension in "$@"; do
    if docker exec "$container" php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then
        continue
    fi
    docker exec "$container" install-php-extensions "$extension" \
        || echo "::warning::could not install $extension on $base_image"
done

docker commit "$container" "$output_image" >/dev/null
