# Traefik HTTPS

Ce dossier installe le seul point d'entrée Internet du progiciel sur le VPS dédié.

- Ports publiés : TCP 80 et TCP 443 uniquement.
- Tableau de bord Traefik : désactivé et non publié.
- Découverte Docker : les conteneurs restent privés par défaut ; une application doit porter le label `traefik.enable=true` pour être publiée.
- Certificats : Let's Encrypt par défi HTTP, stockés hors Git dans `/opt/clientele/traefik/letsencrypt/acme.json` avec droits 0600.
- Réseau externe : `traefik-public`.

## Première installation

Depuis une copie fiable de ce dépôt sur le VPS, exécutée en root :

```bash
bash infra/traefik/bootstrap-traefik.sh
```

Le script demande seulement l'adresse de renouvellement Let's Encrypt. Il crée la configuration dans `/opt/clientele/traefik`, démarre Traefik puis un petit service temporaire sans donnée métier afin d'émettre les certificats pour :

- `erp.clientelegroup.tech`
- `preprod.erp.clientelegroup.tech`

Le pare-feu Hostinger doit déjà accepter TCP 80 et TCP 443 et les deux noms DNS doivent viser le VPS.

## Passage à l'application

La préproduction garde le service `bootstrap` actif : il continue de répondre sur `erp.clientelegroup.tech`, tandis que le compose applicatif déclare un routeur `clientele-erp-preprod` prioritaire pour `preprod.erp.clientelegroup.tech`. Cela évite une interruption de la page temporaire de production.

Lors de la vraie publication de production, créer et vérifier d'abord le routeur de production, puis retirer `bootstrap` sans arrêter Traefik :

```bash
cd /opt/clientele/traefik
docker compose --env-file .env stop bootstrap
docker compose --env-file .env rm -f bootstrap
```
