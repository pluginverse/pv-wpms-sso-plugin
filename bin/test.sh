#!/bin/sh
# Runs the full PHPUnit suite inside the wp-env tests container.
# The plugin directory name inside the container matches the checkout's
# basename (pv-wpms-sso locally, pv-wpms-sso-plugin in CI), so resolve it.
set -e

plugin_dir=$(basename "$PWD")
exec npx @wordpress/env run tests-cli --env-cwd="wp-content/plugins/$plugin_dir" vendor/bin/phpunit "$@"
