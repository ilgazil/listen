#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# 1. Configuration (gitignorée) — .deploy.env via .deploy.env.example
if [ ! -f .deploy.env ]; then
  echo "❌ Fichier .deploy.env manquant."
  echo "   cp .deploy.env.example .deploy.env puis renseignez vos accès de production."
  exit 1
fi
# shellcheck disable=SC1091
source .deploy.env

PROD_PORT="${PROD_PORT:-22}"
PROD_PHP="${PROD_PHP:-php}"
PROD_USE_COMPOSER="${PROD_USE_COMPOSER:-0}"
# URL publique du site (optionnelle) — uniquement pour l'affichage du récapitulatif final.
PROD_URL="${PROD_URL:-}"

if [ -z "${PROD_HOST:-}" ] || [ -z "${PROD_SSH_USER:-}" ] || [ -z "${PROD_PATH:-}" ]; then
  echo "❌ PROD_HOST / PROD_SSH_USER / PROD_PATH manquants dans .deploy.env."
  exit 1
fi

DRY=""
if [ "${1:-}" = "--dry-run" ]; then
  DRY="--dry-run"
  echo "→ MODE DRY-RUN : aucune modification sur le serveur."
fi

# 2. Sécurité : la prod doit refléter git (arbre propre)
if [ -z "$DRY" ] && [ -n "$(git status --porcelain)" ]; then
  echo "⚠️  Arbre git sale — la prod doit refléter un état commité."
  git status --short
  exit 1
fi

# 3. Vérifier que le build frontend existe (make deploy le produit via check)
if [ ! -d frontend/dist ] || [ ! -f frontend/dist/index.html ]; then
  echo "❌ frontend/dist absent. Lancez d'abord : make check"
  exit 1
fi

# 4. Connexion SSH par clé (jamais par mot de passe) :
#    - DEPLOY_SSH_KEY (optionnel) = chemin de la clé privée dédiée au déploiement.
#    - BatchMode=yes : si un mot de passe était demandé, le script échoue au lieu de bloquer.
#    - accept-new : le 1er contact ajoute le serveur à known_hosts sans confirmation.
SSH_OPTS=(-p "$PROD_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
if [ -n "${DEPLOY_SSH_KEY:-}" ]; then
  DEPLOY_SSH_KEY="${DEPLOY_SSH_KEY/#\~/$HOME}"
  if [ ! -f "$DEPLOY_SSH_KEY" ]; then
    echo "❌ Clé SSH introuvable : $DEPLOY_SSH_KEY"
    echo "   Générez-la (make ssh-key) puis envoyez la clé publique (make ssh-copy)."
    exit 1
  fi
  SSH_OPTS+=(-i "$DEPLOY_SSH_KEY")
fi

SSH_DEST="$PROD_SSH_USER@$PROD_HOST"
# rsync appelle `-e "ssh <options>"` : on fournit une vraie ligne de commande ssh, pas une chaîne.
SSH_CMD="ssh"
for opt in "${SSH_OPTS[@]}"; do SSH_CMD="$SSH_CMD $(printf '%q' "$opt")"; done

echo "→ source procédure : $(git rev-parse --short HEAD) @ $PROD_HOST:$PROD_PATH"

# 5. Sources backend (les artefacts runtime et secrets restent sur le serveur)
EXCLUDES=(
  --exclude '.env*'            # .env.prod + .env.prod.local du serveur, jamais écrasés
  --exclude '.user.ini'        # config PHP réglée depuis le Manager Infomaniak (max_execution_time…)
  --exclude 'var/'
  --exclude 'public/cache/'    # cache scrapers quotidien
  --exclude 'public/uploads/'  # fichiers uploadés présents sur le serveur
  --exclude 'public/assets/'   # artefacts du build frontend
  --exclude 'public/index.html'
  --exclude 'public/favicon.ico'
)
[ "$PROD_USE_COMPOSER" = "1" ] && EXCLUDES+=( --exclude 'vendor/' )

echo "→ rsync backend (sources)"
rsync -az --delete "${EXCLUDES[@]}" $DRY -e "$SSH_CMD" backend/ "$SSH_DEST:$PROD_PATH/"

# 6. Build frontend (déjà produit par make check), envoyé dans public/
echo "→ rsync frontend (build dans public/)"
rsync -az $DRY -e "$SSH_CMD" frontend/dist/ "$SSH_DEST:$PROD_PATH/public/"

if [ -n "$DRY" ]; then
  echo "→ dry-run terminé, rien n'a été envoyé."
  exit 0
fi

# 7. Composer sur le serveur (optionnel : si PROD_USE_COMPOSER=1)
if [ "$PROD_USE_COMPOSER" = "1" ]; then
  echo "→ composer install --no-dev sur le serveur"
  ssh "${SSH_OPTS[@]}" "$SSH_DEST" "cd $PROD_PATH && composer install --no-dev --optimize-autoloader"
fi

# 8. Cache Symfony prod (déjà chaud si rien n'a changé, sinon rejeté)
echo "→ cache:clear --env=prod"
ssh "${SSH_OPTS[@]}" "$SSH_DEST" "cd $PROD_PATH && $PROD_PHP bin/console cache:clear --env=prod"

echo "✅ Déploiement terminé : $(git rev-parse --short HEAD)${PROD_URL:+ → $PROD_URL}"
echo "   Si des migrations sont en attente : $PROD_PHP bin/console doctrine:migrations:migrate (manuelle, à la volée)."