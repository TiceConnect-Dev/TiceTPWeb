TPWEB 16 - PORTAIL DE RESSOURCES PÉDAGOGIQUES (XAMPP / Windows)
================================================================

INSTALLATION
------------
1) Décompressez le dossier TPWeb dans C:\xampp\htdocs\TPWeb
2) Lancez XAMPP Control Panel, démarrez Apache, puis ouvrez http://localhost/TPWeb/admin.php
3) Au premier démarrage, créez le compte administrateur (identifiant + mot de passe).
4) Le dossier C:\xampp\TPWebData\cours est créé automatiquement à côté de htdocs.
   Déposez-y vos dossiers et fichiers de cours. Ne les placez JAMAIS dans htdocs.
5) Dans Paramètres, indiquez le nom de l'établissement (affiché dans l'en-tête).
6) Dans Comptes enseignants, créez les comptes de vos collègues et attribuez-leur leurs dossiers.
7) Dans Bibliothèque, envoyez vos documents, publiez-les pour les classes voulues
   et ouvrez si besoin le dépôt des travaux.
8) Les élèves se rendent sur http://IP_DU_SERVEUR/TPWeb/ depuis le réseau local.

MISE À JOUR DEPUIS UNE VERSION PRÉCÉDENTE
-----------------------------------------
Publications, classes et codes sont conservés. L'ancien compte devient automatiquement
l'identifiant « admin » avec le même mot de passe.
1) Arrêtez Apache
2) Sauvegardez le dossier TPWeb actuel ET le dossier TPWebData
3) Supprimez l'ancien dossier htdocs\TPWeb puis copiez le nouveau
   (l'ancien fichier lib.php n'est plus utilisé)
4) Conservez impérativement TPWebData
5) Redémarrez Apache puis rechargez le navigateur avec Ctrl + F5

STRUCTURE
---------
TPWeb/
  index.php      Espace élèves (choix de la classe, code, consultation)
  admin.php      Espace enseignant (bibliothèque, classes, codes, paramètres)
  file.php       Ouverture / téléchargement d'un document
  view.php       Affichage des cours HTML et de leurs ressources (isolés)
  preview.php    Aperçu intégré (cours HTML, PDF, images, vidéos, audio, texte)
  course.php     Ancienne adresse, redirige vers preview.php
  cas.php        Connexion des enseignants par l'ENT (CAS)
  quiz.php       QCM côté élève
  present.php    Mode projection (enseignant)
  cron.php       Tâches planifiées (ligne de commande uniquement)
  legal.php      Mentions légales et données personnelles
  config.php     Chemins et réglages
  inc/           Code interne (accès web interdit)
  assets/        Feuille de style, script, icône
TPWebData/
  cours/         Vos dossiers et fichiers de cours
  rendus/        Travaux déposés par les élèves
  corbeille/     Éléments supprimés (30 jours)
  archives/      Rendus archivés en fin d'année
  sauvegardes/   Archives créées depuis l'administration
  config/        Réglages, comptes, codes de classes, dépôts
  cache/         Aperçus PDF des documents bureautiques

SÉCURITÉ
--------
- TPWebData doit rester hors du dossier web public.
- Sauvegardez TPWebData régulièrement.
- Connexion enseignant limitée à 5 essais puis blocage de 5 minutes.
- Codes de classes : 5 essais puis blocage de 5 minutes.
- Les cours HTML s'exécutent dans un cadre isolé (sandbox), sans accès à l'administration.
- Ne publiez pas ce serveur sur Internet sans sécurisation supplémentaire (HTTPS, pare-feu).
- Le pare-feu Windows peut bloquer le port 80 : testez depuis un poste élève.
- Compatible PHP 7.0 et supérieur (extensions mbstring et zip, actives par défaut dans XAMPP).

RÉGLAGES PHP UTILES (php.ini de XAMPP)
--------------------------------------
- upload_max_filesize = 64M et post_max_size = 64M : taille maximale des envois
  (par défaut 40 Mo dans XAMPP). Redémarrez Apache après modification.
- extension=zip : nécessaire pour les sauvegardes et le téléchargement des rendus en .zip.

CONNEXION PAR L'ENT (ex. : ENT Hauts-de-France)
-----------------------------------------------
1) Paramètres > Connexion par l'ENT : copiez l'« adresse de service ».
2) Demandez à l'administrateur ENT de l'établissement de créer un connecteur CAS
   pour TPWeb avec cette adresse.
3) Renseignez l'adresse du serveur CAS de l'ENT (communiquée par l'administrateur ENT),
   enregistrez, puis « Tester la connexion ».
4) Activez le bouton. Chaque enseignant se connecte une fois avec son identifiant TPWeb
   puis clique sur « Lier mon compte ENT » dans Mon compte.
Le serveur doit avoir accès à Internet en HTTPS (extension curl ou openssl de PHP).
La connexion par identifiant TPWeb reste toujours disponible (secours).

NIVEAUX ET CLASSES
------------------
Administration > Niveaux et classes : un niveau en début de ligne, ses classes en retrait :
    Seconde
        2GT1
        2GT2
    Terminale
        TGA
        TSTI2D
    BTS SIO 1        (ligne seule = classe sans niveau)
Les élèves choisissent leur niveau puis leur classe. Dans les formulaires (publication,
dépôts, annonces, examen), une case « tout le niveau » coche toutes ses classes.

ENSEIGNANTS : MATIÈRE, CLASSES ET DROITS
----------------------------------------
- Chaque compte enseignant a une matière et des classes. TPWeb crée automatiquement
  les dossiers « Classe/Matière » (ex. : 2GT1/Physique-Chimie), les attribue à
  l'enseignant et les publie pour la classe. Côté élève : section « Mes matières ».
- L'enseignant peut choisir lui-même ses classes (Mon compte) si l'administrateur
  lui en donne le droit.
- Droits réglables par compte (Comptes enseignants) : publier, ajouter des fichiers,
  créer des dossiers, renommer/déplacer/dupliquer/supprimer, dépôts, examens,
  annonces, codes d'accès, choix de ses classes. Les droits sont vérifiés côté serveur.

CONFORMITÉ (RGPD)
-----------------
- Page publique « Mentions légales et données personnelles » (legal.php), liée en pied de page.
  Renseignez responsable, contact, DPO et hébergeur dans Paramètres.
- Notice d'information sous le formulaire de dépôt des élèves.
- Journal d'activité (Administration › Journal d'activité), export CSV, conservé 12 mois.
- Purge automatique quotidienne : journal > 12 mois, archives de rendus > 1 an,
  corbeille > 30 jours, tentatives de connexion > 1 heure.
- Pensez à inscrire TPWeb au registre des traitements de l'établissement (voir la fiche technique).

NOUVEAUTÉS VERSION 16
---------------------
En classe
- Aperçu des documents Word, PowerPoint et tableurs dans le portail (converti en PDF par
  LibreOffice s'il est installé sur le serveur, sinon aperçu texte simplifié).
- Mode projection (Bibliothèque › Projeter) : document en plein écran, flèches ← → pour
  passer au document suivant du dossier, touche F pour le plein écran.
- QCM auto-corrigés (Bibliothèque › Ajouter au dossier › Créer un QCM) : publiés comme un
  document, note immédiate, correction facultative, 1 à 3 essais ou illimité,
  résultats par classe, réussite par question, export CSV.
- Page « À rendre » côté élève (échéancier avec compte à rebours) + bloc sur l'accueil de la classe.
Dépôts
- Retards : fermer à la date limite ou accepter les retards (marqués), avec limite facultative.
- Rendus en binôme ou en groupe (jusqu'à 5 élèves).
- Repérage des fichiers strictement identiques déposés par des élèves différents.
- Numéro de reçu affiché à l'élève (imprimable), visible et recherchable côté enseignant.
Entre enseignants
- Co-enseignement : onglet « Collègues » d'un dossier pour le partager avec des collègues.
- Banque de ressources par matière (dossier « Banque de ressources/Matière »), jamais visible
  des élèves ; bouton « Copier vers… » pour reprendre un document dans ses dossiers.
- Import d'une arborescence .zip (TP Works, clé USB, partage réseau…).
Différenciation
- Groupes dans une classe (menu Groupes) : groupe A/B, spécialité, tiers-temps…, avec code
  facultatif. Publications, dépôts et annonces peuvent cibler un groupe.
- Mode examen : temps supplémentaire (tiers-temps) pour les élèves d'un groupe.
Connexion ENT
- Création automatique du compte à la première connexion par l'ENT (Paramètres › Connexion
  par l'ENT) : refusée, à valider par un administrateur (recommandé) ou immédiate.
  Les comptes à valider apparaissent en haut de Comptes enseignants.
Administration et sécurité
- Double authentification (application TOTP + codes de secours), obligatoire au choix pour
  les administrateurs ou pour tous (Paramètres › Sécurité des comptes).
- Quotas de stockage par enseignant et page « Stockage » (espace disque, répartition).
- Sauvegarde automatique quotidienne ou hebdomadaire, rotation, copie vers un dossier
  externe (NAS, partage) et/ou un espace WebDAV (Nextcloud…).

APERÇU WORD / POWERPOINT (LIBREOFFICE)
--------------------------------------
- Windows / XAMPP : installez LibreOffice (libreoffice.org). Il est détecté automatiquement
  dans C:\Program Files\LibreOffice ; sinon indiquez son chemin dans config.php (TPW_SOFFICE).
- Docker (php:8.3-apache) : ajoutez à la commande de démarrage du conteneur, avant apache2-foreground :
    command -v soffice >/dev/null || (apt-get update && apt-get install -y --no-install-recommends
      libreoffice-writer-nogui libreoffice-impress-nogui libreoffice-calc-nogui
      fonts-dejavu fonts-liberation2 fonts-crosextra-carlito fonts-crosextra-caladea)
  (environ 400 Mo, installé une seule fois tant que le conteneur n'est pas recréé).
- Vérification : Administration › Paramètres › Informations techniques › Aperçu Word / PowerPoint.
- Les PDF d'aperçu sont mis en cache dans TPWebData/cache (purge automatique après 60 jours).

TÂCHES PLANIFIÉES (RECOMMANDÉ)
------------------------------
cron.php applique les purges RGPD et lance la sauvegarde automatique à l'heure prévue.
- Docker (crontab de l'hôte) :   0 * * * *  docker exec tpweb php /var/www/html/cron.php
- Windows (Planificateur) :      C:\xampp\php\php.exe C:\xampp\htdocs\TPWeb\cron.php  (toutes les heures)
Sans tâche planifiée, la sauvegarde part à la première visite de l'espace enseignant après l'heure prévue.
Pour une copie vers un NAS en Docker, montez le partage dans le conteneur (ex. volume
/mnt/nas/tpweb:/sauvegardes-externes) puis indiquez /sauvegardes-externes dans Sauvegardes.

NOUVEAUTÉS VERSION 15
---------------------
- Sélecteur de classes compact partout : « Mes classes » en premier, niveaux repliés,
  « tout le niveau », recherche.
- Bibliothèque en onglets : Contenu · Partage · Dépôt des travaux.
- Dépôt : seules les classes qui voient le dossier sont proposées, aucune cochée par défaut.
- Comptes enseignants : liste compacte avec recherche, page d'édition par compte,
  droits et dossiers en pastilles.

NOUVEAUTÉS VERSION 14
---------------------
- Mentions légales et données personnelles, journal d'activité, purges automatiques.
- Connexion ENT fiabilisée (adresse de service réglable, relance automatique, diagnostic).

NOUVEAUTÉS VERSION 13
---------------------
- Dossiers de matière automatiques et droits par enseignant.
- Corbeille : suppression restaurable pendant 30 jours (avec la publication).
- Déplacer et dupliquer des fichiers et dossiers.
- Fin d'année : sauvegarde, archivage des rendus, retrait des publications,
  remise à zéro des classes, codes et annonces, en une seule opération.
- Badges « Nouveau » (7 jours) et bloc « Dernières publications » côté élève.

NOUVEAUTÉS VERSION 12.1
-----------------------
- Classes regroupées par niveau (accueil élèves, formulaires, codes d'accès).

NOUVEAUTÉS VERSION 12
---------------------
- Liens externes (sites, Capytale, Genially…) publiés comme des documents ;
  les vidéos YouTube s'affichent directement dans le portail.
- Annonces par classe (information ou importante), avec retrait automatique.
- Copie des publications et des dépôts d'une classe vers d'autres classes.
- Menu « Affichage » : thème clair / sombre / système, taille du texte,
  lecture facilitée (police et espacements adaptés aux élèves DYS).
- Connexion des enseignants par l'ENT (CAS).

NOUVEAUTÉS VERSION 11
---------------------
- Dépôt des travaux par les élèves (date limite, formats, consigne), rendus classés
  par classe, téléchargement en .zip.
- Gestion des fichiers depuis le navigateur : envoi, création de dossiers, renommage, suppression.
- Publication programmée : dates d'ouverture et de fermeture.
- Comptes enseignants : chaque professeur gère uniquement ses dossiers.
- Recherche dans les ressources côté élève.
- Aperçu intégré des documents dans le portail.
- Mode examen : une ou plusieurs classes ne voient plus qu'un dossier, fin automatique.
- Sauvegardes et restauration depuis l'administration.

NOUVEAUTÉS VERSION 10
---------------------
- Interface entièrement refaite : sobre, institutionnelle, lisible, adaptée au mobile.
- Code réorganisé et commenté (dossier inc/).
- Nom de l'établissement paramétrable.
- Bibliothèque : arborescence repliable, « visible actuellement par » pour chaque élément.
- Taille et type affichés pour chaque document ; bouton Télécharger pour les fichiers non affichables.
- Confirmation du mot de passe, limitation des tentatives de connexion enseignant.
- Pages d'erreur claires au lieu de messages bruts.
