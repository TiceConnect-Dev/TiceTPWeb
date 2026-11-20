<?php
/**
 * Mentions légales et informations sur les données personnelles (RGPD, articles 12 à 14).
 * Les coordonnées se règlent dans Administration › Paramètres.
 */

require_once __DIR__ . '/inc/bootstrap.php';

release_session();
$data = settings();
$l = (isset($data['legal']) && is_array($data['legal']) ? $data['legal'] : []) + legal_defaults();
$school = school_name() !== '' ? school_name() : 'l’établissement';

/** Valeur réglée, ou mention « à compléter ». */
function legal_value($value)
{
    $value = trim((string) $value);
    return $value !== '' ? nl2br(h($value)) : '<span class="badge badge--warning">À compléter</span>';
}

page_top('Mentions légales et données personnelles');
?>
<div class="container container--text section legal">
  <?php breadcrumb([['Accueil', 'index.php'], ['Mentions légales et données personnelles', null]]); ?>
  <h1>Mentions légales et données personnelles</h1>
  <p class="lead">TPWeb est le portail pédagogique de l’établissement<?= school_name() !== '' ? ' (' . h(school_name()) . ')' : '' ?> : les enseignants y publient leurs cours et les élèves y déposent leurs travaux. Cette page explique qui en est responsable, quelles données sont traitées et quels sont vos droits.</p>

  <h2 class="h3">Éditeur et hébergement</h2>
  <dl class="meta-list">
    <div><dt>Éditeur</dt><dd><?= h($school) ?><?= $l['address'] !== '' ? '<br>' . nl2br(h($l['address'])) : '' ?></dd></div>
    <div><dt>Directeur de la publication</dt><dd><?= legal_value($l['publisher']) ?></dd></div>
    <div><dt>Hébergeur</dt><dd><?= legal_value($l['host']) ?></dd></div>
    <div><dt>Conception</dt><dd>Application TPWeb, développée et administrée par le service informatique de l’établissement</dd></div>
  </dl>

  <h2 class="h3">Responsable du traitement et contacts</h2>
  <dl class="meta-list">
    <div><dt>Responsable du traitement</dt><dd><?= legal_value($l['controller']) ?></dd></div>
    <div><dt>Contact</dt><dd><?= legal_value($l['contact']) ?></dd></div>
    <div><dt>Délégué à la protection des données</dt><dd><?= legal_value($l['dpo']) ?></dd></div>
  </dl>

  <h2 class="h3">Finalités et base légale</h2>
  <p>Les données sont traitées pour mettre à disposition des élèves les ressources pédagogiques de leur classe, permettre la remise des travaux à leurs enseignants et assurer la sécurité du service. Ce traitement relève de la mission d’intérêt public de l’établissement (article 6.1.e du RGPD).</p>

  <h2 class="h3">Données traitées et durées de conservation</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Personnes</th><th>Données</th><th>Conservation</th></tr></thead>
      <tbody>
        <tr><td>Élèves</td><td>Nom, prénom, classe, fichiers déposés (et noms des coéquipiers), date et heure du dépôt, numéro de reçu</td><td>Année scolaire en cours ; archivés à la fin de l’année puis supprimés automatiquement au bout d’un an</td></tr>
        <tr><td>Élèves</td><td>QCM : nom, prénom, classe, groupe, réponses et note</td><td>Comme les dépôts (année scolaire, puis archives un an)</td></tr>
        <tr><td>Élèves (si la liste est utilisée)</td><td>Nom, prénom, classe, groupes, identifiant ENT</td><td>Année scolaire en cours ; liste vidée en fin d’année</td></tr>
        <tr><td>Élèves</td><td>Notes, commentaires et copies annotées par les enseignants</td><td>Comme les dépôts</td></tr>
        <tr><td>Élèves connectés par l’ENT</td><td>Documents mis en favoris, séances marquées « terminées »</td><td>Année scolaire en cours ; supprimés avec la liste des élèves</td></tr>
        <tr><td>Élèves</td><td>Consultations des ressources : date, ressource, classe (et identifiant de l’élève s’il est connecté par l’ENT)</td><td>12 mois, puis suppression automatique</td></tr>
        <tr><td>Tous</td><td>Signalements (bugs, idées) : texte, nom indiqué, classe</td><td>Jusqu’à leur suppression par l’administrateur</td></tr>
        <tr><td>Élèves</td><td>Groupe choisi dans la classe (groupe de besoin, tiers-temps…)</td><td>Session du navigateur uniquement (effacé à sa fermeture)</td></tr>
        <tr><td>Enseignants</td><td>Identifiant, nom affiché, mot de passe chiffré (haché), identifiant ENT, matière et classes, adresse électronique et préférences de notification, clé de double authentification et codes de secours (chiffrés)</td><td>Jusqu’à la suppression du compte</td></tr>
        <tr><td>Tous</td><td>Journal d’activité : date, compte, action effectuée, adresse IP</td><td><?= AUDIT_MONTHS ?> mois, puis suppression automatique</td></tr>
        <tr><td>Tous</td><td>Empreinte chiffrée de l’adresse IP en cas d’échec de connexion (protection contre les attaques)</td><td>1 heure</td></tr>
      </tbody>
    </table>
  </div>
  <p>Les élèves n’ont pas de compte : aucune adresse e-mail ni aucun mot de passe d’élève n’est collecté. Aucune donnée n’est vendue, utilisée à des fins commerciales ou transmise à un tiers.</p>

  <h2 class="h3">Destinataires</h2>
  <p>Les travaux déposés et les résultats des QCM ne sont accessibles qu’aux enseignants qui gèrent le dossier concerné (y compris les collègues avec qui ce dossier est partagé) et à l’administrateur du portail. Les ressources publiées sont visibles des seules classes choisies par l’enseignant, le cas échéant protégées par un code d’accès.</p>

  <h2 class="h3">Cookies et stockage local</h2>
  <p>TPWeb n’utilise aucun cookie publicitaire ni outil de mesure d’audience. Un seul cookie, strictement nécessaire, maintient la session (accès à la classe, espace enseignant) ; il est supprimé à la fermeture du navigateur. Les préférences d’affichage (thème, taille du texte, lecture facilitée), la date de dernière visite, les favoris et la progression dans les séquences (sans connexion ENT) ainsi que les pages mises en cache pour la consultation hors ligne sont enregistrés uniquement sur votre appareil. Ces éléments ne nécessitent pas de consentement.</p>

  <h2 class="h3">Contenus de tiers</h2>
  <p>Certaines ressources sont des liens vers des sites extérieurs ou des vidéos YouTube. Les vidéos sont intégrées en mode « confidentialité renforcée » (youtube-nocookie.com) : YouTube ne reçoit des informations (dont l’adresse IP) que si vous lancez la lecture. Les sites extérieurs ouverts par un lien appliquent leurs propres règles.</p>

  <h2 class="h3">Connexion par l’ENT</h2>
  <p>Les enseignants peuvent se connecter avec leur compte de l’ENT. Le mot de passe est saisi uniquement sur le site de l’ENT ; TPWeb reçoit seulement l’identifiant de l’enseignant et n’a aucun accès aux autres données de l’ENT.</p>

  <h2 class="h3">Vos droits</h2>
  <p>Vous disposez d’un droit d’accès, de rectification, d’effacement, de limitation et d’opposition sur les données vous concernant. Pour les exercer, adressez-vous au responsable du traitement ou au délégué à la protection des données (coordonnées ci-dessus). Pour un élève mineur, la demande peut être faite par ses représentants légaux. Si vous estimez que vos droits ne sont pas respectés, vous pouvez adresser une réclamation à la CNIL (<a href="https://www.cnil.fr/fr/plaintes" target="_blank" rel="noopener">www.cnil.fr</a>).</p>

  <h2 class="h3">Sécurité</h2>
  <p>Les échanges sont chiffrés (HTTPS), les mots de passe sont stockés sous forme chiffrée, les tentatives de connexion répétées sont bloquées, les droits de chaque enseignant sont limités à ses propres dossiers et les actions sensibles sont journalisées. La double authentification peut être exigée pour les comptes enseignants. Les données sont sauvegardées régulièrement, le cas échéant sur un espace de stockage de l’établissement distinct du serveur.</p>

  <h2 class="h3">Propriété intellectuelle</h2>
  <p>Les cours et documents publiés restent la propriété de leurs auteurs. Ils sont mis à disposition pour un usage pédagogique personnel des élèves de l’établissement ; toute diffusion en dehors de ce cadre nécessite l’accord de l’auteur.</p>

  <p class="muted small">Version <?= h(TPW_VERSION) ?> de TPWeb.</p>
</div>
<?php
page_end();
