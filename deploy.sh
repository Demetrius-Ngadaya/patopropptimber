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
  cd "$ROOT/frontend"
  # DirectAdmin "Setup Node.js App" keeps Node in ~/nodevenv/<app root>/<version>; use it when present.
  VENV="$(ls -d "$HOME"/nodevenv/patopro/frontend/*/bin/activate 2>/dev/null | sort -V | tail -1 || true)"
  if [[ -n "$VENV" ]]; then
    # shellcheck disable=SC1090
    set +u; source "$VENV"; set -u
  fi
  command -v node >/dev/null || { echo "Node.js not found. Create the app in DirectAdmin → Setup Node.js App first."; exit 1; }
  echo "   Node $(node -v)"
  # npm install (not npm ci): on CloudLinux node_modules is a link to the app's Node environment
  npm install --no-audit --no-fund
  NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=1536}" npm run build
  # Standalone output needs the static files and public folder next to server.js
  rm -rf .next/standalone/.next/static .next/standalone/public
  cp -r .next/static .next/standalone/.next/static
  cp -r public .next/standalone/public
  if command -v pm2 >/dev/null 2>&1 && pm2 describe patopro-web >/dev/null 2>&1; then
    pm2 restart patopro-web --update-env
  else
    # DirectAdmin "Setup Node.js App" (Passenger) restarts on this file
    mkdir -p tmp && touch tmp/restart.txt
    echo "   If the site did not restart, press RESTART in DirectAdmin → Setup Node.js App."
  fi
fi

echo "==> Done: $(git -C "$ROOT" log -1 --format='%h %s')"
