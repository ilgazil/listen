#!/usr/bin/env bash
# Session SSH interactive ou commande one-shot sur le serveur de prod —
# même configuration et mêmes garde-fous que scripts/deploy.sh (.deploy.env).
#
#   scripts/ssh.sh                    → shell interactif, atterri dans PROD_PATH
#   scripts/ssh.sh <commande>         → exécute la commande depuis PROD_PATH
#                                       (ex. "PROD_PHP bin/console doctrine:migrations:migrate")
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

if [ -z "${PROD_HOST:-}" ] || [ -z "${PROD_SSH_USER:-}" ] || [ -z "${PROD_PATH:-}" ]; then
  echo "❌ PROD_HOST / PROD_SSH_USER / PROD_PATH manquants dans .deploy.env."
  exit 1
fi

# 2. Connexion SSH par clé (jamais par mot de passe) — mêmes options que deploy.sh :
#    BatchMode échoue net si un mot de passe serait demandé, accept-new fait
#    confiance au serveur au premier contact.
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

# Chemin cité pour le shell distant (printf %q : sûr même avec des espaces).
PROD_PATH_Q="$(printf '%q' "$PROD_PATH")"

# 3. Interactif (shell de login atterri dans le dossier de l'app) ou commande.
#    -t : un TTY est alloué dans les deux cas (confirmation des migrations,
#    Ctrl+C, éditeurs). Le exit code de la commande distante est propagé.
#
#    Shell interactif = /bin/bash explicite : le compte Infomaniak a un shell
#    de login « /usr/sbin/nologin » (les commandes ssh passent quand même, mais
#    « exec $SHELL » retombe dessus → « This account is currently not
#    available. »). bash est exécutable et -l charge le profil (PATH complet).
if [ $# -eq 0 ]; then
  echo "→ Shell interactif sur $PROD_HOST — dossier de l'app : $PROD_PATH"
  REMOTE_CMD="cd $PROD_PATH_Q && exec /bin/bash -li"
else
  CMD="$*"
  echo "→ Commande sur $PROD_HOST (depuis $PROD_PATH) : $CMD"
  REMOTE_CMD="cd $PROD_PATH_Q && $CMD"
fi

set +e
ssh -t "${SSH_OPTS[@]}" "$SSH_DEST" "$REMOTE_CMD"
status=$?
set -e

if [ $status -eq 255 ]; then
  echo "❌ Échec SSH : clé non déployée ou serveur injoignable."
  echo "   Une seule fois : make ssh-key puis make ssh-copy."
fi

exit $status
