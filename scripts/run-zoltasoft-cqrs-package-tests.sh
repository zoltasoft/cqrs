#!/usr/bin/env bash
set -uo pipefail

# Usage:
#   ./scripts/run-package-tests.sh <package-dir> <script> [keep]
# Examples:
#   ./scripts/run-package-tests.sh packages/cqrs qa
#   ./scripts/run-package-tests.sh packages/cqrs test keep
# If third arg is 'keep' the package's vendor directory is preserved.

PKG_DIR=${1:-packages/cqrs}
SCRIPT=${2:-phpunit}
KEEP_VENDOR=${3:-}
EXIT_CODE=0

cleanup() {
  popd >/dev/null 2>&1 || true
  if [ "$KEEP_VENDOR" != "keep" ]; then
    echo "Cleaning vendor directory for $PKG_DIR"
    rm -rf "$PKG_DIR/vendor"
  else
    echo "Keeping vendor directory for $PKG_DIR"
  fi
}
trap cleanup EXIT

if [ ! -d "$PKG_DIR" ]; then
  echo "Package directory not found: $PKG_DIR" >&2
  exit 2
fi

pushd "$PKG_DIR" >/dev/null
set -e  # Enable errexit after pushd succeeds

echo "Installing composer dependencies (including dev) for $PKG_DIR..."
composer install --no-interaction --prefer-dist --ansi || { EXIT_CODE=$?; set +e; }

if [ "$SCRIPT" = "phpunit" ]; then
  echo "Running phpunit..."
  if [ -x vendor/bin/phpunit ]; then
    vendor/bin/phpunit || { EXIT_CODE=$?; set +e; }
  else
    echo "phpunit not found in vendor/bin. Did composer install succeed?" >&2
    exit 2
  fi
else
  echo "Running composer script: $SCRIPT"
  # If the user passed 'qa' or another script name, run it via composer
  composer run-script --no-interaction "$SCRIPT" || { EXIT_CODE=$?; set +e; }
fi

set +e  # Disable errexit before cleanup so trap runs
if [ $EXIT_CODE -eq 0 ]; then
  echo "Done."
else
  echo "QA script failed with exit code $EXIT_CODE"
fi

exit $EXIT_CODE
