<?php
// Авторизация отключена (single-user) — открываем дашборд сразу.
// config.php (через dashboard.php) сам создаст БД при первом запуске.
header('Location: dashboard.php');
exit;
