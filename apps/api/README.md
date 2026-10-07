# API Clientèle Group ERP

Ce dossier recevra l'application Laravel et ses migrations, tests et modules.

Le fichier database/001_noyau.sql constitue le contrat de données initial. Il sert à valider les règles PostgreSQL avant de les traduire en migrations Laravel versionnées.

L'API ne doit jamais exposer une route qui contourne :

1. l'authentification ;
2. la société active ;
3. la vérification de permission ;
4. l'écriture d'audit ;
5. l'idempotence des opérations de caisse.
