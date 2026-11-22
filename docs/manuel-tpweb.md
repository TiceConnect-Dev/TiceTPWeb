# Manuel de TPWeb 18.0

<p align="center">
  <img src="images/tpweb-cover.png" alt="TPWeb — portail de cours, QCM et rendus" width="960">
</p>

Ce guide accompagne l’installation de TPWeb 18.0, la configuration de l’établissement et l’utilisation du portail au quotidien.

[Contenu du dépôt](#contenu-du-dépôt) · [Installation](#installation) · [Configuration](#configuration-de-létablissement) · [Espace enseignant](#espace-enseignant) · [Espace élève](#espace-élève) · [Maintenance](#maintenance) · [Dépannage](#dépannage)

## Contenu du dépôt

Depuis la page GitHub du projet, utilisez **Code → Download ZIP**, ou clonez la branche `main`. Dans le dossier récupéré, `index.php`, `admin.php`, `inc/` et `assets/` doivent se trouver au même niveau.

| Chemin | Rôle |
| --- | --- |
| [`index.php`](../index.php) | Portail élève et accès aux ressources de la classe |
| [`admin.php`](../admin.php) | Espace enseignant et administration |
| [`config.php`](../config.php) | Version, chemins de données et réglages locaux |
| [`inc/`](../inc/) | Modules PHP internes, écrans d’administration et protection Apache `.htaccess` |
| [`assets/`](../assets/) | Feuille de style, JavaScript, icône et script de QR code |
| [`cron.php`](../cron.php) | Purges et sauvegardes planifiées en ligne de commande |
| [`README.txt`](../README.txt) | Guide texte livré avec le projet et historique détaillé |

TPWeb initialise ses données dans `TPWebData`, hors de la racine web. Il utilise des fichiers pour stocker ses réglages et ses données. Pour Docker ou Portainer, utilisez l’image publiée `ghcr.io/ticeconnect-dev/tpweb:latest` (ou `:office` avec LibreOffice) et montez un volume de données persistant sur `/var/TPWebData`.

## Installation

### Prérequis

- Apache avec PHP et les extensions **`mbstring`** et **`zip`**. Pour une nouvelle installation, choisissez une version de PHP 8.x encore maintenue. Vérifiez les extensions chargées par Apache et, si vous utilisez `cron.php`, par le PHP en ligne de commande.
- Un dossier de données accessible en écriture par PHP, hors du répertoire public. Les chemins se règlent dans [`config.php`](../config.php).
- En option, **LibreOffice** pour la conversion des documents bureautiques en PDF et **cURL** ou **OpenSSL** pour la connexion CAS en HTTPS.

### XAMPP sous Windows

1. Copiez les fichiers du dépôt dans `C:\xampp\htdocs\TPWeb`. Vérifiez que `admin.php`, `inc\` et `assets\` se trouvent à ce niveau.
2. Lancez le panneau XAMPP et démarrez **Apache**.
3. Ouvrez `http://localhost/TPWeb/admin.php` et créez le compte administrateur. Le mot de passe doit comporter au moins 10 caractères.
4. Vérifiez que le dossier de données se trouve dans `C:\xampp\TPWebData`. Placez vos dossiers de cours dans `C:\xampp\TPWebData\cours` ou envoyez-les depuis **Bibliothèque**.
5. Configurez une classe, publiez un document de test et ouvrez `http://IP_DU_SERVEUR/TPWeb/` depuis un poste élève. Si le poste ne peut pas se connecter, vérifiez le port d’Apache et le pare-feu Windows.

Sous Linux ou macOS, adaptez le répertoire web et le chemin de `TPWebData` à l’emplacement réel d’Apache. La valeur par défaut de `TPW_DATA` place ce dossier **deux niveaux au-dessus** du répertoire qui contient `config.php`.

### Apache sous Linux ou macOS

1. Placez les fichiers du projet dans le dossier servi par votre hôte virtuel ou dans un sous-dossier `TPWeb`.
2. Vérifiez le chemin de `TPW_DATA` dans [`config.php`](../config.php). Utilisez un emplacement hors des répertoires publics et accessible en écriture à l’utilisateur qui exécute PHP.
3. Assurez-vous qu’Apache applique [`inc/.htaccess`](../inc/.htaccess) ou bloque l’accès au répertoire `inc/` dans la configuration de l’hôte virtuel. Une requête vers `/TPWeb/inc/bootstrap.php` (ou `/inc/bootstrap.php` si TPWeb occupe la racine du site) doit être refusée.
4. Ouvrez `admin.php` pour créer le compte administrateur, puis testez le portail depuis un autre poste.

### Docker ou Portainer

Le plus simple est l’image officielle `ghcr.io/ticeconnect-dev/tpweb:latest` (voir le [README](../README.md#docker--portainer)) : Apache, PHP 8.3, extensions, limites d’envoi à 64 Mo, blocage de `inc/` et tâches planifiées horaires sont déjà réglés ; il suffit de monter `/var/TPWebData`. La variante `:office` ajoute LibreOffice. Mise à jour : re-pull de l’image.

Avec votre propre image Apache/PHP qui inclut `mbstring` et `zip`, utilisez les emplacements ci-dessous pour une application montée à la racine du site.

| Élément | Chemin dans le conteneur | Configuration |
| --- | --- | --- |
| Fichiers du dépôt | `/var/www/html` | Montez le dossier applicatif de l’hôte ou copiez-le dans votre image. |
| Données persistantes | `/var/TPWebData` | Montez un volume ou un dossier de l’hôte. Avec `config.php` dans `/var/www/html`, ce chemin correspond à `TPW_DATA` par défaut. |
| Sauvegardes externes, si utilisées | `/sauvegardes-externes`, par exemple | Montez votre partage sur ce chemin et renseignez-le dans **Sauvegardes**. |

1. Vérifiez que l’utilisateur d’Apache peut écrire dans le volume de données. Appliquez `inc/.htaccess` ou bloquez `inc/` dans la configuration Apache du conteneur.
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

### Connexion des élèves via l’ENT

1. Dans **Élèves → Importer une liste d’élèves**, importez un CSV ou un export Pronote / ENT. Le modèle propose `Nom ; Prénom ; Classe ; Groupes ; Identifiant ENT`. Vérifiez que les classes existent déjà dans TPWeb. L’identifiant ENT permet de rattacher directement l’élève à sa fiche.
2. Dans **Paramètres → Connexion par l’ENT**, activez la connexion des élèves et testez le serveur CAS. Vous pouvez autoriser un élève absent de la liste à demander l’accès en choisissant sa classe. Les enseignants concernés traitent la demande dans **Élèves**.
3. Pour créer automatiquement une fiche à la première connexion, activez l’option correspondante. TPWeb doit recevoir le nom, le prénom et une classe qu’il reconnaît. Vérifiez les attributs envoyés par votre ENT avec le test de connexion. Si les attributs manquent, activez les demandes d’accès ou importez les élèves avec leurs identifiants ENT.
4. Dans **Élèves → Accès des élèves au portail**, choisissez entre les classes visibles avec leur code éventuel et la connexion ENT obligatoire. En mode ENT obligatoire, un élève connecté accède à sa classe et à ses groupes.

## Espace enseignant

| Action | Parcours et réglages |
| --- | --- |
| **Préparer une ressource** | Dans **Bibliothèque**, ajoutez un fichier, un dossier, un lien ou importez une arborescence ZIP. TPWeb peut créer des dossiers `Classe/Matière` et les afficher dans **Mes matières** côté élève. |
| **Publier un cours** | Dans **Partage**, sélectionnez les classes ou groupes, avec des dates d’ouverture et de fermeture si besoin. L’option **Tout le niveau** permet de sélectionner les classes d’un niveau. |
| **Ouvrir un dépôt** | Dans **Dépôt des travaux**, définissez la consigne, les formats acceptés, l’échéance et les règles de retard. Les élèves peuvent rendre seuls ou en groupe jusqu’à cinq, selon le réglage. |
| **Suivre les rendus** | Dans **Dépôts des élèves**, retrouvez les fichiers par classe, recherchez un reçu et téléchargez les rendus en ZIP. TPWeb signale les fichiers strictement identiques déposés par des élèves différents. |
| **Créer un QCM** | Dans **Bibliothèque → Ajouter au dossier**, créez un QCM autocorrigé. Réglez les questions, les tentatives, la date de fermeture et la correction. Publiez le QCM comme une ressource. Les résultats et la réussite par question peuvent être exportés en CSV. |
| **Partager avec des collègues** | Utilisez l’onglet **Collègues** du dossier pour le co-enseignement. La banque de ressources par matière est réservée aux enseignants et permet de copier un document vers un autre dossier. |
| **Projeter un document** | Depuis **Bibliothèque**, utilisez **Projeter**. Les flèches gauche et droite passent d’un document à l’autre et la touche **F** active le plein écran. |
| **Organiser un examen** | Dans **Mode examen**, sélectionnez un dossier et les classes concernées, puis réglez la fin de session. Un groupe peut recevoir du temps supplémentaire. Vérifiez depuis un poste élève que seul le dossier attendu est visible pendant la session. |
| **Publier une annonce** | Dans **Annonces**, choisissez les classes ou groupes concernés, le niveau d’importance et la date de retrait si besoin. |

Les cours HTML, PDF et médias compatibles s’ouvrent dans le portail. Les ressources HTML s’affichent dans un cadre isolé. Un élément supprimé peut être restauré depuis la **Corbeille** pendant 30 jours.

### Suivre les rendus et communiquer les résultats

1. Dans **Dépôts des élèves**, ouvrez le détail du dépôt, puis **Suivi et évaluation**. TPWeb affiche les élèves qui ont rendu, les retards et les travaux manquants. Importez la liste dans **Élèves** pour identifier aussi ceux qui n’ont rien envoyé.
2. Cochez les élèves concernés puis cliquez sur **Relancer les élèves cochés**. Le rappel apparaît dans le portail de l’élève connecté par l’ENT tant que le travail manque et que le dépôt accepte encore les rendus. Vous pouvez aussi publier une annonce à la classe sans afficher de noms.
3. Saisissez la note et le commentaire, puis cliquez sur **Enregistrer les notes**. Cochez **Les élèves voient leur note et leur commentaire** pour les rendre consultables dans **Mes travaux** ou avec le numéro de reçu.

### Programmer les corrigés

- **Dépôt de travaux.** Dans **Bibliothèque → Dépôt des travaux**, sélectionnez un fichier ou un sous-dossier du dossier du dépôt comme corrigé. Fixez une date limite. Si vous acceptez les retards, renseignez aussi **Retards acceptés jusqu’au**. Le corrigé devient accessible à la fermeture effective du dépôt.
- **QCM.** Renseignez la date de fermeture, puis choisissez **Publiée automatiquement à la fermeture** dans **Correction pour l’élève**. Les autres choix sont une correction immédiate après la réponse ou la note seule.

### Consulter les statistiques et traiter les signalements

- Dans **Bibliothèque**, sélectionnez une ressource ou un dossier, puis ouvrez **Statistiques**. L’onglet affiche les consultations par classe et, pour un dossier, les documents les plus ouverts. Le suivi nominatif des ouvertures repose sur la connexion des élèves par l’ENT. Une réouverture de la même ressource dans la même session et la même classe en moins de 30 minutes ne crée pas de nouvelle consultation.
- Dans **Signaler un bug / une idée**, les enseignants peuvent envoyer et consulter leurs signalements. Les élèves disposent d’un lien en pied de page. L’administrateur voit tous les signalements, peut répondre et mettre leur statut à jour.

## Espace élève

1. Ouvrez l’adresse du portail et connectez-vous à l’ENT si l’établissement le demande. Avec l’accès par classe, choisissez votre niveau puis votre classe et saisissez le code si nécessaire. Un groupe peut demander un code supplémentaire.
2. Consultez **Mes matières** et les dernières publications, ou utilisez la recherche pour trouver une ressource.
3. Dans **À rendre**, vérifiez les échéances. Depuis le dossier du cours, ouvrez le formulaire de dépôt et sélectionnez vos fichiers.
4. Pour un travail de groupe, renseignez vos coéquipiers si le formulaire le demande. Après l’envoi, conservez le numéro de reçu. Retrouvez vos rendus et les retours publiés dans **Mes travaux** avec la connexion ENT, ou recherchez un dépôt avec son numéro de reçu.
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
| `config/` | Réglages, comptes, codes de classe, paramètres des dépôts, signalements et journaux de consultation |
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

Sans tâche planifiée, une sauvegarde due peut démarrer au prochain accès à l’espace enseignant. Le journal d’activité peut être exporté en CSV. Les durées documentées sont de **12 mois** pour le journal d’activité et les statistiques de consultation, **1 an** pour les archives de rendus, **30 jours** pour la corbeille, **60 jours** pour le cache des aperçus et **1 heure** pour les tentatives de connexion.

### Mise à jour et fin d’année

1. Planifiez l’interruption de service, arrêtez Apache ou le conteneur et sauvegardez **le dossier applicatif et `TPWebData`**.
2. Depuis la version 18.0, envoyez l’archive dans **Administration › Mise à jour** (sauvegarde automatique, réglages de `config.php` conservés) ; avec l’image Docker, faites un re-pull. Sinon, remplacez le dossier de l’application par celui de TPWeb 18.0 récupéré sur GitHub. Conservez `TPWebData` au même emplacement et reportez vos réglages locaux pertinents dans le nouveau `config.php`.
3. Redémarrez le serveur, rechargez la page et vérifiez la connexion, les publications, les codes et un dépôt de test. Pour un ancien compte migré, le manuel d’origine indique l’identifiant `admin` avec le mot de passe existant.
4. Pour la clôture scolaire, utilisez **Fin d’année** après avoir vérifié votre sauvegarde. L’opération archive les rendus et remet à zéro les classes, les codes et les annonces, avec retrait des publications.

## Dépannage

| Symptôme | Vérification |
| --- | --- |
| **Erreur PHP mentionnant `inc/bootstrap.php`** | Vérifiez que `inc/` se trouve à côté de `index.php` et que PHP peut lire `inc/bootstrap.php`. |
| **Page sans style ou boutons inactifs** | Vérifiez la présence de `assets/style.css` et `assets/app.js` sous le répertoire web de TPWeb. |
| **Impossible de créer le premier compte ou d’envoyer un fichier** | Vérifiez le chemin `TPW_DATA` et les droits d’écriture du processus Apache. Pour les envois, contrôlez aussi `upload_max_filesize` et `post_max_size`. |
| **Le portail fonctionne sur le serveur uniquement** | Vérifiez l’adresse utilisée depuis le poste élève, le port Apache ou Docker, le pare-feu et l’accès au réseau local. |
| **Les aperçus bureautiques restent en texte** | Vérifiez si PHP trouve et peut exécuter `soffice`. Consultez **Paramètres → Informations techniques**. |
| **La connexion ENT boucle ou échoue** | Vérifiez l’adresse de service déclarée auprès de l’ENT, le serveur CAS et le diagnostic de **Tester la connexion**. |
| **Un élève ne voit pas un document** | Vérifiez le partage classe/groupe, les dates de publication, le code d’accès et l’activation éventuelle du mode examen. |
| **Un corrigé de dépôt reste caché** | Vérifiez la date limite et, si les retards sont acceptés, la date de fin des retards. |
| **L’élève connecté n’accède pas à sa classe** | Vérifiez son identifiant ENT et la classe de sa fiche. Pour un nouvel élève, examinez les demandes dans **Élèves** et les attributs CAS transmis. |
| **Une sauvegarde planifiée manque** | Vérifiez le volume de données, l’espace disque, la destination externe et la dernière exécution de `cron.php`. |

### Historique

<details>
<summary>Versions 10 à 18.0</summary>

| Version | Principales évolutions |
| --- | --- |
| **18.0** | Tableau de bord, annotation des copies, séquences, banque de questions et tirage aléatoire, favoris et rappels élèves, application installable, duplication pour l’année suivante, apparence, courriels SMTP, mise à jour depuis l’interface et image Docker. |
| **17.0** | Suivi et relance des élèves, notes et commentaires publiables, corrigés à la fermeture, statistiques de consultation, inscription des élèves via l’ENT et signalements. |
| **16.0** | Aperçu bureautique et projection, QCM autocorrigés, échéancier, retards et rendus de groupe, co-enseignement, groupes, double authentification, quotas et sauvegardes planifiées. |
| **15** | Sélecteur de classes compact, bibliothèque en onglets et gestion améliorée des comptes et des dépôts. |
| **14** | Mentions légales, journal, purges et diagnostic de connexion ENT. |
| **13** | Dossiers par matière, droits par enseignant, corbeille et opération de fin d’année. |
| **12.1** | Regroupement des classes par niveau. |
| **12** | Liens et vidéos, annonces, réglages de lecture et accès ENT. |
| **11** | Dépôt des travaux, publication programmée, recherche, aperçus, examens et sauvegardes. |
| **10** | Nouvelle interface mobile, nom d’établissement configurable et réorganisation du code. |

</details>

[Retour à la présentation du projet](../README.md) · [Consulter le guide texte d’origine](../README.txt)
