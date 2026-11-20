#!/bin/sh
# Prépare le volume de données puis lance Apache (et les tâches planifiées toutes les heures).
set -e
DATA="${TPW_DATA:-/var/TPWebData}"
mkdir -p "$DATA"
# Droits d'écriture pour Apache (seulement si nécessaire, pour ne pas parcourir de gros volumes à chaque démarrage).
if [ "$(stat -c %U "$DATA")" != "www-data" ]; then
  chown -R www-data:www-data "$DATA" || echo "TPWeb : impossible de donner $DATA à www-data, vérifiez les droits du volume." >&2
fi
if [ "${TPW_CRON:-1}" = "1" ]; then
  # Purges RGPD, sauvegardes automatiques, courriels (bilans, résumés) : toutes les heures.
  ( sleep 60
    while true; do
      su -s /bin/sh www-data -c "php /var/www/html/cron.php" || true
      sleep 3600
    done ) &
fi
exec docker-php-entrypoint "$@"
