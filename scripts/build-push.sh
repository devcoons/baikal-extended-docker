#!/usr/bin/env bash
# Build and optionally push devcoons/baikal-extended to Docker Hub.
#
# Usage:
#   ./scripts/build-push.sh              # build only
#   ./scripts/build-push.sh --push       # build and push
#   ./scripts/build-push.sh --push 0.11.1
#
# Prerequisites:
#   git submodule update --init --recursive
#   podman login docker.io   # or: docker login

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

REGISTRY="${REGISTRY:-docker.io}"
IMAGE="${IMAGE:-devcoons/baikal-extended}"
PUSH=false
TAG=""

for arg in "$@"; do
    case "$arg" in
        --push) PUSH=true ;;
        -h|--help)
            sed -n '2,12p' "$0"
            exit 0
            ;;
        *)
            if [[ -z "$TAG" && "$arg" != --* ]]; then
                TAG="$arg"
            fi
            ;;
    esac
done

if [[ -z "$TAG" ]]; then
    if command -v php >/dev/null 2>&1; then
        TAG="$(php -r "include 'src/Core/Distrib.php'; echo BAIKAL_VERSION;")"
    else
        TAG="$(grep -oP 'define\("BAIKAL_VERSION", "\K[^"]+' src/Core/Distrib.php)"
    fi
fi

if command -v podman >/dev/null 2>&1; then
    BUILDER=podman
elif command -v docker >/dev/null 2>&1; then
    BUILDER=docker
else
    echo "error: need podman or docker" >&2
    exit 1
fi

echo "==> Ensuring submodule is checked out"
git submodule update --init --recursive

FULL_IMAGE="${REGISTRY}/${IMAGE}"
echo "==> Building ${FULL_IMAGE}:${TAG} and ${FULL_IMAGE}:latest"
"$BUILDER" build -t "${FULL_IMAGE}:${TAG}" -t "${FULL_IMAGE}:latest" .

if [[ "$PUSH" == true ]]; then
    echo "==> Pushing ${FULL_IMAGE}:${TAG} and ${FULL_IMAGE}:latest"
    "$BUILDER" push "${FULL_IMAGE}:${TAG}"
    "$BUILDER" push "${FULL_IMAGE}:latest"
    echo "==> Done: ${FULL_IMAGE}:${TAG}"
else
    echo "==> Built locally (not pushed). To push:"
    echo "    $0 --push"
    echo "    $0 --push $TAG"
fi
