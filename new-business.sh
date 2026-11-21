#!/usr/bin/env bash
# PatoPro: set up ONE MORE business on this server from the same GitHub repository.
#
# Each business gets its own folder (a copy of the repository), its own database and
# its own settings, so one "git push" from your PC updates them all
# (then ./update-all.sh on the server).
#
# Before running it, in DirectAdmin:
#   1. Create the website subdomain   (e.g. dablos.shezoni.com)  and its API subdomain
#      (e.g. api-dablos.shezoni.com), each with SSL (Let's Encrypt).
#   2. Create a MySQL database + user for the business (MySQL Management).
#
# Usage (on the server, from any PatoPro folder, e.g. ~/patopro):
#   ./new-business.sh dablos
# It asks for the addresses, database and first admin, then prepares everything.
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
NAME="${1:-}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"

if [[ -z "$NAME" || ! "$NAME" =~ ^[a-z0-9][a-z0-9-]{1,30}$ ]]; then
  echo "Usage: ./new-business.sh <folder-name>     e.g. ./new-business.sh dablos"
  echo "       (lower-case letters, numbers and - only)"
  exit 1
fi
DEST="$HOME/$NAME"
if [[ -e "$DEST" ]]; then
  echo "!! $DEST already exists. To update it run:  cd $DEST && ./deploy.sh"
  exit 1
fi

ask() { # ask "Question" default -> answer
  local answer
  read -r -p "$1${2:+ [$2]}: " answer
  echo "${answer:-$2}"
}
secret() {
  local answer
  read -r -s -p "$1: " answer
  echo >&2
  echo "$answer"
}

echo "==> New PatoPro business: $NAME"
WEB_DOMAIN="$(ask "Website address (no https://)" "$NAME.shezoni.com")"
API_DOMAIN="$(ask "API address (no https://)" "api-${WEB_DOMAIN}")"
DB_NAME="$(ask "Database name (created in DirectAdmin)" "$(whoami)_$NAME")"
DB_USER="$(ask "Database user" "$DB_NAME")"
DB_PASS="$(secret "Database password")"
if [[ "$DB_PASS" == *'"'* ]]; then echo "!! Please use a database password without the \" character."; exit 1; fi
ADMIN_EMAIL="$(ask "Email of the business's first admin (optional)" "")"
ADMIN_PASS="$(secret "Password for the 'admin' login (empty = a random one is shown once)")"
MAIL_FROM="$(ask "Address emails are sent from" "no-reply@$WEB_DOMAIN")"

for d in "$WEB_DOMAIN" "$API_DOMAIN"; do
  if [[ ! -d "$HOME/domains/$d" ]]; then
    echo "!! $HOME/domains/$d does not exist. Create the subdomain $d in DirectAdmin first (Subdomain Management), then run this again."
    exit 1
  fi
done

echo "==> Checking the database connection"
if ! DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" $PHP -r 'try { new PDO("mysql:host=127.0.0.1;dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS")); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }'; then
  echo "!! Cannot open database $DB_NAME as $DB_USER. Check the name, user and password in DirectAdmin → MySQL Management."
  exit 1
fi

echo "==> Copying the code (same GitHub repository)"
git clone -q "$(git -C "$SRC" remote get-url origin)" "$DEST"
git -C "$DEST" config core.fileMode false
chmod +x "$DEST"/*.sh

echo "==> Backend settings (backend/.env)"
ENV="$DEST/backend/.env"
cp "$DEST/backend/.env.example" "$ENV"
chmod 600 "$ENV"
set_env() { # set_env KEY VALUE
  local key="$1" value="$2"
  value="${value//\\/\\\\}"; value="${value//|/\\|}"; value="${value//&/\\&}"
  if grep -q "^${key}=" "$ENV"; then sed -i "s|^${key}=.*|${key}=${value}|" "$ENV"; else echo "${key}=${value}" >> "$ENV"; fi
}
set_env APP_URL "https://$API_DOMAIN"
set_env FRONTEND_URL "https://$WEB_DOMAIN"
set_env CORS_ALLOWED_ORIGINS "https://$WEB_DOMAIN"
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "\"$DB_PASS\""
set_env MAIL_FROM_ADDRESS "$MAIL_FROM"
set_env SEED_ADMIN_EMAIL "$ADMIN_EMAIL"
set_env SEED_ADMIN_PASSWORD "${ADMIN_PASS:+\"$ADMIN_PASS\"}"
# The AI key can be shared between businesses (it is your Groq account).
if [[ -f "$SRC/backend/.env" ]] && grep -q '^GROQ_API_KEY=.\+' "$SRC/backend/.env"; then
  if [[ "$(ask "Use the same AI (Groq) key as $(basename "$SRC")? y/n" "y")" == "y" ]]; then
    set_env GROQ_API_KEY "$(grep '^GROQ_API_KEY=' "$SRC/backend/.env" | cut -d= -f2-)"
  fi
fi

echo "==> Installing the backend"
cd "$DEST/backend"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction
$PHP artisan key:generate --force
$PHP artisan migrate --force --seed
$PHP artisan mauzo:sync-permissions
$PHP artisan storage:link 2>/dev/null || true
# The admin password should not stay in the settings file.
sed -i 's|^SEED_ADMIN_PASSWORD=.*|SEED_ADMIN_PASSWORD=|' "$ENV"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "==> Pointing https://$API_DOMAIN at the API"
PUB="$HOME/domains/$API_DOMAIN/public_html"
if [[ -e "$PUB" && ! -L "$PUB" ]]; then mv "$PUB" "$PUB.before-patopro"; fi
ln -sfn "$DEST/backend/public" "$PUB"

echo "==> Website settings (frontend/.env)"
cat > "$DEST/frontend/.env" <<EOF
API_URL=https://$API_DOMAIN/api/v1
APP_URL=https://$WEB_DOMAIN
COOKIE_SECURE=true
EOF
chmod 600 "$DEST/frontend/.env"

echo "==> Downloading the website build"
cd "$DEST"
./deploy.sh frontend >/dev/null

echo "==> Scheduled jobs (reports, reminders, backups) every minute"
PHPBIN="$(command -v "$PHP")"
[[ -x /opt/alt/php84/usr/bin/php ]] && PHPBIN=/opt/alt/php84/usr/bin/php
LINE="* * * * * cd $DEST/backend && $PHPBIN artisan schedule:run >> $DEST/backend/storage/logs/cron.log 2>&1"
( crontab -l 2>/dev/null | grep -vF "cd $DEST/backend && " ; echo "$LINE" ) | crontab -

echo "==> Adding $NAME to the list for ./update-all.sh"
touch "$HOME/.patopro-sites"
grep -qxF "$DEST" "$HOME/.patopro-sites" || echo "$DEST" >> "$HOME/.patopro-sites"
grep -qxF "$SRC" "$HOME/.patopro-sites" || echo "$SRC" >> "$HOME/.patopro-sites"

echo "==> Checking the API"
sleep 1
curl -fsS "https://$API_DOMAIN/api/v1/health" && echo || echo "   (The API did not answer yet: check the SSL certificate of $API_DOMAIN.)"

cat <<EOF

==> Almost done. Last step in DirectAdmin → Setup Node.js App → CREATE APPLICATION:
      Node.js version:        24
      Application mode:       Production
      Application root:       $NAME/frontend
      Application URL:        $WEB_DOMAIN
      Application startup file: app.js
    Press CREATE, then RESTART. Open https://$WEB_DOMAIN and sign in as  admin.

    Then, as the Software Engineer, create your hidden account for this business:
      cd $DEST/backend && $PHP artisan mauzo:create-developer <your-username>

    From now on, after "git push" (and ./publish-web.sh) on your PC:
      ~/update-all.sh   (or: cd $DEST && ./deploy.sh)
EOF
