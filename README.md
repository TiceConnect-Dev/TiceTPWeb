<h1 align="center">
  <img src="docs/images/tpweb-cover.png" alt="TPWeb — Portail pédagogique" width="1200">
</h1>

<p align="center">
  <strong>Version 18.0</strong><br>
  Un portail pédagogique auto-hébergé pour les collèges et les lycées.<br>
  Les enseignants publient leurs supports par classe. Les élèves les consultent et rendent leurs travaux depuis leur navigateur.
</p>

<p align="center">
  <a href="#prise-en-main">Prise en main</a> ·
  <a href="#nouveautés-de-la-version-180">Nouveautés 18.0</a> ·
  <a href="#docker--portainer">Docker</a> ·
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

## Nouveautés de la version 18.0

| Fonction | Ce que vous pouvez faire |
| --- | --- |
| **Tableau de bord** | Retrouver à l’ouverture de l’espace enseignant les dépôts en cours et leur taux de rendu, les nouveautés de la semaine, les contenus les plus consultés et ce qui est à traiter. |
| **Annotation des copies** | Corriger un rendu PDF, image ou document Office au stylo, au surligneur, au texte et avec ✓ / ✗. L’élève récupère sa copie corrigée en PDF dans « Mes travaux ». |
| **Séquences** | Transformer un dossier en séances numérotées, en accès libre ou débloquées une à une, avec « J’ai terminé » et suivi de la progression. |
| **Banque de questions** | Reprendre des questions d’autres QCM et tirer au hasard N questions différentes pour chaque élève. |
| **Côté élèves** | Nouveautés depuis la dernière visite, rappel la veille d’une échéance, rubrique « À rendre », favoris et application installable consultable hors ligne. |
| **Année suivante** | Dupliquer un cours en gardant ses réglages de partage, avec les dates décalées d’un an. |
| **Apparence et courriels** | Logo, couleur et message d’accueil ; notifications SMTP aux enseignants (nouveau rendu, bilan de fermeture, signalements). |
| **Mise à jour** | Installer une nouvelle version depuis l’interface avec sauvegarde automatique, ou re-puller l’image Docker. |

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

### Docker / Portainer

Une image est publiée automatiquement par GitHub Actions à chaque mise à jour de `main` :

| Image | Contenu |
| --- | --- |
| `ghcr.io/ticeconnect-dev/tpweb:latest` | Apache + PHP 8.3 (amd64 et arm64) |
| `ghcr.io/ticeconnect-dev/tpweb:office` | Même image avec LibreOffice : aperçus et annotation des documents Word, PowerPoint et tableurs (amd64) |
| `ghcr.io/ticeconnect-dev/tpweb:18.0` | Version figée (idem `18.0-office`) |

Stack Portainer (ou `docker compose up -d` avec [`docker-compose.yml`](docker-compose.yml)) :

```yaml
services:
  tpweb:
    image: ghcr.io/ticeconnect-dev/tpweb:latest
    container_name: tpweb
    restart: unless-stopped
    ports:
      - "8080:80"
    volumes:
      - /opt/tpweb/TPWebData:/var/TPWebData
    environment:
      TZ: Europe/Paris
```

- Toutes les données (cours, rendus, comptes, réglages, sauvegardes) sont dans le volume `/var/TPWebData` ; le code de l’image est en lecture seule.
- Les tâches planifiées (`cron.php` : purges, sauvegardes automatiques, courriels) tournent toutes les heures dans le conteneur. `TPW_CRON=0` pour les désactiver.
- Mise à jour : **Re-pull image** puis redéploiement de la stack dans Portainer.
- Derrière un proxy inverse (Nginx Proxy Manager), l’adresse IP réelle des visiteurs est prise en compte.

> [!NOTE]
> Si le dépôt est privé, le paquet `ghcr.io/ticeconnect-dev/tpweb` l’est aussi : déclarez le registre `ghcr.io` dans Portainer avec un jeton GitHub (`read:packages`), ou rendez le paquet public dans GitHub › Packages › tpweb › Package settings.

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
| **Mettre à jour TPWeb** | **Administration › Mise à jour** : envoyez le .zip de la nouvelle version (sauvegarde automatique avant). Avec Docker : re-pull de l’image. |
| **Configurer les mentions légales** | Renseignez les informations de l’établissement affichées dans le portail. Le manuel détaille le journal d’activité et les durées de conservation des données. |

Le [manuel complet](docs/manuel-tpweb.md) détaille aussi l’installation sous Apache, Docker ou Portainer, les réglages PHP, la connexion ENT des élèves et l’exécution des tâches planifiées.
