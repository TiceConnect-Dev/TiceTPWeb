# Manuel de TPWeb 16

<p align="center">
  <img src="images/tpweb-cover.png" alt="TPWeb 16 — portail de cours, QCM et rendus" width="960">
</p>

Ce guide accompagne l’installation de TPWeb 16.0, la configuration de l’établissement et l’utilisation du portail au quotidien.

[État du dépôt](#état-du-dépôt) · [Installation](#installation) · [Configuration](#configuration-de-létablissement) · [Espace enseignant](#espace-enseignant) · [Espace élève](#espace-élève) · [Maintenance](#maintenance) · [Dépannage](#dépannage)

## État du dépôt

> [!IMPORTANT]
> La branche `main` contient les pages PHP principales et la documentation. Pour démarrer l’application, il faut récupérer les dossiers `inc/` et `assets/` **dans la distribution complète de TPWeb 16.0**. Sans `inc/bootstrap.php`, l’accueil, l’administration et les tâches planifiées ne démarrent pas.

Les pages présentes dans le dépôt référencent directement les fichiers suivants.

| Dossier | Fichiers recherchés | Utilisation |
| --- | --- | --- |
| `inc/` | `bootstrap.php`, `backup.php`, `cas.php`, `trash.php`, `totp.php`, `autobackup.php` | Initialisation, authentification, sauvegardes et maintenance |
| `inc/admin/` | `actions.php`, `views_library.php`, `views_students.php`, `views_admin.php`, `views_quiz.php` | Actions et écrans de l’espace enseignant |
| `assets/` | `style.css`, `app.js`, `favicon.svg` | Mise en page, interactions et icône du portail |

Cette liste est établie à partir des références visibles. Récupérez **les deux dossiers complets**, y compris leurs éventuels fichiers cachés et dépendances internes. Le dépôt actuel ne fournit aucun `Dockerfile` ni fichier Compose.

## Installation

### Prérequis

- Apache avec PHP et les extensions **`mbstring`** et **`zip`**. Le manuel d’origine annonce une compatibilité à partir de PHP 7.0. Pour une nouvelle installation, utilisez une version de PHP encore maintenue.
- Un dossier de données accessible en écriture par PHP, hors du répertoire public. Les chemins se règlent dans [`config.php`](../config.php).
- En option, **LibreOffice** pour la conversion des documents bureautiques en PDF et **cURL** ou **OpenSSL** pour la connexion CAS en HTTPS.

### XAMPP sous Windows

1. Copiez la **distribution complète** dans `C:\xampp\htdocs\TPWeb`. Vérifiez que `admin.php`, `inc\` et `assets\` se trouvent à ce niveau.
2. Lancez le panneau XAMPP et démarrez **Apache**.
3. Ouvrez `http://localhost/TPWeb/admin.php` et créez le compte administrateur. Le mot de passe doit comporter au moins 10 caractères.
4. Vérifiez que le dossier de données se trouve dans `C:\xampp\TPWebData`. Placez vos dossiers de cours dans `C:\xampp\TPWebData\cours` ou envoyez-les depuis **Bibliothèque**.
5. Configurez une classe, publiez un document de test et ouvrez `http://IP_DU_SERVEUR/TPWeb/` depuis un poste élève. Si le poste ne peut pas se connecter, vérifiez le port d’Apache et le pare-feu Windows.

Sous Linux ou macOS, adaptez le répertoire web et le chemin de `TPWebData` à l’emplacement réel d’Apache. La valeur par défaut de `TPW_DATA` place ce dossier **deux niveaux au-dessus** du répertoire qui contient `config.php`.

### Docker ou Portainer

Avec une image Apache/PHP qui inclut `mbstring` et `zip`, utilisez les emplacements ci-dessous pour une application montée à la racine du site.

| Élément | Chemin dans le conteneur | Configuration |
| --- | --- | --- |
| Distribution complète | `/var/www/html` | Montez le dossier applicatif de l’hôte ou copiez-le dans votre image. |
| Données persistantes | `/var/TPWebData` | Montez un volume ou un dossier de l’hôte. Avec `config.php` dans `/var/www/html`, ce chemin correspond à `TPW_DATA` par défaut. |
| Sauvegardes externes, si utilisées | `/sauvegardes-externes`, par exemple | Montez votre partage sur ce chemin et renseignez-le dans **Sauvegardes**. |

1. Vérifiez que l’utilisateur d’Apache peut écrire dans le volume de données et que le dossier interne `inc/` est protégé de l’accès HTTP direct.
2. Publiez le port HTTP du conteneur sur le port choisi pour le réseau local, puis ouvrez `http://IP_DU_SERVEUR:PORT/admin.php`.
3. Créez le compte administrateur et vérifiez les extensions avec `docker exec tpweb php -m`, en remplaçant `tpweb` par le nom réel du conteneur.
4. En cas d’exposition à Internet, prévoyez HTTPS et les règles nécessaires de pare-feu avant la mise en service.

### Taille des fichiers et aperçus

Dans le `php.ini` utilisé par Apache, ajustez les limites au besoin. Pour autoriser un fichier de 64 Mio, prévoyez une marge sur la taille totale de la requête.

```ini
upload_max_filesize = 64M
post_max_size = 80M
```

Redémarrez Apache après modification. Si un proxy inverse se trouve devant TPWeb, vérifiez aussi sa limite d’envoi.

Pour les aperçus Word, PowerPoint et tableurs, installez LibreOffice sur la machine qui exécute PHP. En conteneur, ajoutez-le à l’image utilisée par TPWeb. Si la détection automatique échoue, indiquez le chemin de `soffice` avec `TPW_SOFFICE` dans `config.php`, puis vérifiez **Paramètres → Informations techniques → Aperçu Word / PowerPoint**. TPWeb propose un aperçu texte simplifié quand la conversion PDF est indisponible.

## Configuration de l’établissement

1. Dans **Paramètres**, renseignez le nom de l’établissement affiché en haut du portail.
2. Dans **Niveaux et classes**, saisissez un niveau puis ses classes en retrait. Une classe seule peut être déclarée sans niveau.
3. Dans **Comptes enseignants**, créez les comptes et attribuez les matières, classes et dossiers. Les droits permettent de régler la publication, l’envoi et la gestion des fichiers, les dépôts, les annonces, les codes, les groupes et le mode examen. Le choix des classes par l’enseignant est configurable.
4. Dans **Codes d’accès**, définissez les codes de classe si nécessaire. Dans **Groupes**, créez des sous-ensembles comme « Groupe A » ou « Tiers-temps ». Un groupe peut avoir son propre code.
5. Dans **Paramètres**, renseignez les informations de la page **Mentions légales et données personnelles**, notamment les contacts de l’établissement, du DPO et de l’hébergeur.

Exemple de saisie des niveaux et des classes.

```text
Seconde
    2GT1
    2GT2
Terminale
    TGA
    TSTI2D
BTS SIO 1
```

### Connexion enseignante via l’ENT

1. Ouvrez **Paramètres → Connexion par l’ENT**, puis copiez l’adresse de service indiquée par TPWeb.
2. Demandez à l’administrateur ENT de déclarer cette adresse dans le connecteur CAS et de vous communiquer l’adresse du serveur CAS.
3. Renseignez le serveur CAS, enregistrez et utilisez **Tester la connexion** avant d’activer le bouton ENT.
4. Dans **Mon compte**, l’enseignant se connecte une première fois avec son compte TPWeb, puis utilise **Lier mon compte ENT**.
5. Si vous activez la création de comptes au premier accès ENT, choisissez leur mode de validation dans les paramètres. Les comptes en attente apparaissent dans **Comptes enseignants**.

Conservez un accès administrateur local pour intervenir si le service CAS est indisponible. La double authentification TOTP et les codes de secours se règlent dans **Paramètres → Sécurité des comptes**.

## Espace enseignant

| Action | Parcours et réglages |
| --- | --- |
| **Préparer une ressource** | Dans **Bibliothèque**, ajoutez un fichier, un dossier, un lien ou importez une arborescence ZIP. TPWeb peut créer des dossiers `Classe/Matière` et les afficher dans **Mes matières** côté élève. |
| **Publier un cours** | Dans **Partage**, sélectionnez les classes ou groupes, avec des dates d’ouverture et de fermeture si besoin. L’option **Tout le niveau** permet de sélectionner les classes d’un niveau. |
| **Ouvrir un dépôt** | Dans **Dépôt des travaux**, définissez la consigne, les formats acceptés, l’échéance et les règles de retard. Les élèves peuvent rendre seuls ou en groupe jusqu’à cinq, selon le réglage. |
| **Suivre les rendus** | Dans **Dépôts des élèves**, retrouvez les fichiers par classe, recherchez un reçu et téléchargez les rendus en ZIP. TPWeb signale les fichiers strictement identiques déposés par des élèves différents. |
| **Créer un QCM** | Dans **Bibliothèque → Ajouter au dossier → Créer un QCM**, configurez les questions, les tentatives et l’affichage de la correction. Publiez le QCM comme une ressource. Les résultats et la réussite par question peuvent être exportés en CSV. |
| **Partager avec des collègues** | Utilisez l’onglet **Collègues** du dossier pour le co-enseignement. La banque de ressources par matière est réservée aux enseignants et permet de copier un document vers un autre dossier. |
| **Projeter un document** | Depuis **Bibliothèque**, utilisez **Projeter**. Les flèches gauche et droite passent d’un document à l’autre et la touche **F** active le plein écran. |
| **Organiser un examen** | Dans **Mode examen**, sélectionnez un dossier et les classes concernées, puis réglez la fin de session. Un groupe peut recevoir du temps supplémentaire. Vérifiez depuis un poste élève que seul le dossier attendu est visible pendant la session. |
| **Publier une annonce** | Dans **Annonces**, choisissez les classes ou groupes concernés, le niveau d’importance et la date de retrait si besoin. |

Les cours HTML, PDF et médias compatibles s’ouvrent dans le portail. Les ressources HTML s’affichent dans un cadre isolé. Un élément supprimé peut être restauré depuis la **Corbeille** pendant 30 jours.

## Espace élève

1. Ouvrez l’adresse du portail, choisissez votre niveau puis votre classe et saisissez le code si demandé. Un groupe peut demander un code supplémentaire.
2. Consultez **Mes matières** et les dernières publications, ou utilisez la recherche pour trouver une ressource.
3. Dans **À rendre**, vérifiez les échéances. Depuis le dossier du cours, ouvrez le formulaire de dépôt et sélectionnez vos fichiers.
4. Après l’envoi, conservez le numéro de reçu. Pour un travail de groupe, renseignez vos coéquipiers si le formulaire le demande.
5. Pour un QCM, renseignez votre identité, répondez puis validez pour obtenir votre note. La correction s’affiche selon le réglage de l’enseignant.

Le menu **Affichage** permet de modifier le thème, la taille du texte et les réglages de lecture.

## Maintenance

### Données et destinations de sauvegarde

| Dossier sous `TPWebData/` | Contenu |
| --- | --- |
| `cours/` | Cours et fichiers publiés |
| `rendus/` | Travaux déposés par les élèves |
| `corbeille/` | Fichiers et dossiers supprimés |
| `archives/` | Rendus archivés en fin d’année |
| `sauvegardes/` | Archives créées par TPWeb |
| `config/` | Réglages, comptes, codes de classe et paramètres des dépôts |
| `cache/` | Aperçus bureautiques convertis en PDF |

> [!IMPORTANT]
> Gardez `TPWebData` hors du répertoire public et du dépôt Git. Conservez une copie indépendante de ces données avant une mise à jour, une restauration ou une opération de fin d’année.

Dans **Sauvegardes**, configurez une fréquence quotidienne ou hebdomadaire, la rotation et, si nécessaire, une copie externe vers un NAS, un dossier monté ou un espace WebDAV. Utilisez **Stockage** pour vérifier la place disponible et les quotas enseignants. Vérifiez régulièrement qu’une archive contient les données attendues et qu’elle peut être restaurée sur une installation de test.

### Tâches planifiées

[`cron.php`](../cron.php) s’exécute en ligne de commande. Il applique les purges et lance les sauvegardes dues. Une exécution horaire permet d’effectuer ces opérations indépendamment des visites sur le portail.

**Docker**, dans la crontab d’un compte de l’hôte qui peut exécuter Docker.

```cron
0 * * * * docker exec tpweb php /var/www/html/cron.php
```

**Windows**, dans le Planificateur de tâches, choisissez une répétition horaire. Utilisez `C:\xampp\php\php.exe` comme programme et `C:\xampp\htdocs\TPWeb\cron.php` comme argument.

Sans tâche planifiée, le manuel d’origine prévoit le démarrage d’une sauvegarde due lors du prochain accès à l’espace enseignant. Le journal d’activité peut être exporté en CSV. Les durées documentées sont de **12 mois** pour le journal, **1 an** pour les archives de rendus, **30 jours** pour la corbeille, **60 jours** pour le cache des aperçus et **1 heure** pour les tentatives de connexion.

### Mise à jour et fin d’année

1. Planifiez l’interruption de service, arrêtez Apache ou le conteneur et sauvegardez **le dossier applicatif et `TPWebData`**.
2. Remplacez l’application par la distribution complète. Conservez `TPWebData` au même emplacement et reportez vos réglages locaux pertinents dans le nouveau `config.php`.
3. Redémarrez le serveur, rechargez la page et vérifiez la connexion, les publications, les codes et un dépôt de test. Pour un ancien compte migré, le manuel d’origine indique l’identifiant `admin` avec le mot de passe existant.
4. Pour la clôture scolaire, utilisez **Fin d’année** après avoir vérifié votre sauvegarde. L’opération archive les rendus et remet à zéro les classes, les codes et les annonces, avec retrait des publications.

## Dépannage

| Symptôme | Vérification |
| --- | --- |
| **Erreur PHP mentionnant `inc/bootstrap.php`** | Vérifiez que le dossier `inc/` de la distribution complète a été copié à côté de `index.php`. |
| **Page sans style ou boutons inactifs** | Vérifiez la présence de `assets/style.css` et `assets/app.js` sous le répertoire web de TPWeb. |
| **Impossible de créer le premier compte ou d’envoyer un fichier** | Vérifiez le chemin `TPW_DATA` et les droits d’écriture du processus Apache. Pour les envois, contrôlez aussi `upload_max_filesize` et `post_max_size`. |
| **Le portail fonctionne sur le serveur uniquement** | Vérifiez l’adresse utilisée depuis le poste élève, le port Apache ou Docker, le pare-feu et l’accès au réseau local. |
| **Les aperçus bureautiques restent en texte** | Vérifiez si PHP trouve et peut exécuter `soffice`. Consultez **Paramètres → Informations techniques**. |
| **La connexion ENT boucle ou échoue** | Vérifiez l’adresse de service déclarée auprès de l’ENT, le serveur CAS et le diagnostic de **Tester la connexion**. |
| **Un élève ne voit pas un document** | Vérifiez le partage classe/groupe, les dates de publication, le code d’accès et l’activation éventuelle du mode examen. |
| **Une sauvegarde planifiée manque** | Vérifiez le volume de données, l’espace disque, la destination externe et la dernière exécution de `cron.php`. |

### Historique

<details>
<summary>Versions 10 à 16.0</summary>

| Version | Principales évolutions |
| --- | --- |
| **16.0** | Aperçu bureautique et projection, QCM autocorrigés, échéancier, retards et rendus de groupe, co-enseignement, groupes, double authentification, quotas et sauvegardes planifiées. |
| **15** | Sélecteur de classes compact, bibliothèque en onglets et gestion améliorée des comptes et des dépôts. |
| **14** | Mentions légales, journal, purges et diagnostic de connexion ENT. |
| **13** | Dossiers par matière, droits par enseignant, corbeille et opération de fin d’année. |
| **12.1** | Regroupement des classes par niveau. |
| **12** | Liens et vidéos, annonces, réglages de lecture et accès ENT. |
| **11** | Dépôt des travaux, publication programmée, recherche, aperçus, examens et sauvegardes. |
| **10** | Nouvelle interface mobile, nom d’établissement configurable et réorganisation du code. |

</details>

[Retour à la présentation du projet](../README.md) · [Consulter le manuel d’origine](../README.txt)
