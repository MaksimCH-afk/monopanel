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
    function dbRetryOnLock(callable $fn, $tries = 12) {
        $delayMs = 100;
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $locked = (stripos($msg, 'database is locked') !== false
                        || stripos($msg, 'database is busy') !== false
                        || stripos($msg, 'database table is locked') !== false);
                if (!$locked || $i >= $tries) throw $e;
                // Бэкофф с джиттером (чтобы конкуренты не били в одну точку): ~100,200,…,макс 3с.
                usleep(($delayMs + random_int(0, 100)) * 1000);
                $delayMs = min($delayMs * 2, 3000);
            }
        }
    }
}

if (!function_exists('dbImmediateTxn')) {
    /**
     * Короткая транзакция с write-локом сразу (BEGIN IMMEDIATE), в ретрае на блокировку.
     *
     * Зачем: паттерн «SELECT → потом INSERT/UPDATE» в одном соединении под непрерывными
     * фоновыми записями даёт SQLITE_BUSY_SNAPSHOT (read-снапшот устарел к моменту записи),
     * который busy_timeout НЕ ждёт — падает сразу. BEGIN IMMEDIATE берёт write-лок в начале
     * (ждёт его до busy_timeout), поэтому окна устаревания снапшота нет. Для upsert’ов на
     * горячих путях (деплой/привязка). $fn выполняет запись; commit/rollback — здесь.
     */
    function dbImmediateTxn($pdo, callable $fn) {
        return dbRetryOnLock(function () use ($pdo, $fn) {
            $started = false;
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                $started = true;
                $result = $fn();
                $pdo->exec('COMMIT');
                $started = false;
                return $result;
            } catch (Throwable $e) {
                // BEGIN мог не стартовать (лок) — тогда транзакции нет, ROLLBACK не нужен.
                if ($started) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {} }
                throw $e;
            }
        });
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
