<?php
/**
 * Telegram — настройки и отправка. Инфраструктура готова, но к событиям пока
 * НЕ подключена (триггеры добавим отдельно). Bot API: sendMessage напрямую,
 * без сторонних сервисов — только curl к api.telegram.org.
 */
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');
$userId = $_SESSION['user_id'] ?? 1;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function tgSetSetting($pdo, $k, $v) {
    $pdo->prepare("INSERT INTO app_settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$k, $v]);
}

try {
    switch ($action) {
        case 'get':
            $s = tgGetSettings($pdo);
            $masked = $s['bot_token'] ? (mb_substr($s['bot_token'], 0, 10) . '…' . mb_substr($s['bot_token'], -4)) : '';
            $alertKeys = ['offline', 'expiry', 'ns', 'ip', 'token', 'zone', 'queue'];
            $alerts = [];
            foreach ($alertKeys as $k) $alerts[$k] = appGetSetting($pdo, 'tg_alert_' . $k, '1') === '1';
            echo json_encode([
                'success' => true,
                'has_token' => !empty($s['bot_token']),
                'bot_token_masked' => $masked,
                'chat_id' => $s['chat_id'],
                'alerts' => $alerts,
                'interval_hours' => appGetSetting($pdo, 'tg_monitor_interval_hours', '12'),
                'batch' => appGetSetting($pdo, 'tg_monitor_batch', '8'),
                'last_digest' => appGetSetting($pdo, 'tg_last_digest', ''),
            ]);
            break;

        case 'save':
            $bot  = trim($_POST['bot_token'] ?? '');
            $chat = trim($_POST['chat_id'] ?? '');
            // Пустой bot_token при сохранении = оставить прежний (чтобы не затирать маской)
            if ($bot !== '') tgSetSetting($pdo, 'telegram_bot_token', $bot);
            tgSetSetting($pdo, 'telegram_chat_id', $chat);
            // Тумблеры категорий
            foreach (['offline', 'expiry', 'ns', 'ip', 'token', 'zone', 'queue'] as $k) {
                tgSetSetting($pdo, 'tg_alert_' . $k, (isset($_POST['alert_' . $k]) && $_POST['alert_' . $k] === '1') ? '1' : '0');
            }
            // Настройки мониторинга
            $ih = (float)($_POST['interval_hours'] ?? 12); if ($ih < 0.25) $ih = 0.25;
            $bt = (int)($_POST['batch'] ?? 8); if ($bt < 1) $bt = 1; if ($bt > 50) $bt = 50;
            tgSetSetting($pdo, 'tg_monitor_interval_hours', (string)$ih);
            tgSetSetting($pdo, 'tg_monitor_batch', (string)$bt);
            logAction($pdo, $userId, 'Telegram: настройки сохранены', "chat_id: {$chat}");
            echo json_encode(['success' => true]);
            break;

        case 'test':
            // Разрешаем протестировать введённые значения ещё до «Сохранить»
            $bot  = trim($_POST['bot_token'] ?? '');
            $chat = trim($_POST['chat_id'] ?? '');
            if ($bot !== '')  tgSetSetting($pdo, 'telegram_bot_token', $bot);
            if ($chat !== '') tgSetSetting($pdo, 'telegram_chat_id', $chat);
            $s = tgGetSettings($pdo);

            // Предварительная диагностика (чтобы в логах была понятная причина)
            $pre = '';
            if (empty($s['bot_token']))      $pre = 'не задан bot_token';
            elseif (empty($s['chat_id']))    $pre = 'не задан chat_id';
            elseif (!preg_match('/^\d+:/', $s['bot_token'])) $pre = 'бот-токен неполный: нужен формат 123456789:AAE… (цифры и двоеточие в начале). Скопируйте токен от @BotFather ЦЕЛИКОМ, вместе с числом до двоеточия.';
            if ($pre !== '') {
                logAction($pdo, $userId, 'Telegram: тест НЕ отправлен', $pre);
                echo json_encode(['success' => false, 'error' => $pre]);
                break;
            }

            $r = tgSendMessage($pdo, "✅ <b>CloudPanel</b>: тестовое сообщение.\nTelegram-оповещения подключены.");
            if ($r['ok']) {
                logAction($pdo, $userId, 'Telegram: тест отправлен', "chat_id: {$s['chat_id']}");
                echo json_encode(['success' => true, 'error' => null]);
            } else {
                $e = (string)($r['error'] ?? 'неизвестная ошибка');
                // Расшифровка частых ошибок Telegram
                $hint = '';
                if (stripos($e, "can't send messages to the bot") !== false) $hint = ' → в Chat ID указан id бота, а нужен ВАШ личный chat_id. Нажмите «Определить chat_id».';
                elseif (stripos($e, 'chat not found') !== false) $hint = ' → неверный chat_id, либо вы не нажали Start у бота. Нажмите «Определить chat_id».';
                elseif (stripos($e, 'not found') !== false)      $hint = ' → неверный/неполный бот-токен (проверьте у @BotFather)';
                elseif (stripos($e, 'blocked') !== false)        $hint = ' → бот заблокирован в этом чате';
                elseif (stripos($e, 'unauthorized') !== false)   $hint = ' → бот-токен недействителен (revoked)';
                logAction($pdo, $userId, 'Telegram: тест НЕ отправлен', "Ошибка: {$e}{$hint} | chat_id: {$s['chat_id']}");
                echo json_encode(['success' => false, 'error' => $e . $hint]);
            }
            break;

        case 'get_updates':
            // Тянем chat_id из последних сообщений боту (getUpdates).
            $s = tgGetSettings($pdo);
            $bot = trim($_POST['bot_token'] ?? '');
            if ($bot === '') $bot = $s['bot_token'];
            if ($bot === '') throw new Exception('Сначала укажите бот-токен');
            $ch = curl_init("https://api.telegram.org/bot" . rawurlencode($bot) . "/getUpdates?limit=20");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $resp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (empty($resp['ok'])) throw new Exception('Telegram: ' . ($resp['description'] ?? 'не удалось получить обновления'));
            $chats = [];
            foreach ($resp['result'] as $u) {
                $chat = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? $u['my_chat_member']['chat'] ?? null;
                if (!$chat || empty($chat['id'])) continue;
                $name = trim(($chat['title'] ?? '') . ' ' . ($chat['first_name'] ?? '') . ' ' . ($chat['username'] ? '@' . $chat['username'] : ''));
                $chats[$chat['id']] = ['id' => (string)$chat['id'], 'type' => $chat['type'] ?? '', 'name' => $name ?: ('id ' . $chat['id'])];
            }
            echo json_encode(['success' => true, 'chats' => array_values($chats),
                'note' => empty($chats) ? 'Сообщений нет. Напишите боту любое сообщение (или Start) и нажмите снова.' : '']);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
