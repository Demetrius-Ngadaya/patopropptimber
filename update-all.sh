#!/usr/bin/env bash
# PatoPro: update EVERY business on this server after a "git push" from your PC.
# The list of business folders is ~/.patopro-sites (one path per line; ./new-business.sh
# adds to it). One business failing does not stop the others.
#
# Usage (on the server):   ~/patopro/update-all.sh            (backend + website)
#                          ~/patopro/update-all.sh frontend   (only the websites)
set -uo pipefail

PART="${1:-all}"
LIST="$HOME/.patopro-sites"
if [[ ! -s "$LIST" ]]; then
  # First run: every folder in the home directory that holds a PatoPro copy.
  for d in "$HOME"/*/; do
    [[ -f "$d/deploy.sh" && -f "$d/backend/artisan" ]] && echo "${d%/}" >> "$LIST"
  done
fi

ok=(); failed=()
while IFS= read -r dir; do
  [[ -z "$dir" || ! -x "$dir/deploy.sh" ]] && continue
  echo
  if "$dir/deploy.sh" "$PART"; then ok+=("$(basename "$dir")"); else failed+=("$(basename "$dir")"); fi
done < "$LIST"

echo
echo "==> Updated: ${ok[*]:-none}"
if (( ${#failed[@]} )); then
  echo "!! Failed:  ${failed[*]}  (scroll up for the reason)"
  exit 1
fi
echo "   Press RESTART for each website in DirectAdmin → Setup Node.js App if a site still shows the old version."
