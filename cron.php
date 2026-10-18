<?php
/**
 * Tâches planifiées de TPWeb (à lancer en ligne de commande, par exemple toutes les heures) :
 *   - purges RGPD (journal, archives, corbeille, cache des aperçus) ;
 *   - sauvegarde automatique si elle est due, avec copie externe.
 *
 * Linux / Docker (crontab de l'hôte) :
 *   0 * * * *  docker exec tpweb php /var/www/html/cron.php
 * Windows (Planificateur de tâches) :
 *   C:\xampp\php\php.exe C:\xampp\htdocs\TPWeb\cron.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/backup.php';
require_once __DIR__ . '/inc/cas.php';
require_once __DIR__ . '/inc/trash.php';
require_once __DIR__ . '/inc/autobackup.php';

maintenance_run(in_array('--force', $argv, true));
$force = in_array('--backup', $argv, true);
$result = autobackup_run($force);
echo date('Y-m-d H:i:s') . ' maintenance faite';
if ($result) echo ' ; sauvegarde ' . ($result['file'] ?: '-') . ' : ' . $result['msg'];
echo PHP_EOL;
