#!/usr/bin/env bash
# PatoPro: update the live server from GitHub.
# Usage (on the server):  cd ~/patopro && ./deploy.sh          (backend + website)
#                         ./deploy.sh backend   |   ./deploy.sh frontend
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PART="${1:-all}"
PHP="${PHP:-php}"            # e.g. PHP=/usr/local/php83/bin/php ./deploy.sh
COMPOSER="${COMPOSER:-composer}"

echo "==> Getting the latest code"
cd "$ROOT"
git pull --ff-only

if [[ "$PART" == "all" || "$PART" == "backend" ]]; then
  echo "==> Backend (Laravel)"
  cd "$ROOT/backend"
  $PHP artisan down --retry=15 || true
  $COMPOSER install --no-dev --optimize-autoloader --no-interaction
  $PHP artisan migrate --force
  $PHP artisan mauzo:sync-permissions
  $PHP artisan storage:link 2>/dev/null || true
  $PHP artisan optimize:clear
  $PHP artisan config:cache
  $PHP artisan route:cache
  $PHP artisan view:cache
  $PHP artisan up
fi

if [[ "$PART" == "all" || "$PART" == "frontend" ]]; then
  echo "==> Website (Next.js)"
  if [[ "${BUILD_ON_SERVER:-0}" == "1" ]]; then
    # Only for servers new enough to build Next.js 16 (glibc 2.29+).
    cd "$ROOT/frontend"
    VENV="$(ls -d "$HOME"/nodevenv/patopro/frontend/*/bin/activate 2>/dev/null | sort -V | tail -1 || true)"
    if [[ -n "$VENV" ]]; then set +u; source "$VENV"; set -u; fi
    npm install --no-audit --no-fund
    npm run build
    rm -rf .next/standalone/.next/static .next/standalone/public
    cp -r .next/static .next/standalone/.next/static
    cp -r public .next/standalone/public
  else
    # The build is made on a PC with ./publish-web.sh and kept on the "web-build" branch.
    WEB="$HOME/patopro-web"
    URL="$(git -C "$ROOT" remote get-url origin)"
    if [[ ! -d "$WEB/.git" ]]; then
      git clone -q --depth 1 --branch web-build "$URL" "$WEB"
    else
      git -C "$WEB" fetch -q --depth 1 origin web-build
      git -C "$WEB" reset -q --hard FETCH_HEAD
      git -C "$WEB" clean -qfd
    fi
    echo "   $(grep -h 'Source commit\|Built' "$WEB/BUILD_INFO.txt" | tr '\n' ' ')"
  fi
  cd "$ROOT/frontend"
  if command -v pm2 >/dev/null 2>&1 && pm2 describe patopro-web >/dev/null 2>&1; then
    pm2 restart patopro-web --update-env
  else
    # DirectAdmin "Setup Node.js App" (Passenger) restarts on this file
    mkdir -p tmp && touch tmp/restart.txt
    echo "   If the site did not restart, press RESTART in DirectAdmin → Setup Node.js App."
  fi
fi

echo "==> Done: $(git -C "$ROOT" log -1 --format='%h %s')"
