#!/usr/bin/env bash
# Link-rot canary for the built-in provider privacy-policy URLs.
#
# Runs in CI on a schedule — NEVER from the plugin (invariant 9: the plugin
# makes no outbound requests). Fails on any non-2xx final response, and
# reports redirects that leave the provider's domain so a moved policy is
# noticed before site visitors click a stale link.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
while IFS= read -r url; do
	[ -z "$url" ] && continue
	final=$(curl -sS -o /dev/null -L --max-redirs 5 --max-time 20 -A "calucon-embed-gate-link-canary" -w '%{http_code} %{url_effective}' "$url" 2>/dev/null) || final="000 $url"
	code=${final%% *}; effective=${final#* }
	from_host=$(printf '%s' "$url" | sed -E 's#^https?://([^/]+).*#\1#'); to_host=$(printf '%s' "$effective" | sed -E 's#^https?://([^/]+).*#\1#')
	if [[ "$from_host" != "$to_host" ]]; then
		# Redirected to another host — a moved policy or an acquisition
		# (matterport.com → costar.com). Reported first, whatever the final
		# status, so a bot-blocked destination cannot hide the move.
		echo "MOVED $code  $url -> $effective (update Descriptors.php)"
	elif [[ "$code" == 401 || "$code" == 403 || "$code" == 429 ]]; then
		# Bot-blocking, not link rot: the page exists but refuses a curl UA.
		# Reported, not failed, so FAIL keeps meaning "gone".
		echo "BLOCKED $code $url (anti-bot; check by hand)"
	elif [[ "$code" != 2* ]]; then
		echo "FAIL $code  $url -> $effective"; fail=1
	else
		echo "ok   $code  $url"
	fi
done < <(grep -oP "'privacy_url'\s*=>\s*'\K[^']+" src/Providers/Builtin/Descriptors.php | sort -u)
exit $fail
