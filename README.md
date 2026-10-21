# Roue de la Chance

Site de jeu « roue de la chance » avec tickets QR à usage unique, pour 4 enseignes, prêt pour Render.

## Contenu

- `site/` : le site (PHP 8.3).
- `Dockerfile` et `docker/` : l'image PHP + Apache utilisée par Render.
- `render.yaml` : la configuration du service pour Render (Blueprint).

## Mise en ligne sur Render

1. Sur Render, cliquez sur **New → Blueprint** et choisissez ce dépôt.
2. Render demande deux valeurs :
   - `RDC_ADMIN_PHONE` : votre numéro de téléphone, avec l'indicatif (par exemple +212…) ;
   - `RDC_ADMIN_PASSWORD` : votre mot de passe de départ (8 caractères minimum).
3. Validez. Render construit le site et crée un disque permanent de 1 Go pour les tickets et les photos. Il faut une offre payante : le disque n'existe pas dans l'offre gratuite.
4. Quand le service est « Live », ouvrez `https://<votre-service>.onrender.com/admin/` et connectez-vous avec votre numéro et votre mot de passe.

Aucun mot de passe n'est écrit dans ce dépôt : ils restent dans les réglages du service sur Render (onglet **Environment**).
