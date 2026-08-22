#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/api"
FRONTEND_DIR="$ROOT_DIR/frontend"
MAIL_FETCHER_DIR="$ROOT_DIR/mail-fetcher"

PHP_BIN="${PHP_BIN:-php}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_CONFIRM="${DEPLOY_CONFIRM:-}"
SKIP_PULL="${SKIP_PULL:-0}"
SKIP_TESTS="${SKIP_TESTS:-0}"
RUN_INTEGRATION_TESTS="${RUN_INTEGRATION_TESTS:-1}"
SKIP_FRONTEND_BUILD="${SKIP_FRONTEND_BUILD:-0}"
INSTALL_MAIL_FETCHER="${INSTALL_MAIL_FETCHER:-0}"
BACKUP_COMMAND="${BACKUP_COMMAND:-}"
DUMP_ENV="${DUMP_ENV:-0}"

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

prepare_php_path_for_ovh() {
  if [ -x /usr/local/php8.4/bin/php ]; then
    export PATH="/usr/local/php8.4/bin:$PATH"
    PHP_BIN="${PHP_BIN:-/usr/local/php8.4/bin/php}"
  fi
}

confirm_production_deploy() {
  if [ "$DEPLOY_CONFIRM" = "production" ]; then
    return
  fi

  printf "Deploiement PRODUCTION OVH mutualise.\n"
  printf "Ce script va installer, tester, builder, migrer la BDD et vider le cache prod.\n"
  printf "Tape exactement 'production' pour continuer: "
  read -r answer
  [ "$answer" = "production" ] || die "deploiement annule"
}

prepare_runtime_dirs() {
  log "Preparation des dossiers ecriture Symfony"
  cd "$API_DIR"
  mkdir -p var/cache var/log var/site_files
  chmod -R u+rwX var/cache var/log var/site_files >/dev/null 2>&1 || true
  rm -rf var/cache/prod
}

install_api_with_dev_deps_for_tests() {
  log "Installation API avec dependances de test"
  cd "$API_DIR"
  composer install --prefer-dist --no-interaction --optimize-autoloader
}

run_api_tests() {
  if [ "$SKIP_TESTS" = "1" ]; then
    warn "tests ignores via SKIP_TESTS=1"
    return
  fi

  log "Tests unitaires API"
  cd "$API_DIR"
  APP_ENV=test APP_DEBUG=1 "$PHP_BIN" vendor/bin/phpunit --testsuite unit

  if [ "$RUN_INTEGRATION_TESTS" = "1" ]; then
    log "Tests integration API"
    APP_ENV=test APP_DEBUG=1 "$PHP_BIN" vendor/bin/phpunit --testsuite integration
  fi
}

build_frontend() {
  if [ "$SKIP_FRONTEND_BUILD" = "1" ]; then
    warn "build frontend ignore via SKIP_FRONTEND_BUILD=1"
    return
  fi

  log "Build frontend production"
  cd "$FRONTEND_DIR"
  if [ -f package-lock.json ]; then
    npm ci
  else
    npm install
  fi
  npm run build
}

install_api_prod_deps() {
  log "Installation API production sans dependances de dev"
  cd "$API_DIR"
  APP_ENV=prod APP_DEBUG=0 composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader \
    --classmap-authoritative
}

backup_before_migration() {
  if [ -n "$BACKUP_COMMAND" ]; then
    log "Sauvegarde avant migration"
    bash -lc "$BACKUP_COMMAND"
  else
    warn "aucune sauvegarde automatique configuree. Option: BACKUP_COMMAND='mysqldump ... > backup.sql' ./deploy.sh"
  fi
}

run_prod_migrations_and_cache() {
  log "Verification Doctrine avant migration"
  cd "$API_DIR"
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console doctrine:migrations:status --no-interaction

  backup_before_migration

  log "Migration BDD production"
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction

  if [ "$DUMP_ENV" = "1" ]; then
    log "Dump environnement Symfony prod"
    APP_ENV=prod APP_DEBUG=0 composer dump-env prod
  fi

  log "Cache Symfony production"
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console cache:clear --env=prod --no-debug
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console cache:warmup --env=prod --no-debug
}

install_mail_fetcher_deps() {
  if [ "$INSTALL_MAIL_FETCHER" != "1" ]; then
    warn "mail-fetcher non lance sur mutualise. Prevoir une tache cron separee si necessaire."
    return
  fi

  log "Installation dependances mail-fetcher pour cron"
  cd "$MAIL_FETCHER_DIR"
  if [ -f package-lock.json ]; then
    npm ci --omit=dev
  else
    npm install --omit=dev
  fi
}

final_checks() {
  log "Verifications finales"
  cd "$API_DIR"
  APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" bin/console about --env=prod --no-debug

  if [ ! -f "$ROOT_DIR/.ovhconfig" ]; then
    warn ".ovhconfig absent a la racine. Sur OVH mutualise, prevoir app.engine.version=8.4 et environment=production."
  fi

  if [ ! -f "$API_DIR/public/dist/index.html" ]; then
    warn "build frontend introuvable dans api/public/dist/index.html"
  fi

  printf "\nDeploiement termine.\n"
  printf "Point d'entree web conseille sur OVH: %s\n" "$API_DIR/public"
  printf "Test API attendu: https://votre-domaine.fr/api/health\n"
}

confirm_production_deploy
prepare_php_path_for_ovh

log "Verification des prerequis"
need_cmd "$PHP_BIN"
need_cmd composer
if [ "$SKIP_PULL" != "1" ]; then
  need_cmd git
fi
if [ "$SKIP_FRONTEND_BUILD" != "1" ]; then
  need_cmd node
  need_cmd npm
fi

[ -d "$API_DIR" ] || die "dossier api/ introuvable"
[ -d "$FRONTEND_DIR" ] || die "dossier frontend/ introuvable"

if [ "$SKIP_PULL" != "1" ]; then
  log "Mise a jour du code"
  cd "$ROOT_DIR"
  git fetch --prune
  git checkout "$DEPLOY_BRANCH"
  git pull --ff-only origin "$DEPLOY_BRANCH"
else
  warn "git pull ignore via SKIP_PULL=1"
fi

prepare_runtime_dirs
install_api_with_dev_deps_for_tests
build_frontend
run_api_tests
install_api_prod_deps
run_prod_migrations_and_cache
install_mail_fetcher_deps
final_checks
