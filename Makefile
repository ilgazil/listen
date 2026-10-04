.DEFAULT_GOAL := help
.PHONY: help install backend frontend db-up db-create db-migrate test test-backend test-frontend lint lint-backend lint-frontend format build check deploy deploy-dry ssh-key ssh-copy

# Utilise Node 24 via nvm (le Node système par défaut est v20.9, hors `engines`).
# Si nvm est absent, la commande tourne avec le Node système en place.
NVM := export NVM_DIR="$$HOME/.nvm"; . "$$NVM_DIR/nvm.sh" 2>/dev/null; nvm use 24 >/dev/null 2>&1 || true

help: ## Affiche cette aide et liste les commandes
	@rg '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sed 's/:.*##/  /'

install: ## Installe les dépendances (backend + frontend)
	cd backend && composer install
	@$(NVM); cd frontend && npm install

backend: ## Lance le backend Symfony (http://localhost:8000)
	cd backend && PHP_INI_SCAN_DIR=":$$(pwd)/.php-local" symfony serve

frontend: ## Lance le frontend Vite (http://localhost:5173)
	@$(NVM); cd frontend && npm run dev

db-up: ## Démarre PostgreSQL local (docker compose)
	cd backend && docker compose up -d

db-create: ## Crée la base locale et applique les migrations
	cd backend && php bin/console doctrine:database:create && php bin/console doctrine:migrations:migrate

db-migrate: ## Applique les migrations
	cd backend && php bin/console doctrine:migrations:migrate

test: test-backend test-frontend ## Lance tous les tests (backend + frontend)

test-backend: ## Lance les tests backend (PHPUnit)
	cd backend && php bin/phpunit

test-frontend: ## Lance les tests frontend (Vitest)
	@$(NVM); cd frontend && npm run test

lint-backend: ## Lint le backend (container + yaml)
	cd backend && php bin/console lint:container && php bin/console lint:yaml config

lint-frontend: ## Lint le frontend (eslint)
	@$(NVM); cd frontend && npm run lint

lint: lint-backend lint-frontend ## Lint backend + frontend

format: ## Formate le frontend (prettier)
	@$(NVM); cd frontend && npm run format

build: ## Build le frontend (type-check + build dans frontend/dist)
	@$(NVM); cd frontend && npm run build

check: lint test build ## Vérifications complètes avant déploiement (lint + tests + build)

deploy: check ## Vérifie puis déploie sur la prod (rsync + ssh), voir .deploy.env
	scripts/deploy.sh

deploy-dry: ## Simule le déploiement (dry-run rsync, rien n'est envoyé)
	scripts/deploy.sh --dry-run

ssh-key: ## Génère la clé SSH de déploiement dédiée (une seule fois)
	@test -f "$$HOME/.ssh/id_ed25519_infomaniak" && \
	  echo "✅ Clé déjà existante : ~/.ssh/id_ed25519_infomaniak" || \
	  ssh-keygen -t ed25519 -C "deploy-infomaniak-listen" -N "" -f "$$HOME/.ssh/id_ed25519_infomaniak"

ssh-copy: ## Envoie la clé publique sur le serveur (dernière fois qu'un mot de passe est demandé)
	@. .deploy.env && \
	  KEY="$${DEPLOY_SSH_KEY/#\~/\$$HOME}"; \
	  if command -v ssh-copy-id >/dev/null 2>&1; then \
	    ssh-copy-id -i "$$KEY.pub" -p "$${PROD_PORT}" "$${PROD_SSH_USER}@$${PROD_HOST}"; \
	  else \
	    echo "⚠️ ssh-copy-id absent (brew install ssh-copy-id). Méthode manuelle : "; \
	    echo "   Exécutez à la main : cat $$KEY.pub | ssh -p $${PROD_PORT} $${PROD_SSH_USER}@$${PROD_HOST} \\"; \
	    echo "     'mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys'"; \
	    exit 1; \
	  fi; \
	  echo "✅ Clé copiée sur $${PROD_HOST} — plus jamais de mot de passe pour make deploy"