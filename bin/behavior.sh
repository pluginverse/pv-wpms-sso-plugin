#!/bin/sh
set -eu
plugin_dir=$(basename "$PWD")
# Behat fails closed for empty selections when a path is explicit.
case "${1:-}" in
    ""|-*) set -- tests/Behavior/features "$@" ;;
esac
exec npx @wordpress/env run tests-cli --env-cwd="wp-content/plugins/$plugin_dir" vendor/bin/behat "$@"
