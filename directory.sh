#!/usr/bin/env bash
# PatoPro business codes: the list the mobile app reads to find each business's server.
# It is one small file on shezoni.com:  https://shezoni.com/patopro/directory.json
#
# Usage (on the shezoni.com server):
#   ./directory.sh list
#   ./directory.sh add dablos "Dablos" https://api-dablos.shezoni.com
#   ./directory.sh add pptimber "PP Timber" https://api.pptimber.co.tz
#   ./directory.sh remove dablos
# DIRECTORY_FILE=/other/path/directory.json ./directory.sh ...   to use another place.
set -euo pipefail

FILE="${DIRECTORY_FILE:-$HOME/domains/shezoni.com/public_html/patopro/directory.json}"
PHP="${PHP:-php}"
CMD="${1:-list}"

mkdir -p "$(dirname "$FILE")"
[[ -f "$FILE" ]] || echo '{"businesses":{}}' > "$FILE"
# Always the newest list for phones (no caching), readable by anyone.
HT="$(dirname "$FILE")/.htaccess"
if [[ ! -f "$HT" ]]; then
  cat > "$HT" <<'HTA'
<Files "directory.json">
  Header set Cache-Control "no-cache, max-age=0"
  Header set Access-Control-Allow-Origin "*"
</Files>
Options -Indexes
HTA
fi

case "$CMD" in
  list)
    FILE="$FILE" $PHP -r '$d=json_decode(file_get_contents(getenv("FILE")),true)["businesses"]??[]; if(!$d){echo "(no businesses yet)\n";} foreach($d as $c=>$b){printf("%-16s %-24s %s\n",$c,$b["name"]??"",$b["api"]??"");}'
    ;;
  add)
    CODE="$(echo "${2:-}" | tr 'A-Z' 'a-z')"; NAME="${3:-}"; API="${4:-}"
    if [[ ! "$CODE" =~ ^[a-z0-9][a-z0-9-]{1,30}$ || -z "$NAME" || ! "$API" =~ ^https?:// ]]; then
      echo 'Usage: ./directory.sh add <code> "<Business name>" https://api-address'; exit 1
    fi
    API="${API%/}"; API="${API%/api/v1}"
    if ! curl -fsS --max-time 15 "$API/api/v1/health" >/dev/null; then
      echo "!! $API/api/v1/health does not answer. Check the address (and its SSL) first."; exit 1
    fi
    FILE="$FILE" CODE="$CODE" NAME="$NAME" API="$API" $PHP -r '$f=getenv("FILE"); $d=json_decode(file_get_contents($f),true)?:["businesses"=>[]]; $d["businesses"][getenv("CODE")]=["name"=>getenv("NAME"),"api"=>getenv("API")]; ksort($d["businesses"]); $d["updated_at"]=date("c"); file_put_contents($f.".tmp", json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"); rename($f.".tmp",$f);'
    echo "==> Business code \"$CODE\" → $API"
    ;;
  remove)
    CODE="${2:-}"
    FILE="$FILE" CODE="$CODE" $PHP -r '$f=getenv("FILE"); $d=json_decode(file_get_contents($f),true); unset($d["businesses"][getenv("CODE")]); $d["updated_at"]=date("c"); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");'
    echo "==> Removed \"$CODE\""
    ;;
  *) echo "Usage: ./directory.sh list | add <code> \"<name>\" <api> | remove <code>"; exit 1 ;;
esac
chmod 644 "$FILE"
