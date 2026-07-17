<?php
/**
 * Подсказки для поиска на дашборде: домены + логин аккаунта, совпавшие с запросом.
 * Отдаёт JSON для тайпхеда «Список доменов» (как выпадающий список на «Деплой»).
 * Серверная фильтрация (LIKE), поэтому масштабируется на большое число доменов.
 */
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? 1;
$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$st = $pdo->prepare("SELECT ca.domain AS d, cc.email AS a
    FROM cloudflare_accounts ca
    LEFT JOIN cloudflare_credentials cc ON cc.id = ca.account_id
    WHERE ca.user_id = ? AND ca.domain IS NOT NULL AND ca.domain <> ''
      AND (ca.domain LIKE ? OR cc.email LIKE ?)
    ORDER BY ca.domain
    LIMIT 30");
$st->execute([$userId, $like, $like]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(array_map(function ($r) {
    // Чистый логин: срезаем « #N» и хвост «'s Account» (как на «Деплой»).
    $login = preg_replace('/\s*#\d+$/u', '', (string)($r['a'] ?? ''));
    $login = preg_replace('/[\'\x{2019}]s Account$/u', '', (string)$login);
    $login = trim($login);
    return ['d' => (string)$r['d'], 'a' => $login !== '' ? $login : (string)($r['a'] ?? '')];
}, $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
