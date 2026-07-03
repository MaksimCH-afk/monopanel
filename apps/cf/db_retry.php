<?php
/**
 * [monopanel] Устойчивая запись в SQLite при конкурентном доступе.
 *
 * WAL + busy_timeout снимают большинство конфликтов, но при параллельной записи
 * (фоновые cf-queue/cf-monitor + веб-запрос перевыпуска токена/смены SSL) SQLite
 * всё равно иногда отдаёт "SQLSTATE[HY000]: General error: 5 database is locked"
 * — например, BUSY_SNAPSHOT, который busy_timeout НЕ ждёт. Тогда ждём с
 * экспоненциальным бэкоффом и пробуем снова.
 */

if (!function_exists('dbRetryOnLock')) {
    /**
     * Выполняет $fn (внутри — запись в БД) с повтором при «database is locked».
     * Возвращает результат $fn. Пробрасывает исключение, если оно не про блокировку
     * или исчерпаны попытки.
     */
    function dbRetryOnLock(callable $fn, $tries = 8) {
        $delayMs = 120;
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $locked = (stripos($msg, 'database is locked') !== false
                        || stripos($msg, 'database is busy') !== false
                        || stripos($msg, 'database table is locked') !== false);
                if (!$locked || $i >= $tries) throw $e;
                usleep($delayMs * 1000);
                $delayMs = min($delayMs * 2, 2000); // бэкофф: 120,240,…,макс 2с
            }
        }
    }
}

if (!function_exists('logActionSafe')) {
    /**
     * Запись в журнал панели с повтором при блокировке БД — иначе сбой
     * (в т.ч. сам «database is locked») не попадал бы в раздел «Логи», а был бы
     * виден только во всплывающем тосте. Пригодна к вызову в catch-блоках.
     */
    function logActionSafe($pdo, $userId, $action, $details = '') {
        try {
            return dbRetryOnLock(function () use ($pdo, $userId, $action, $details) {
                $pdo->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)")
                    ->execute([$userId, $action, $details]);
                return true;
            });
        } catch (Exception $e) {
            error_log("logActionSafe failed: " . $e->getMessage());
            return false;
        }
    }
}
