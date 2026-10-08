#!/usr/bin/env bash
set -Eeuo pipefail

# Correctif immédiat du routeur préproduction déjà déployé.
# À exécuter avec le compte clientele-deploy, sans root.

DEPLOY_DIR="${DEPLOY_DIR:-/opt/clientele/erp-preprod}"
APP_DOMAIN="preprod.erp.clientelegroup.tech"
ROUTE_OVERRIDE="${DEPLOY_DIR}/compose.route-fix.yaml"

if [[ "${EUID}" -eq 0 ]]; then
  echo "Exécutez ce correctif avec clientele-deploy, pas avec root." >&2
  exit 64
fi

if [[ ! -f "${DEPLOY_DIR}/compose.yaml" || ! -f "${DEPLOY_DIR}/.env" ]]; then
  echo "La préproduction n'est pas installée dans ${DEPLOY_DIR}. Arrêt sans modification." >&2
  exit 1
fi

if ! docker network inspect traefik-public >/dev/null 2>&1; then
  echo "Le réseau traefik-public est introuvable. Arrêt sans modification." >&2
  exit 1
fi

install -m 0600 /dev/null "${ROUTE_OVERRIDE}"
cat > "${ROUTE_OVERRIDE}" <<'YAML'
services:
  web:
    labels:
      traefik.enable: "true"
      traefik.docker.network: traefik-public
      traefik.http.routers.clientele-erp-preprod.rule: "Host(`preprod.erp.clientelegroup.tech`)"
      traefik.http.routers.clientele-erp-preprod.entrypoints: websecure
      traefik.http.routers.clientele-erp-preprod.tls: "true"
      traefik.http.routers.clientele-erp-preprod.tls.certresolver: letsencrypt
      traefik.http.routers.clientele-erp-preprod.middlewares: clientele-security@file
      traefik.http.routers.clientele-erp-preprod.priority: "1000"
      traefik.http.routers.clientele-erp-preprod.service: clientele-erp-preprod
      traefik.http.services.clientele-erp-preprod.loadbalancer.server.port: "8080"
YAML

compose() {
  docker compose \
    --project-directory "${DEPLOY_DIR}" \
    --env-file "${DEPLOY_DIR}/.env" \
    -f "${DEPLOY_DIR}/compose.yaml" \
    -f "${ROUTE_OVERRIDE}" \
    "$@"
}

compose config -q
compose up -d --force-recreate web

public_health=''
for attempt in {1..24}; do
  if public_health="$(curl --fail --silent --max-time 20 "https://${APP_DOMAIN}/api/health")" \
    && [[ "${public_health}" == *'"status":"ok"'* ]] \
    && [[ "${public_health}" == *'"service":"clientele-group-erp-api"'* ]]; then
    printf '%s\n' "${public_health}"
    echo "Routage préproduction confirmé vers l'API ERP."
    exit 0
  fi
  sleep 3
done

echo "Le routeur public ne rejoint pas encore l'API ERP. Aucun autre service n'a été modifié." >&2
compose ps >&2
exit 1
