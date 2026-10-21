#!/bin/sh
# Roue de la Chance — démarrage du conteneur : disque permanent, port, puis Apache.
set -eu
WEBROOT="${ROUE_WEBROOT:-/var/www/html}"
DIST="${ROUE_UPLOADS_DIST:-/usr/local/share/roue/uploads}"
DATA="${RDC_DATA_DIR:-/var/data}"
export PORT="${PORT:-10000}"

mkdir -p "$DATA/uploads"
# Fichiers de protection du dossier des photos (copiés une seule fois sur le disque)
for f in .htaccess index.html; do
  if [ ! -e "$DATA/uploads/$f" ] && [ -e "$DIST/$f" ]; then cp "$DIST/$f" "$DATA/uploads/$f"; fi
done
# Les photos envoyées depuis l'administration sont enregistrées sur le disque permanent
if [ ! -L "$WEBROOT/uploads" ]; then
  rm -rf "$WEBROOT/uploads"
  ln -s "$DATA/uploads" "$WEBROOT/uploads"
fi
chown -R www-data:www-data "$DATA" 2>/dev/null || true

if command -v mountpoint >/dev/null 2>&1 && ! mountpoint -q "$DATA"; then
  echo "ATTENTION : aucun disque permanent n'est monté sur $DATA." >&2
  echo "Les tickets, les parties et les photos seront effacés au prochain redémarrage. Ajoutez un disque Render monté sur $DATA." >&2
fi
echo "Roue de la Chance : démarrage sur le port $PORT (données : $DATA)"
exec "${ROUE_EXEC:-apache2-foreground}"
