# Comptes avec écritures et courriel d'essai

Branche `fix/comptes-et-essai-smtp`, sur `main` à `8ee9ece`. La version reste `0.9.0-alpha.1`.

## 1. Suppression définitive d'un utilisateur

- La suppression définitive est refusée si le compte a des écritures dans la société : « Ce compte a des écritures. Désactivez-le. »
- Écritures prises en compte (`App\Support\UserRecords`) :
  - paiement approuvé, inspection, règlement de dépôt, facture, changement de plaque, fichier déposé, taux de change saisi ;
  - session de caisse ouverte, clôturée ou approuvée, mouvement d'espèces ;
  - toute action métier journalisée par ce compte (réservation créée, modifiée, mise en circulation, paiement soumis, reçu réimprimé, etc.).
- Ne comptent pas : connexion, refus d'accès, actions de configuration, consultation d'un fichier et impression d'un rapport.
- La désactivation reste possible et conserve le nom de l'auteur sur chaque écriture.
- Le propriétaire système reste non supprimable. La confirmation par le courriel exact ne change pas.
- La fiche utilisateur l'indique avant toute tentative (`has_records` dans la réponse de l'API) ; le bouton est alors indisponible.
- Le contrôle porte sur la société de la fiche (isolation RLS). La suppression reste déjà réservée à un compte sans accès à une autre société.

## 2. Courriel d'essai

- Configuration > Courriel, propriétaire seulement.
- Affiche l'expéditeur (adresse et nom), l'hôte masqué (par exemple `smtp.h•••••••r.com`) et le port. Jamais l'identifiant ni le mot de passe.
- « Envoyer un courriel d'essai » envoie un message à l'adresse du propriétaire connecté, et à aucune autre.
- Un échec est expliqué en français, sans trace technique : identifiant ou mot de passe refusé, connexion chiffrée en échec, serveur injoignable, expéditeur refusé, ou message général. La trace reste dans le journal du serveur.
- Chaque essai est journalisé (`configuration.mail_test_sent` ou `configuration.mail_test_failed` avec la catégorie d'échec). Cinq essais au plus par dix minutes.
- Pas de formulaire pour le serveur ni le mot de passe : ils restent dans l'environnement du serveur (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_ADDRESS`, `SMTP_FROM_NAME`).

## 3. Tests

- `SystemConfigurationTest::test_a_user_with_records_or_the_owner_can_only_be_deactivated` : refus avec écritures, désactivation possible, propriétaire non supprimable. Le cas sans écriture reste couvert par `test_owner_can_manage_then_permanently_delete_an_unshared_company_user`.
- `SystemMailTest` : affichage sans mot de passe ni hôte complet, envoi au seul propriétaire, échec SMTP en français sans trace, accès refusé hors propriétaire.
