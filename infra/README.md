# Infrastructure

Le fichier `compose.yaml` décrit la cible applicative de production et de préproduction. Il ne construit pas d'image sur le KVM1 : les images validées sont tirées de GHCR. Le fichier `compose.build.yaml` est réservé au poste de développement ou à une recette temporaire, jamais au VPS de production. Les secrets réels restent hors Git.

Le proxy HTTPS est géré séparément par `traefik/compose.yaml`. Il est installé une fois sur le VPS, dans `/opt/clientele/traefik`, avant le déploiement de l'application.

Prérequis :

- Traefik opérationnel avec un réseau Docker externe nommé `traefik-public` ;
- certificats valides pour `erp.clientelegroup.tech` et `preprod.erp.clientelegroup.tech` ;
- fichier .env de production sécurisé, dérivé de .env.example ;
- branche validée, dépendances verrouillées et images privées publiées depuis celle-ci lorsque GHCR est activé ;
- sauvegarde de PostgreSQL et test de restauration ;
- recette de l'imprimante thermique réalisée.

Le compose ne publie pas PostgreSQL ni Redis. Seul le service web est routé par Traefik.

Le service `bootstrap` de `infra/traefik/compose.yaml` est uniquement utilisé pour la première émission des certificats. Il doit être arrêté et supprimé avant l'exposition de l'application réelle sur les mêmes domaines.

Le premier lancement de `app` applique les migrations Laravel avec `APP_RUN_MIGRATIONS=true`. Les services `worker` et `scheduler` attendent ensuite son état de santé. PostgreSQL et Redis ne publient aucun port.
