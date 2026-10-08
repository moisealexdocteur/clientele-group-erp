#!/usr/bin/env bash
set -Eeuo pipefail

# Déploiement préproduction autonome pour le VPS KVM1.
# Exécuter en clientele-deploy avec un tag immuable, par exemple :
# DEPLOY_TAG=sha-6248721 bash ~/deploy-preprod.sh

DEPLOY_TAG="${DEPLOY_TAG:-}"
DEPLOY_DIR="${DEPLOY_DIR:-/opt/clientele/erp-preprod}"
GHCR_USER="moisealexdocteur"
APP_DOMAIN="preprod.erp.clientelegroup.tech"

if [[ -z "${DEPLOY_TAG}" || ! "${DEPLOY_TAG}" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "Indiquez un tag GHCR immuable, par exemple : DEPLOY_TAG=sha-6248721 bash ~/deploy-preprod.sh" >&2
  exit 64
fi

if ! docker network inspect traefik-public >/dev/null 2>&1; then
  echo "Le réseau Docker externe traefik-public est introuvable. Arrêt sans modification." >&2
  exit 1
fi

if [[ ! -f "${DEPLOY_DIR}/.env" ]]; then
  sudo install -d -m 0750 -o "${USER}" -g "${USER}" "${DEPLOY_DIR}"
  umask 077
  app_key="$(openssl rand -base64 32 | tr -d '\n')"
  postgres_password="$(openssl rand -hex 32)"
  redis_password="$(openssl rand -hex 32)"
  qr_secret="$(openssl rand -hex 48)"
  cat > "${DEPLOY_DIR}/.env" <<EOF
COMPOSE_PROJECT_NAME=clientele-erp-preprod
TRAEFIK_ROUTER_NAME=clientele-erp-preprod
TRAEFIK_ROUTER_PRIORITY=1000
APP_DOMAIN=${APP_DOMAIN}
APP_URL=https://${APP_DOMAIN}
APP_ENV=staging
APP_IMAGE_TAG=${DEPLOY_TAG}
POSTGRES_DB=clientele_erp_preprod
POSTGRES_USER=clientele_erp_preprod
POSTGRES_PASSWORD=${postgres_password}
REDIS_PASSWORD=${redis_password}
APP_KEY=base64:${app_key}
MAIL_MAILER=log
SMTP_SCHEME=smtp
SMTP_HOST=localhost
SMTP_PORT=25
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM_ADDRESS=no-reply@clientelegroup.tech
SMTP_FROM_NAME=Clientèle Group préproduction
HASH_DRIVER=argon2id
HASH_VERIFY=true
ARGON_MEMORY=65536
ARGON_THREADS=1
ARGON_TIME=4
AUTH_TOKEN_ABSOLUTE_MINUTES=720
AUTH_TOKEN_IDLE_MINUTES=120
AUTH_EMAIL_CODE_TTL_MINUTES=10
AUTH_EMAIL_CODE_MAX_ATTEMPTS=5
QR_SIGNING_SECRET=${qr_secret}
EOF
  chmod 0600 "${DEPLOY_DIR}/.env"
else
  sed -i -E "s/^APP_IMAGE_TAG=.*/APP_IMAGE_TAG=${DEPLOY_TAG}/" "${DEPLOY_DIR}/.env"
  if grep -q '^TRAEFIK_ROUTER_PRIORITY=' "${DEPLOY_DIR}/.env"; then
    sed -i -E 's/^TRAEFIK_ROUTER_PRIORITY=.*/TRAEFIK_ROUTER_PRIORITY=1000/' "${DEPLOY_DIR}/.env"
  else
    printf '\nTRAEFIK_ROUTER_PRIORITY=1000\n' >> "${DEPLOY_DIR}/.env"
  fi

  ensure_env_line() {
    local key="$1"
    local value="$2"

    if ! grep -q "^${key}=" "${DEPLOY_DIR}/.env"; then
      printf '%s=%s\n' "${key}" "${value}" >> "${DEPLOY_DIR}/.env"
    fi
  }

  ensure_env_line HASH_DRIVER argon2id
  ensure_env_line HASH_VERIFY true
  ensure_env_line ARGON_MEMORY 65536
  ensure_env_line ARGON_THREADS 1
  ensure_env_line ARGON_TIME 4
  ensure_env_line AUTH_TOKEN_ABSOLUTE_MINUTES 720
  ensure_env_line AUTH_TOKEN_IDLE_MINUTES 120
  ensure_env_line AUTH_EMAIL_CODE_TTL_MINUTES 10
  ensure_env_line AUTH_EMAIL_CODE_MAX_ATTEMPTS 5
fi

cat > "${DEPLOY_DIR}/compose.yaml" <<'YAML'
name: ${COMPOSE_PROJECT_NAME:-clientele-erp}
services:
  web:
    image: ghcr.io/moisealexdocteur/clientele-group-erp-web:${APP_IMAGE_TAG}
    restart: unless-stopped
    depends_on:
      app:
        condition: service_healthy
    networks: [traefik-public, clientele-internal]
    labels:
      - "traefik.enable=true"
      - "traefik.docker.network=traefik-public"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.rule=Host(`${APP_DOMAIN}`)"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.entrypoints=websecure"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.tls=true"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.tls.certresolver=letsencrypt"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.middlewares=clientele-security@file"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.priority=${TRAEFIK_ROUTER_PRIORITY}"
      - "traefik.http.routers.${TRAEFIK_ROUTER_NAME}.service=${TRAEFIK_ROUTER_NAME}"
      - "traefik.http.services.${TRAEFIK_ROUTER_NAME}.loadbalancer.server.port=8080"
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://127.0.0.1:8080/health"]
      interval: 30s
      timeout: 5s
      retries: 3
  app:
    image: ghcr.io/moisealexdocteur/clientele-group-erp-app:${APP_IMAGE_TAG}
    restart: unless-stopped
    environment:
      APP_ENV: ${APP_ENV}
      APP_NAME: Clientèle Group ERP
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      APP_DEBUG: "false"
      APP_LOCALE: fr
      APP_FALLBACK_LOCALE: fr
      APP_FAKER_LOCALE: fr_FR
      HASH_DRIVER: ${HASH_DRIVER}
      HASH_VERIFY: ${HASH_VERIFY}
      ARGON_MEMORY: ${ARGON_MEMORY}
      ARGON_THREADS: ${ARGON_THREADS}
      ARGON_TIME: ${ARGON_TIME}
      AUTH_TOKEN_ABSOLUTE_MINUTES: ${AUTH_TOKEN_ABSOLUTE_MINUTES}
      AUTH_TOKEN_IDLE_MINUTES: ${AUTH_TOKEN_IDLE_MINUTES}
      AUTH_EMAIL_CODE_TTL_MINUTES: ${AUTH_EMAIL_CODE_TTL_MINUTES}
      AUTH_EMAIL_CODE_MAX_ATTEMPTS: ${AUTH_EMAIL_CODE_MAX_ATTEMPTS}
      APP_RUN_MIGRATIONS: "true"
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: "5432"
      DB_DATABASE: ${POSTGRES_DB}
      DB_USERNAME: ${POSTGRES_USER}
      DB_PASSWORD: ${POSTGRES_PASSWORD}
      DB_SSLMODE: disable
      REDIS_HOST: redis
      REDIS_PASSWORD: ${REDIS_PASSWORD}
      REDIS_CLIENT: phpredis
      CACHE_STORE: redis
      SESSION_DRIVER: redis
      QUEUE_CONNECTION: redis
      MAIL_MAILER: ${MAIL_MAILER}
      MAIL_SCHEME: ${SMTP_SCHEME}
      MAIL_HOST: ${SMTP_HOST}
      MAIL_PORT: ${SMTP_PORT}
      MAIL_USERNAME: ${SMTP_USERNAME}
      MAIL_PASSWORD: ${SMTP_PASSWORD}
      MAIL_FROM_ADDRESS: ${SMTP_FROM_ADDRESS}
      MAIL_FROM_NAME: ${SMTP_FROM_NAME}
      QR_SIGNING_SECRET: ${QR_SIGNING_SECRET}
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks: [clientele-internal]
    healthcheck:
      test: ["CMD", "php", "artisan", "health:check"]
      interval: 30s
      timeout: 10s
      retries: 3
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    volumes: [clientele-postgres:/var/lib/postgresql/data]
    networks: [clientele-internal]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}"]
      interval: 10s
      timeout: 5s
      retries: 10
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD}
    volumes: [clientele-redis:/data]
    networks: [clientele-internal]
    healthcheck:
      test: ["CMD-SHELL", "redis-cli --no-auth-warning -a ${REDIS_PASSWORD} ping | grep PONG"]
      interval: 10s
      timeout: 5s
      retries: 10
networks:
  traefik-public:
    external: true
  clientele-internal:
    internal: true
volumes:
  clientele-postgres:
  clientele-redis:
YAML

compose() {
  docker compose --project-directory "${DEPLOY_DIR}" --env-file "${DEPLOY_DIR}/.env" -f "${DEPLOY_DIR}/compose.yaml" "$@"
}

compose config -q
read -r -s -p "Jeton GitHub classique (read:packages) : " GHCR_TOKEN
echo
if [[ -z "${GHCR_TOKEN}" ]]; then
  echo "Jeton absent. Arrêt sans démarrer de conteneur." >&2
  exit 1
fi
printf '%s' "${GHCR_TOKEN}" | docker login ghcr.io -u "${GHCR_USER}" --password-stdin
unset GHCR_TOKEN
compose pull
docker logout ghcr.io >/dev/null
compose up -d

for attempt in {1..24}; do
  if compose exec -T app php artisan health:check >/dev/null 2>&1; then
    break
  fi
  if [[ "${attempt}" -eq 24 ]]; then
    compose ps
    echo "La santé applicative n'a pas été confirmée. Les journaux suivent :" >&2
    compose logs --tail=120 app postgres redis >&2
    exit 1
  fi
  sleep 5
done

public_health=''
for attempt in {1..24}; do
  if public_health="$(curl --fail --silent --max-time 30 "https://${APP_DOMAIN}/api/health")" \
    && [[ "${public_health}" == *'"status":"ok"'* ]] \
    && [[ "${public_health}" == *'"service":"clientele-group-erp-api"'* ]]; then
    break
  fi
  if [[ "${attempt}" -eq 24 ]]; then
    echo "Le point de santé public ne répond pas encore avec l'API ERP attendue." >&2
    compose ps >&2
    exit 1
  fi
  sleep 5
done
printf '%s\n' "${public_health}"
compose ps
echo "Préproduction opérationnelle avec le tag ${DEPLOY_TAG}."
