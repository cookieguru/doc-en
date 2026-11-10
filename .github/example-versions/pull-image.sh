#!/bin/bash
# Usage: pull-image.sh <tag>
# Old php images use manifest formats current Docker cannot unpack, so fall
# back to converting them with skopeo.
set -uo pipefail

image="php:$1-cli"
docker pull "$image" \
    || skopeo copy --format v2s2 "docker://docker.io/library/$image" "docker-daemon:$image"
