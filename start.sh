#!/bin/bash
set -e

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

docker compose \
    -f "$script_directory/docker/docker-compose.yml" \
    -f "$script_directory/docker/docker-compose.test.yml" \
    up -d

exec "$@"
