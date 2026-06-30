# cf — Cloudflare Panel

| | |
|---|---|
| **Назначение** | Управление доменами, SSL, DNS, токенами Cloudflare |
| **Стек** | PHP 8.1 + Apache |
| **Порт** | **3331** (исходный был 1000) |
| **БД** | SQLite `cloudflare_panel.db` |
| **Авторизация** | нет (single-user) |

## Куда положить код

Исходники приложения (PHP-файлы, `index.php`, `.htaccess` и т.д.) кладутся
прямо в эту папку — `apps/cf/`. При сборке образа содержимое копируется в
`DocumentRoot` Apache (`/var/www/html`).

## Данные

`cloudflare_panel.db` **содержит API-токены Cloudflare** — в репозиторий не идёт.
Положите её локально в `data/cf/cloudflare_panel.db` (см. корневой README, раздел
«Перенос данных»). При старте `entrypoint.sh` создаёт симлинк на неё в корне кода.

## Что изменено по сравнению с оригиналом

- Apache слушает **3331** вместо 1000 (правка `ports.conf` в `Dockerfile`).
- БД вынесена в том `/data/cf` через симлинк.
- **Встраивание в дашборд:** подключён `docker/cf-frame.conf` (`a2enconf cf-frame`),
  который снимает `X-Frame-Options`/`Content-Security-Policy`, иначе плитка-iframe
  дашборда показала бы пустоту. Компромисс по clickjacking — приемлем для
  локального single-user инструмента; см. комментарий в самом conf-файле.
