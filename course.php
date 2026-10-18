<?php
/** Ancienne adresse d'affichage des cours HTML : redirige vers l'aperçu intégré. */
require_once __DIR__ . '/inc/bootstrap.php';
redirect('preview.php?level=' . rawurlencode(query('level')) . '&path=' . rawurlencode(query('path')));
