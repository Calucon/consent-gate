#!/usr/bin/env bash
# Print the plugin version from the main file's header — the single source
# of truth shared by bin/build-zip.sh and .github/workflows/release.yml, so
# the release tag and the packaged zip can never disagree.
set -euo pipefail
cd "$(dirname "$0")/.."
header=$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9][0-9A-Za-z.\-]*)[[:space:]]*$/\1/p' calucon-third-party-embed-gate.php | head -n1)
constant=$(sed -nE "s/^define\( 'CALUCON_EMBED_GATE_VERSION', '([^']+)' \);.*$/\1/p" calucon-third-party-embed-gate.php | head -n1)
stable=$(sed -nE 's/^Stable tag:[[:space:]]*([0-9][0-9A-Za-z.\-]*)[[:space:]]*$/\1/p' readme.txt | head -n1)
# Every merge to main deploys: the header, the constant and readme's
# Stable tag must agree, or wp.org serves the wrong tag.
if [[ -z "$header" || "$header" != "$constant" || "$header" != "$stable" ]]; then
	echo "version mismatch: header='$header' constant='$constant' stable_tag='$stable'" >&2
	exit 1
fi
echo "$header"
