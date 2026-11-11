#!/usr/bin/env bash
# Copie la base MySQL de PRODUCTION dans la base MySQL LOCALE (tests sur donnees reelles).
#
# La prod n'est jamais modifiee: on fait seulement un mysqldump en lecture.
# La base locale est ECRASEE (une sauvegarde locale est faite avant).
#
# 3 facons de recuperer la prod (variable SOURCE):
#   SOURCE=file    : un dump deja telecharge (ex: export phpMyAdmin OVH)      -> DUMP_FILE=chemin.sql(.gz)
#   SOURCE=ssh     : mysqldump lance sur l'hebergement OVH via SSH (conseille) -> SSH_TARGET=user@ssh.clusterXXX.hosting.ovh.net
#   SOURCE=direct  : mysqldump direct vers le serveur MySQL prod (si joignable de l'exterieur)
#
# Exemples:
#   SOURCE=file DUMP_FILE=~/Downloads/omtek.sql.gz ./sync-prod-db.sh
#   SOURCE=ssh SSH_TARGET=moncompte@ssh.cluster021.hosting.ovh.net \
#     PROD_DB_HOST=xxxx.mysql.db PROD_DB_USER=omtek PROD_DB_NAME=omtek ./sync-prod-db.sh
#
# Base locale (par defaut: root sans mot de passe sur localhost, base "omtek", comme phpMyAdmin local):
#   LOCAL_DB_HOST=127.0.0.1 LOCAL_DB_PORT=3306 LOCAL_DB_USER=root LOCAL_DB_NAME=omtek
#   Mot de passe local: LOCAL_DB_PASSWORD (sinon demande, Entree = vide).
#
# Apres import:
#   - les tokens API de connexion prod sont invalides en local (reconnexion necessaire);
#   - KEEP_PROD_TOKENS=1 pour les garder;
#   - les migrations Doctrine du code local sont appliquees (SKIP_MIGRATIONS=1 pour ne pas le faire).
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/api"
DUMP_DIR="$API_DIR/var/db-dumps"   # api/var/ est ignore par git: les dumps ne sont jamais commites.
PHP_BIN="${PHP_BIN:-php}"

SOURCE="${SOURCE:-}"
DUMP_FILE="${DUMP_FILE:-}"
SSH_TARGET="${SSH_TARGET:-}"
PROD_DB_HOST="${PROD_DB_HOST:-}"
PROD_DB_PORT="${PROD_DB_PORT:-3306}"
PROD_DB_USER="${PROD_DB_USER:-}"
PROD_DB_NAME="${PROD_DB_NAME:-}"
PROD_DB_PASSWORD="${PROD_DB_PASSWORD:-}"

LOCAL_DB_HOST="${LOCAL_DB_HOST:-127.0.0.1}"
LOCAL_DB_PORT="${LOCAL_DB_PORT:-3306}"
LOCAL_DB_USER="${LOCAL_DB_USER:-root}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-omtek}"
LOCAL_DB_PASSWORD="${LOCAL_DB_PASSWORD-__ASK__}"

KEEP_PROD_TOKENS="${KEEP_PROD_TOKENS:-0}"
SKIP_MIGRATIONS="${SKIP_MIGRATIONS:-0}"
SYNC_CONFIRM="${SYNC_CONFIRM:-}"

log()  { printf "\n\033[1;36m==> %s\033[0m\n" "$1"; }
warn() { printf "\033[1;33mAttention:\033[0m %s\n" "$1"; }
die()  { printf "\033[1;31mErreur:\033[0m %s\n" "$1" >&2; exit 1; }
need_cmd() { command -v "$1" >/dev/null 2>&1 || die "commande introuvable: $1"; }

TMP_FILES=()
cleanup() { for f in "${TMP_FILES[@]:-}"; do [ -n "$f" ] && rm -f "$f"; done; }
trap cleanup EXIT

# Fichier d'options MySQL temporaire (chmod 600): le mot de passe n'apparait jamais dans la ligne de commande.
make_cnf() {
  local host="$1" port="$2" user="$3" password="$4" file
  file="$(mktemp)"
  chmod 600 "$file"
  TMP_FILES+=("$file")
  {
    printf '[client]\n'
    printf 'host=%s\nport=%s\nuser=%s\n' "$host" "$port" "$user"
    [ -n "$password" ] && printf 'password="%s"\n' "${password//\"/\\\"}"
  } > "$file"
  printf '%s' "$file"
}

ask_secret() {
  local prompt="$1" value
  read -r -s -p "$prompt" value
  printf '\n' >&2
  printf '%s' "$value"
}

# ---------------------------------------------------------------------------
# 1. Garde-fous base locale
# ---------------------------------------------------------------------------
case "$LOCAL_DB_HOST" in
  127.0.0.1|localhost|::1) ;;
  *) die "LOCAL_DB_HOST=$LOCAL_DB_HOST n'est pas local: refus d'ecraser une base distante." ;;
esac
[ -n "$PROD_DB_HOST" ] && [ "$PROD_DB_HOST" = "$LOCAL_DB_HOST" ] && [ "$PROD_DB_NAME" = "$LOCAL_DB_NAME" ] \
  && die "source et destination identiques."

need_cmd mysql
need_cmd gzip

if [ "$LOCAL_DB_PASSWORD" = "__ASK__" ]; then
  LOCAL_DB_PASSWORD="$(ask_secret "Mot de passe MySQL local ($LOCAL_DB_USER@$LOCAL_DB_HOST, Entree = vide): ")"
fi
LOCAL_CNF="$(make_cnf "$LOCAL_DB_HOST" "$LOCAL_DB_PORT" "$LOCAL_DB_USER" "$LOCAL_DB_PASSWORD")"
mysql --defaults-extra-file="$LOCAL_CNF" -e 'SELECT 1' >/dev/null 2>&1 \
  || die "connexion MySQL locale impossible ($LOCAL_DB_USER@$LOCAL_DB_HOST:$LOCAL_DB_PORT)."

mkdir -p "$DUMP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"

# ---------------------------------------------------------------------------
# 2. Recuperation du dump prod
# ---------------------------------------------------------------------------
if [ -z "$SOURCE" ]; then
  if [ -n "$DUMP_FILE" ]; then SOURCE=file; elif [ -n "$SSH_TARGET" ]; then SOURCE=ssh; else SOURCE=direct; fi
fi

DUMP_OPTS=(--single-transaction --quick --no-tablespaces --triggers --default-character-set=utf8mb4)
PROD_DUMP="$DUMP_DIR/prod-$STAMP.sql.gz"

case "$SOURCE" in
  file)
    [ -f "$DUMP_FILE" ] || die "DUMP_FILE introuvable: $DUMP_FILE"
    log "Utilisation du dump existant: $DUMP_FILE"
    if [[ "$DUMP_FILE" == *.gz ]]; then cp "$DUMP_FILE" "$PROD_DUMP"; else gzip -c "$DUMP_FILE" > "$PROD_DUMP"; fi
    ;;
  ssh|direct)
    [ -n "$PROD_DB_HOST" ] && [ -n "$PROD_DB_USER" ] && [ -n "$PROD_DB_NAME" ] \
      || die "PROD_DB_HOST, PROD_DB_USER et PROD_DB_NAME sont requis."
    [ -n "$PROD_DB_PASSWORD" ] || PROD_DB_PASSWORD="$(ask_secret "Mot de passe MySQL PROD ($PROD_DB_USER@$PROD_DB_HOST): ")"
    PROD_CNF="$(make_cnf "$PROD_DB_HOST" "$PROD_DB_PORT" "$PROD_DB_USER" "$PROD_DB_PASSWORD")"

    if [ "$SOURCE" = "ssh" ]; then
      need_cmd ssh
      [ -n "$SSH_TARGET" ] || die "SSH_TARGET requis pour SOURCE=ssh."
      log "Dump prod via SSH ($SSH_TARGET) - lecture seule"
      # Le fichier d'options est envoye par stdin, utilise puis supprime cote serveur.
      REMOTE_CNF=".omtek-dump-$STAMP.cnf"
      ssh "$SSH_TARGET" "umask 077 && cat > '$REMOTE_CNF'" < "$PROD_CNF"
      if ! ssh "$SSH_TARGET" "mysqldump --defaults-extra-file='$REMOTE_CNF' ${DUMP_OPTS[*]} '$PROD_DB_NAME' | gzip -c; rc=\${PIPESTATUS[0]}; rm -f '$REMOTE_CNF'; exit \$rc" > "$PROD_DUMP"; then
        ssh "$SSH_TARGET" "rm -f '$REMOTE_CNF'" || true
        rm -f "$PROD_DUMP"
        die "echec du mysqldump distant."
      fi
    else
      need_cmd mysqldump
      log "Dump prod direct ($PROD_DB_HOST) - lecture seule"
      mysqldump --defaults-extra-file="$PROD_CNF" "${DUMP_OPTS[@]}" "$PROD_DB_NAME" | gzip -c > "$PROD_DUMP" \
        || { rm -f "$PROD_DUMP"; die "echec du mysqldump."; }
    fi
    ;;
  *) die "SOURCE inconnue: $SOURCE (file|ssh|direct)" ;;
esac

gzip -t "$PROD_DUMP" || die "dump corrompu: $PROD_DUMP"
gzip -dc "$PROD_DUMP" | grep -q -m1 -i 'CREATE TABLE' || die "le dump ne contient aucune table: $PROD_DUMP"
log "Dump prod: $PROD_DUMP ($(du -h "$PROD_DUMP" | cut -f1))"

# ---------------------------------------------------------------------------
# 3. Confirmation + sauvegarde de la base locale
# ---------------------------------------------------------------------------
if [ "$SYNC_CONFIRM" != "$LOCAL_DB_NAME" ]; then
  printf "La base LOCALE '%s' sur %s va etre ECRASEE par la prod.\n" "$LOCAL_DB_NAME" "$LOCAL_DB_HOST"
  printf "Tape le nom de la base locale (%s) pour continuer: " "$LOCAL_DB_NAME"
  read -r answer
  [ "$answer" = "$LOCAL_DB_NAME" ] || die "annule."
fi

if mysql --defaults-extra-file="$LOCAL_CNF" -N -e "SHOW DATABASES LIKE '$LOCAL_DB_NAME'" | grep -q .; then
  need_cmd mysqldump
  LOCAL_BACKUP="$DUMP_DIR/local-backup-$STAMP.sql.gz"
  log "Sauvegarde de la base locale actuelle: $LOCAL_BACKUP"
  mysqldump --defaults-extra-file="$LOCAL_CNF" "${DUMP_OPTS[@]}" "$LOCAL_DB_NAME" | gzip -c > "$LOCAL_BACKUP"
fi

# ---------------------------------------------------------------------------
# 4. Import
# ---------------------------------------------------------------------------
log "Recreation de la base locale '$LOCAL_DB_NAME'"
mysql --defaults-extra-file="$LOCAL_CNF" -e \
  "DROP DATABASE IF EXISTS \`$LOCAL_DB_NAME\`; CREATE DATABASE \`$LOCAL_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

log "Import du dump prod"
# Les DEFINER des vues/triggers prod (utilisateur OVH) n'existent pas en local: on les retire.
gzip -dc "$PROD_DUMP" \
  | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' \
  | mysql --defaults-extra-file="$LOCAL_CNF" --default-character-set=utf8mb4 "$LOCAL_DB_NAME"

# ---------------------------------------------------------------------------
# 5. Nettoyage post-import (local uniquement)
# ---------------------------------------------------------------------------
if [ "$KEEP_PROD_TOKENS" != "1" ]; then
  log "Invalidation des tokens API prod en local"
  mysql --defaults-extra-file="$LOCAL_CNF" "$LOCAL_DB_NAME" -e \
    "UPDATE \`user\` SET api_token = NULL, api_token_expires_at = NULL;" \
    || warn "table user absente ou differente: tokens non invalides."
fi

# ---------------------------------------------------------------------------
# 6. Migrations du code local (le code local peut etre en avance sur la prod)
# ---------------------------------------------------------------------------
if [ "$SKIP_MIGRATIONS" != "1" ] && [ -f "$API_DIR/bin/console" ]; then
  log "Migrations Doctrine locales"
  (cd "$API_DIR" && "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration) \
    || warn "migrations en echec: verifie DATABASE_URL dans api/.env.local (doit pointer sur $LOCAL_DB_NAME)."
fi

log "Resume"
mysql --defaults-extra-file="$LOCAL_CNF" "$LOCAL_DB_NAME" -t -e "
  SELECT 'sites' AS tbl, COUNT(*) AS n FROM site
  UNION ALL SELECT 'imprimantes', COUNT(*) FROM imprimante
  UNION ALL SELECT 'rapports', COUNT(*) FROM rapport_imprimante
  UNION ALL SELECT 'alertes', COUNT(*) FROM alerte
  UNION ALL SELECT 'utilisateurs', COUNT(*) FROM \`user\`;" || true

cat <<EOF

Base locale '$LOCAL_DB_NAME' = copie de la prod.
- Dump prod conserve : $PROD_DUMP
${LOCAL_BACKUP:+- Ancienne base locale : $LOCAL_BACKUP (restauration: gzip -dc fichier | mysql $LOCAL_DB_NAME)}
- Connexion: memes comptes/mots de passe qu'en prod.
- Identifiants de sites / coffre: lisibles seulement si APP_CREDENTIAL_ENCRYPTION_KEY local = celle de la prod.
- Pense a MAILER_DSN=null://null en local pour ne pas envoyer de mails aux vrais utilisateurs.
EOF
