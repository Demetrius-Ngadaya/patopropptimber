#!/usr/bin/env bash
# PatoPro: update ONE business's live system from GitHub.
#
# Every business has its own copy of the same repository in its own folder
# (e.g. ~/patopro for PP Timber, ~/dablos for Dablos), with its own backend/.env,
# frontend/.env and database. This script updates the copy it sits in.
#
# Usage (on the server, inside the business's folder):
#   ./deploy.sh              backend + website
#   ./deploy.sh backend      only the Laravel API
#   ./deploy.sh frontend     only the website
# All businesses on this server at once:  ./update-all.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PART="${1:-all}"
PHP="${PHP:-php}"            # e.g. PHP=/opt/alt/php84/usr/bin/php ./deploy.sh
COMPOSER="${COMPOSER:-composer}"
# The ready website build lives next to the folder: ~/patopro → ~/patopro-web, ~/dablos → ~/dablos-web.
WEB="${PATOPRO_WEB_DIR:-$ROOT-web}"
NAME="$(basename "$ROOT")"

echo "==> [$NAME] Getting the latest code"
cd "$ROOT"
git pull --ff-only

if [[ "$PART" == "all" || "$PART" == "backend" ]]; then
  echo "==> [$NAME] Backend (Laravel)"
  cd "$ROOT/backend"
  if [[ ! -f .env ]]; then
    echo "!! backend/.env is missing. Set up this business first with ./new-business.sh"
    exit 1
  fi
  $PHP artisan down --retry=15 || true
  $COMPOSER install --no-dev --optimize-autoloader --no-interaction
  if grep -qE '^PATOPRO_MODE=platform' .env; then
    # Many businesses: central tables, then every business's own database.
    $PHP artisan platform:migrate
    $PHP artisan platform:run mauzo:sync-permissions
    $PHP artisan platform:run mauzo:optimize-slides
  else
    $PHP artisan migrate --force
    $PHP artisan mauzo:sync-permissions
    $PHP artisan mauzo:optimize-slides
  fi
  $PHP artisan storage:link 2>/dev/null || true
  $PHP artisan optimize:clear
  $PHP artisan config:cache
  $PHP artisan route:cache
  $PHP artisan view:cache
  $PHP artisan up
fi

if [[ "$PART" == "all" || "$PART" == "frontend" ]]; then
  echo "==> [$NAME] Website (Next.js)"
  if [[ "${BUILD_ON_SERVER:-0}" == "1" ]]; then
    # Only for servers new enough to build Next.js 16 (glibc 2.29+).
    cd "$ROOT/frontend"
    VENV="$(ls -d "$HOME"/nodevenv/"${ROOT#"$HOME"/}"/frontend/*/bin/activate 2>/dev/null | sort -V | tail -1 || true)"
    if [[ -n "$VENV" ]]; then set +u; source "$VENV"; set -u; fi
    npm install --no-audit --no-fund
    npm run build
    rm -rf .next/standalone/.next/static .next/standalone/public
    cp -r .next/static .next/standalone/.next/static
    cp -r public .next/standalone/public
  else
    # The build is made on a PC with ./publish-web.sh and kept on the "web-build" branch.
    # The same build serves every business: their addresses come from frontend/.env.
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
  if [[ ! -f "$ROOT/frontend/.env" ]]; then
    echo "!! frontend/.env is missing (API_URL, APP_URL). Set up this business first with ./new-business.sh"
  fi
  cd "$ROOT/frontend"
  if command -v pm2 >/dev/null 2>&1 && pm2 describe "$NAME-web" >/dev/null 2>&1; then
    pm2 restart "$NAME-web" --update-env
  else
    # DirectAdmin "Setup Node.js App" (Passenger) restarts on this file
    mkdir -p tmp && touch tmp/restart.txt
    echo "   If the site did not restart, press RESTART in DirectAdmin → Setup Node.js App ($NAME/frontend)."
  fi
fi

echo "==> [$NAME] Done: $(git -C "$ROOT" log -1 --format='%h %s')"
