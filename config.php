<?php
/**
 * TPWeb — configuration.
 *
 * Les cours, les rendus des élèves et les réglages sont stockés HORS du dossier
 * web public (htdocs). Adaptez ces chemins uniquement si votre installation est différente.
 */

define('TPW_DATA', dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'TPWebData');
define('TPW_COURSES', TPW_DATA . DIRECTORY_SEPARATOR . 'cours');
define('TPW_STATE', TPW_DATA . DIRECTORY_SEPARATOR . 'config');
define('TPW_RENDUS', TPW_DATA . DIRECTORY_SEPARATOR . 'rendus');
define('TPW_BACKUPS', TPW_DATA . DIRECTORY_SEPARATOR . 'sauvegardes');
define('TPW_TRASH', TPW_DATA . DIRECTORY_SEPARATOR . 'corbeille');
define('TPW_ARCHIVES', TPW_DATA . DIRECTORY_SEPARATOR . 'archives');
define('TPW_CACHE', TPW_DATA . DIRECTORY_SEPARATOR . 'cache');

// Dossier racine partagé entre enseignants d'une même matière (jamais visible des élèves).
define('TPW_BANK', 'Banque de ressources');

// Durée de conservation dans la corbeille (jours).
define('TPW_TRASH_DAYS', 30);

// Un document est signalé « Nouveau » pendant ce nombre de jours.
define('TPW_NEW_DAYS', 7);

define('TPW_APP_NAME', 'TPWeb');
define('TPW_VERSION', '17.0');
define('TPW_TIMEZONE', 'Europe/Paris');

// LibreOffice (aperçu des documents Word, PowerPoint, tableurs). Vide = détection automatique.
// Exemple Windows : 'C:\\Program Files\\LibreOffice\\program\\soffice.exe'
define('TPW_SOFFICE', '');

// Longueur minimale des mots de passe enseignants.
define('TPW_PASSWORD_MIN', 10);

// Classes créées au premier démarrage (modifiables ensuite dans l'administration).
define('TPW_DEFAULT_LEVELS', ['6ème', '5ème', '4ème', '3ème', '2nde', '1ère', 'Terminale', 'BTS 1', 'BTS 2']);
