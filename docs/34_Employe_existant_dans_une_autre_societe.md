# Employé existant dans une autre société

Un employé du groupe garde un seul compte (courriel, mot de passe, code de sécurité) et peut recevoir un accès dans plusieurs sociétés, par exemple Car Rental et Auto Parts. La version reste `0.9.0-alpha.1`.

## Utilisation

Configuration > Utilisateurs > choisir la société > « Ajouter un employé existant » :

1. choisir l'employé dans la liste des comptes actifs du groupe qui n'ont pas encore accès à cette société ;
2. choisir le profil prévu pour l'activité de la société (par exemple Vendeur pour Auto Parts) ;
3. choisir les adresses autorisées ;
4. « Donner l'accès ».

L'employé reçoit un courriel « Nouvel accès » au design validé. Il se connecte avec son mot de passe habituel et choisit la société.

## Règles

- Propriétaire du système seulement, journalisé (`configuration.company_user_attached`).
- Le profil et les adresses sont propres à chaque société ; les accès aux autres sociétés ne changent pas.
- La liste montre seulement le nom et le courriel ; elle ne révèle pas les autres sociétés du compte.
- Refusé pour le compte propriétaire, un compte désactivé ou un compte qui a déjà accès à la société. Un accès désactivé se réactive depuis la fiche de l'utilisateur.
- « Ajouter un utilisateur » avec un courriel déjà connu renvoie vers « Ajouter un employé existant ».
- Un compte présent dans plusieurs sociétés n'est pas supprimable ; ses données personnelles ne se modifient pas depuis une seule société.

## Tests

`SystemConfigurationTest::test_the_owner_gives_an_existing_employee_access_to_another_company`.
