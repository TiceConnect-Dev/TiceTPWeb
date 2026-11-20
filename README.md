<h1 align="center">
  <img src="docs/images/tpweb-cover.png" alt="TPWeb — Portail pédagogique" width="1200">
</h1>

<p align="center">
  <strong>Version 17.0</strong><br>
  Un portail pédagogique auto-hébergé pour les collèges et les lycées.<br>
  Les enseignants publient leurs supports par classe. Les élèves les consultent et rendent leurs travaux depuis leur navigateur.
</p>

<p align="center">
  <a href="#prise-en-main">Prise en main</a> ·
  <a href="#nouveautés-de-la-version-170">Nouveautés 17.0</a> ·
  <a href="#fonctionnalités">Fonctionnalités</a> ·
  <a href="#installation">Installation</a> ·
  <a href="#administration">Administration</a> ·
  <a href="docs/manuel-tpweb.md">Manuel complet</a>
</p>

## Prise en main

| Qui ? | Où ? | Pour faire quoi ? |
| --- | --- | --- |
| **Élèves** | Accueil du portail | Choisir un niveau et une classe, saisir un code si demandé, consulter les cours, répondre aux QCM et déposer ses travaux. |
| **Enseignants** | Espace enseignant (`admin.php`) | Organiser sa bibliothèque, publier des documents pour ses classes, projeter un support et récupérer les rendus. |
| **Administration** | Paramètres de l’espace enseignant | Configurer l’établissement, les classes, les comptes, les droits, la connexion ENT et les sauvegardes. |

## Nouveautés de la version 17.0

| Fonction | Ce que vous pouvez faire |
| --- | --- |
| **Suivi des rendus** | Distinguer les travaux reçus et manquants à partir de la liste de classe, relancer les élèves et publier une note ou un commentaire. |
| **Corrigés programmés** | Publier un fichier ou un sous-dossier à la fermeture d’un dépôt, ou différer la correction d’un QCM jusqu’à sa fermeture. |
| **Statistiques de consultation** | Voir les ouvertures par classe, les ressources les plus consultées et le suivi nominatif des élèves connectés par l’ENT. |
| **Première connexion ENT des élèves** | Traiter les demandes d’accès depuis **Élèves** ou activer la création automatique si l’ENT transmet le nom, le prénom et une classe reconnue. |
| **Signalements** | Envoyer un bug ou une idée depuis le portail. L’administration peut répondre et mettre à jour le statut. |

## Fonctionnalités

- **Ressources et publications.** Import d’arborescences ZIP, aperçu intégré des cours HTML, PDF et médias, publication programmée par classe ou groupe et partage de dossiers entre enseignants.
- **Travaux à rendre.** Échéances, dépôts individuels ou en groupe, gestion des retards, reçu imprimable et téléchargement groupé des rendus.
- **Travail en classe.** QCM autocorrigés avec résultats exportables en CSV, projection plein écran et mode examen avec durée adaptée pour les groupes concernés.
- **Lecture et navigation.** Recherche dans les ressources, interface adaptée aux mobiles, thème clair ou sombre et réglages de lisibilité.
- **Comptes et stockage.** Rôles et droits par enseignant, connexion ENT via CAS pour les enseignants et les élèves, double authentification TOTP pour les comptes enseignants, quotas et sauvegardes planifiées.

> [!TIP]
> Avec LibreOffice installé sur le serveur, TPWeb convertit les documents Word, PowerPoint et les tableurs en PDF pour les afficher dans le portail.

## Installation

Récupérez la branche `main` via **Code → Download ZIP** ou clonez le dépôt avec Git. Le dépôt contient les pages PHP, les modules dans [`inc/`](inc/) et les ressources d’interface dans [`assets/`](assets/). TPWeb stocke ses données dans `TPWebData` et fonctionne sans serveur de base de données.

### Démarrage avec XAMPP

1. Décompressez le ZIP et placez le contenu du projet dans `C:\xampp\htdocs\TPWeb`. Le fichier `admin.php` doit se trouver directement dans ce dossier.
2. Démarrez **Apache** depuis le panneau XAMPP, puis ouvrez [l’espace enseignant en local](http://localhost/TPWeb/admin.php).
3. Créez le compte administrateur. Configurez le nom de l’établissement, les niveaux et les classes.
4. Ajoutez des ressources dans **Bibliothèque** et publiez-les pour une classe.
5. Depuis un poste élève, ouvrez `http://IP_DU_SERVEUR/TPWeb/` sur le réseau local et vérifiez que la ressource apparaît.

Ces chemins correspondent à XAMPP sous Windows. Si votre installation utilise un autre emplacement pour ses données, indiquez-le dans [`config.php`](config.php). Vérifiez que les extensions PHP `mbstring` et `zip` sont activées.

> [!IMPORTANT]
> Conservez `TPWebData` hors du répertoire servi par Apache. Avec les chemins XAMPP ci-dessus, TPWeb le crée dans `C:\xampp\TPWebData`. Il contient les cours, les rendus, les comptes et les réglages.

## Administration

| Besoin | Où commencer |
| --- | --- |
| **Configurer l’ENT** | Ouvrez **Paramètres → Connexion par l’ENT**. Le manuel détaille le service CAS à déclarer et la liaison des comptes. |
| **Planifier les sauvegardes** | Configurez la fréquence et la destination dans **Sauvegardes**, puis planifiez [`cron.php`](cron.php) en ligne de commande. |
| **Mettre à jour TPWeb** | Sauvegardez le dossier de l’application et `TPWebData`, puis suivez la procédure de mise à jour du manuel. |
| **Configurer les mentions légales** | Renseignez les informations de l’établissement affichées dans le portail. Le manuel détaille le journal d’activité et les durées de conservation des données. |

Le [manuel complet](docs/manuel-tpweb.md) détaille aussi l’installation sous Apache, Docker ou Portainer, les réglages PHP, la connexion ENT des élèves et l’exécution des tâches planifiées.
