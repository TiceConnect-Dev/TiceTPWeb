<?php
/**
 * Signalements : bugs et demandes de fonctionnalités (enseignants et élèves).
 * config/feedback.json : liste de { id, type, title, text, page, author, role, class, time, status, replies: [...] }
 */

function feedback_types()
{
    return ['bug' => 'Bug / problème', 'idea' => 'Idée / demande de fonctionnalité', 'question' => 'Question'];
}

function feedback_statuses()
{
    return ['new' => ['Nouveau', 'info'], 'progress' => ['En cours', 'warning'], 'done' => ['Résolu', 'success'], 'rejected' => ['Non retenu', '']];
}

function feedback_all()
{
    $list = load_json(TPW_STATE . '/feedback.json', []);
    foreach ($list as $i => $f) {
        $list[$i] = (array) $f + ['id' => '', 'type' => 'bug', 'title' => '', 'text' => '', 'page' => '', 'author' => '', 'role' => '', 'class' => '',
                                  'time' => 0, 'status' => 'new', 'replies' => []];
    }
    usort($list, function ($a, $b) { return $b['time'] - $a['time']; });
    return $list;
}

function feedback_save(array $list)
{
    atomic_json(TPW_STATE . '/feedback.json', array_values($list));
}

/** Ajoute un signalement ; retourne un message d'erreur ou ''. */
function feedback_add($type, $title, $text, $page, $author, $role, $class = '')
{
    $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
    $text = trim(str_replace("\r", '', (string) $text));
    if (!isset(feedback_types()[$type])) $type = 'bug';
    if ($title === '' || mb_strlen($title) > 150) return 'Indiquez un titre (150 caractères maximum).';
    if (mb_strlen($text) > 4000) return 'Description trop longue (4000 caractères maximum).';
    $list = feedback_all();
    if (count($list) >= 2000) array_pop($list);
    array_unshift($list, [
        'id' => bin2hex(random_bytes(5)), 'type' => $type, 'title' => mb_substr($title, 0, 150), 'text' => $text,
        'page' => mb_substr((string) $page, 0, 300), 'author' => mb_substr((string) $author, 0, 80), 'role' => $role,
        'class' => mb_substr((string) $class, 0, 80), 'time' => time(), 'status' => 'new', 'replies' => [],
    ]);
    feedback_save($list);
    if (function_exists('mail_on_feedback')) mail_on_feedback(feedback_types()[$type], $title, $author, $role);
    return '';
}

function feedback_new_count()
{
    return count(array_filter(feedback_all(), function ($f) { return $f['status'] === 'new'; }));
}
