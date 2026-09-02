#!/usr/bin/env bash
#
# Build the installable module zip for MCP Connector for Perfex CRM.
#
# Produces dist/mcp_connector.zip - the file Setup > Modules > Upload expects -
# and a versioned copy dist/perfex-mcp-connector-<version>.zip for releases.
# The module ships with vendor/ committed, so users never run composer.
#
# Usage: packaging/build-zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(grep -oE "Version:\s*[0-9]+\.[0-9]+\.[0-9]+" mcp_connector/mcp_connector.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
DIST="$ROOT/dist"
STAGE="$DIST/stage"
MODULE_ZIP="$DIST/mcp_connector.zip"
VERSIONED_ZIP="$DIST/perfex-mcp-connector-${VERSION}.zip"

rm -rf "$STAGE" "$MODULE_ZIP" "$VERSIONED_ZIP"
mkdir -p "$STAGE"

# --- copy the module, excluding dev/VCS cruft -------------------------------
rsync -a --delete \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude '*.log' \
  --exclude 'vendor/**/tests' \
  --exclude 'vendor/**/Tests' \
  --exclude 'vendor/**/docs' \
  --exclude 'vendor/bin' \
  mcp_connector "$STAGE/"

# Sanity: the bundled SDK must be present, or users get a white screen.
test -f "$STAGE/mcp_connector/vendor/autoload.php" || { echo "ERROR: vendor/ missing"; exit 1; }
test -f "$STAGE/mcp_connector/mcp_connector.php"   || { echo "ERROR: entry file missing"; exit 1; }

( cd "$STAGE" && zip -rq "$MODULE_ZIP" mcp_connector )
cp "$MODULE_ZIP" "$VERSIONED_ZIP"

rm -rf "$STAGE"

echo "Built:"
echo "  module zip : $MODULE_ZIP"
echo "  versioned  : $VERSIONED_ZIP"
echo "  version    : $VERSION"
