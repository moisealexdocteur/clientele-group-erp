# Infrastructure

Le fichier compose.yaml décrit la cible de production. Il ne doit pas être lancé avant que les images applicatives soient construites, que le domaine soit défini et que les secrets réels soient placés hors Git.

Prérequis :

- réseau Docker externe traefik-public déjà créé par le Traefik existant ;
- fichier .env de production sécurisé, dérivé de .env.example ;
- images privées publiées depuis la branche validée ;
- sauvegarde de PostgreSQL et test de restauration ;
- recette de l'imprimante thermique réalisée.

Le compose ne publie pas PostgreSQL ni Redis. Seul le service web est routé par Traefik.
