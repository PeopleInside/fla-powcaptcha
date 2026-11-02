#!/usr/bin/env bash
# Checks the SHIPPED bundles the way Flarum loads them (PHP wrapper + core bootExtensions),
# and a functional smoke test of the forum bundle with lazy-loaded modals.
# Usage: bash tests/js/run.sh        (needs only Node.js)
set -e
cd "$(dirname "$0")"
node check-wrapper.js ../../js/dist/forum.js "forum.js"
node check-wrapper.js ../../js/dist/admin.js "admin.js"
node smoke-forum.js
node smoke-admin.js
