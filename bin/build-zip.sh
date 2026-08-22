#!/usr/bin/env bash
# Build the installable plugin zip: production files only, wrapped in a
# calucon-third-party-embed-gate/ directory so it extracts as a proper plugin folder.
# Used by .github/workflows/release.yml and runnable locally:
#
#   bash bin/build-zip.sh   ->  build/calucon-third-party-embed-gate-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(bash bin/plugin-version.sh)
if [ -z "$VERSION" ]; then
	echo "Could not read the Version header from calucon-third-party-embed-gate.php" >&2
	exit 1
fi

STAGE="build/calucon-third-party-embed-gate"
ZIP="build/calucon-third-party-embed-gate-${VERSION}.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

# The production surface, nothing else: no tests, no dev tooling, no
# node_modules/vendor (the plugin has no runtime Composer dependencies).
# docs/ ships deliberately: customizing.md is the on-site reference for
# developers and AI agents (the deep docs stay in the repo).
cp calucon-third-party-embed-gate.php uninstall.php readme.txt LICENSE wpml-config.xml "$STAGE/"
cp -R src assets templates languages docs "$STAGE/"

( cd build && zip -rq "calucon-third-party-embed-gate-${VERSION}.zip" calucon-third-party-embed-gate )

echo "Built $ZIP:"
unzip -l "$ZIP"
