#!/usr/bin/env bash
set -euo pipefail

profile="release"
warmup="10"
samples="100"
concurrency="5"
timing="client-monotonic"

for arg in "$@"; do
  case "$arg" in
    --profile=*) profile="${arg#*=}" ;;
    --warmup=*) warmup="${arg#*=}" ;;
    --samples=*) samples="${arg#*=}" ;;
    --concurrency=*) concurrency="${arg#*=}" ;;
    --timing=*) timing="${arg#*=}" ;;
  esac
done

if [[ "$profile" != "release" || "$warmup" != "10" || "$samples" != "100" || "$concurrency" != "5" || "$timing" != "client-monotonic" ]]; then
  echo "Release profile requires --profile=release --warmup=10 --samples=100 --concurrency=5 --timing=client-monotonic" >&2
  exit 2
fi

if [[ -z "${AUTH_PERFORMANCE_BASE_URL:-}" ]]; then
  echo "AUTH_PERFORMANCE_BASE_URL required for production-like latency smoke." >&2
  exit 2
fi

echo "Run release auth profile against ${AUTH_PERFORMANCE_BASE_URL}"
echo "Timing: monotonic client clock before request transmission through complete response body."
