#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Exécutez ce script avec sudo ou depuis la session root." >&2
  exit 1
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="/opt/clientele/traefik"
ERP_DOMAIN="erp.clientelegroup.tech"
PREPROD_DOMAIN="preprod.erp.clientelegroup.tech"

read -r -p "Adresse e-mail de renouvellement Let's Encrypt : " ACME_EMAIL
if [[ ! "${ACME_EMAIL}" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  echo "Adresse e-mail invalide. Rien n'a été démarré." >&2
  exit 1
fi

install -d -m 0750 "${TARGET_DIR}/dynamic" "${TARGET_DIR}/letsencrypt"
install -m 0640 "${SOURCE_DIR}/compose.yaml" "${TARGET_DIR}/compose.yaml"
install -m 0640 "${SOURCE_DIR}/dynamic/clientele-security.yml" "${TARGET_DIR}/dynamic/clientele-security.yml"

umask 077
printf 'ACME_EMAIL=%s\nERP_DOMAIN=%s\nPREPROD_DOMAIN=%s\n' \
  "${ACME_EMAIL}" "${ERP_DOMAIN}" "${PREPROD_DOMAIN}" > "${TARGET_DIR}/.env"
touch "${TARGET_DIR}/letsencrypt/acme.json"
chmod 0600 "${TARGET_DIR}/letsencrypt/acme.json"

if ! docker network inspect traefik-public >/dev/null 2>&1; then
  docker network create traefik-public >/dev/null
fi

docker compose --project-directory "${TARGET_DIR}" --env-file "${TARGET_DIR}/.env" \
  -f "${TARGET_DIR}/compose.yaml" config -q
docker compose --project-directory "${TARGET_DIR}" --env-file "${TARGET_DIR}/.env" \
  -f "${TARGET_DIR}/compose.yaml" up -d

echo
echo "=== Conteneurs ==="
docker compose --project-directory "${TARGET_DIR}" --env-file "${TARGET_DIR}/.env" \
  -f "${TARGET_DIR}/compose.yaml" ps
echo
echo "=== Vérification HTTPS (peut prendre une minute) ==="
curl --silent --show-error --head --max-time 30 "https://${ERP_DOMAIN}" | sed -n '1,8p'
curl --silent --show-error --head --max-time 30 "https://${PREPROD_DOMAIN}" | sed -n '1,8p'
echo
echo "Traefik est en place. Le service bootstrap devra être retiré avant le déploiement de l'application réelle."
