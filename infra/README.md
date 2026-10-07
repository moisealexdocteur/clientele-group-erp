# Infrastructure

Le fichier `compose.yaml` décrit la cible applicative de production. Il ne doit pas être lancé avant que les images applicatives soient construites et que les secrets réels soient placés hors Git.

Le proxy HTTPS est géré séparément par `traefik/compose.yaml`. Il est installé une fois sur le VPS, dans `/opt/clientele/traefik`, avant le déploiement de l'application.

Prérequis :

- Traefik opérationnel avec un réseau Docker externe nommé `traefik-public` ;
- certificats valides pour `erp.clientelegroup.tech` et `preprod.erp.clientelegroup.tech` ;
- fichier .env de production sécurisé, dérivé de .env.example ;
- images privées publiées depuis la branche validée ;
- sauvegarde de PostgreSQL et test de restauration ;
- recette de l'imprimante thermique réalisée.

Le compose ne publie pas PostgreSQL ni Redis. Seul le service web est routé par Traefik.

Le service `bootstrap` de `infra/traefik/compose.yaml` est uniquement utilisé pour la première émission des certificats. Il doit être arrêté et supprimé avant l'exposition de l'application réelle sur les mêmes domaines.
