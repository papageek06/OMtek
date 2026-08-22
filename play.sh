#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/api"
FRONTEND_DIR="$ROOT_DIR/frontend"
MAIL_FETCHER_DIR="$ROOT_DIR/mail-fetcher"

API_HOST="${API_HOST:-127.0.0.1}"
API_PORT="${API_PORT:-8000}"
FRONTEND_HOST="${FRONTEND_HOST:-127.0.0.1}"
FRONTEND_PORT="${FRONTEND_PORT:-5173}"
RUN_MAIL_FETCHER="${RUN_MAIL_FETCHER:-0}"
API_URL=""

API_PID=""
FRONTEND_PID=""
MAIL_FETCHER_PID=""

log() {
  printf "\n\033[1;36m==> %s\033[0m\n" "$1"
}

warn() {
  printf "\033[1;33mAttention:\033[0m %s\n" "$1"
}

die() {
  printf "\033[1;31mErreur:\033[0m %s\n" "$1" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "commande introuvable: $1"
}

clear_windows_readonly_attr() {
  local target="$1"

  if command -v attrib.exe >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1 && [ -e "$target" ]; then
    attrib.exe -R "$(cygpath -w "$target")" /S /D >/dev/null 2>&1 || true
  fi
}

prepare_symfony_runtime_dirs() {
  log "Preparation des dossiers runtime Symfony"
  cd "$API_DIR"

  clear_windows_readonly_attr var
  clear_windows_readonly_attr var/cache
  clear_windows_readonly_attr var/log

  if [ -d var/cache ]; then
    chmod -R u+rwX var/cache >/dev/null 2>&1 || true
    rm -rf var/cache/dev var/cache/prod var/cache/test
  fi

  mkdir -p var/cache var/log
  chmod -R u+rwX var/cache var/log >/dev/null 2>&1 || true
}

cleanup() {
  log "Arret des services locaux"
  for pid in "$MAIL_FETCHER_PID" "$FRONTEND_PID" "$API_PID"; do
    if [ -n "${pid:-}" ] && kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
    fi
  done
}
trap cleanup EXIT INT TERM

log "Verification des prerequis"
need_cmd git
need_cmd php
need_cmd composer
need_cmd node
need_cmd npm

[ -d "$API_DIR" ] || die "dossier api/ introuvable"
[ -d "$FRONTEND_DIR" ] || die "dossier frontend/ introuvable"
[ -d "$MAIL_FETCHER_DIR" ] || warn "dossier mail-fetcher/ introuvable"

log "Mise a jour du depot"
cd "$ROOT_DIR"
git pull --ff-only

prepare_symfony_runtime_dirs

log "Installation des dependances API"
cd "$API_DIR"
composer install

log "Preparation de la base de donnees"
php bin/console doctrine:database:create --if-not-exists || warn "creation BDD ignoree: verifie que MySQL est lance et que DATABASE_URL est correcte"
php bin/console doctrine:migrations:migrate --no-interaction

if [ -n "${BOOTSTRAP_ADMIN_EMAIL:-}" ] && [ -n "${BOOTSTRAP_ADMIN_PASSWORD:-}" ]; then
  log "Creation/mise a jour du compte admin local"
  php bin/console app:user:bootstrap-admin \
    --email="$BOOTSTRAP_ADMIN_EMAIL" \
    --password="$BOOTSTRAP_ADMIN_PASSWORD"
else
  warn "admin non initialise. Optionnel: BOOTSTRAP_ADMIN_EMAIL=admin@local.test BOOTSTRAP_ADMIN_PASSWORD='MotDePasseFort!' ./play.sh"
fi

log "Installation des dependances frontend"
cd "$FRONTEND_DIR"
if [ -f package-lock.json ]; then
  npm ci
else
  npm install
fi

if [ -d "$MAIL_FETCHER_DIR" ]; then
  log "Installation des dependances mail-fetcher"
  cd "$MAIL_FETCHER_DIR"
  if [ -f package-lock.json ]; then
    npm ci
  else
    npm install
  fi
fi

log "Lancement API Symfony"
cd "$API_DIR"
if command -v symfony >/dev/null 2>&1; then
  API_URL="http://$API_HOST:$API_PORT"
  symfony server:stop >/dev/null 2>&1 || true
  symfony serve --no-tls --allow-http --port="$API_PORT" --listen-ip="$API_HOST" &
else
  API_URL="http://$API_HOST:$API_PORT"
  warn "CLI Symfony introuvable, fallback sur php -S"
  php -S "$API_HOST:$API_PORT" -t public public/index.php &
fi
API_PID="$!"

log "Lancement frontend Vite: http://localhost:$FRONTEND_PORT"
cd "$FRONTEND_DIR"
VITE_PROXY_TARGET="$API_URL" npm run dev -- --host "$FRONTEND_HOST" --port "$FRONTEND_PORT" &
FRONTEND_PID="$!"

if [ "$RUN_MAIL_FETCHER" = "1" ] && [ -d "$MAIL_FETCHER_DIR" ]; then
  log "Lancement du mail-fetcher principal"
  cd "$MAIL_FETCHER_DIR"
  npm run reception:principale &
  MAIL_FETCHER_PID="$!"
else
  warn "mail-fetcher non lance. Utilise RUN_MAIL_FETCHER=1 ./play.sh pour lancer aussi la reception principale."
fi

log "Projet pret en localhost"
printf "API      : %s\n" "$API_URL"
printf "Frontend : http://localhost:%s\n" "$FRONTEND_PORT"
printf "Stop     : Ctrl+C\n"

wait "$API_PID" "$FRONTEND_PID"
