#!/usr/bin/env bash
# PatoPro: build the website on THIS computer and publish the ready build to GitHub
# (branch "web-build"). The server only downloads and runs it, because its Linux is
# too old to build Next.js 16 itself.
#
# Usage (on your PC, from the project folder):   ./publish-web.sh
# Then on the server:                              cd ~/patopro && ./deploy.sh frontend
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
BRANCH="web-build"
REMOTE_URL="$(git -C "$ROOT" remote get-url origin)"
COMMIT="$(git -C "$ROOT" rev-parse --short HEAD)"

if [[ -n "$(git -C "$ROOT" status --porcelain -- frontend)" ]]; then
  echo "!! You have uncommitted changes in frontend/. Commit and push them first, so the"
  echo "   live website always matches the code on GitHub."
  exit 1
fi

node -e 'const [a,b]=process.versions.node.split(".").map(Number); if(a<20||(a===20&&b<9)){console.error("Node 20.9 or newer is needed (you have "+process.version+")");process.exit(1)}'

echo "==> Building the website (Node $(node -v))"
cd "$ROOT/frontend"
npm ci --no-audit --no-fund
npm run build

echo "==> Packing the build"
OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT
cp -a .next/standalone/. "$OUT/"
mkdir -p "$OUT/.next"
cp -a .next/static "$OUT/.next/static"
cp -a public "$OUT/public"
rm -f "$OUT/.env" "$OUT/.env.local" "$OUT/.env.production"   # settings live on the server, never in the build
rm -rf "$OUT/node_modules/sharp" "$OUT/node_modules/@img"      # image resizer (~45 MB): PatoPro does not use next/image
cat > "$OUT/BUILD_INFO.txt" <<EOF
PatoPro website build
Source commit: $COMMIT
Built: $(date '+%Y-%m-%d %H:%M %Z') with Node $(node -v)
EOF

echo "==> Uploading to GitHub (branch $BRANCH)"
cd "$OUT"
git init -q -b "$BRANCH"
git add -A
git -c user.name="PatoPro build" -c user.email="build@pptimber.co.tz" commit -q -m "Website build from $COMMIT"
git push -q -f "$REMOTE_URL" "$BRANCH"

echo "==> Done. Now on the server run:  cd ~/patopro && ./deploy.sh frontend"
