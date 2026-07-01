<p align="center">
  <img src="https://img.shields.io/badge/Version-5.0-blue?style=for-the-badge" alt="Version"/>
  <img src="https://img.shields.io/badge/PHP-8.1-purple?style=for-the-badge&logo=php" alt="PHP"/>
  <img src="https://img.shields.io/badge/Cloudflare-API%20v4-orange?style=for-the-badge&logo=cloudflare" alt="Cloudflare"/>
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License"/>
</p>

<h1 align="center">☁️ CloudPanel v5.0</h1>

<p align="center">
  <strong>🚀 Мощная панель управления Cloudflare для массового управления доменами</strong>
</p>

<p align="center">
  <em>Управляйте сотнями доменов, автоматизируйте DNS, защищайте от ботов — всё в одном месте</em>
</p>

---

## 🌟 Почему CloudPanel?

| ❌ Без панели | ✅ С CloudPanel |
|--------------|-----------------|
| Часами кликаете в Cloudflare Dashboard | Массовые операции за секунды |
| Управляете доменами по одному | Группировка и bulk-операции |
| Ручная настройка DNS каждого домена | Автоматическое изменение IP для всех |
| Нет защиты от ботов | 6000+ заблокированных bad bots |
| Не знаете об изменениях | Telegram уведомления в реальном времени |

---

## ✨ Ключевые возможности

### 🌐 Управление доменами
- **Массовые операции** — изменение настроек сотен доменов одним кликом
- **DNS управление** — A, AAAA, CNAME, MX, TXT, SRV, CAA записи
- **Массовое изменение IP** — смена A-записей всех доменов мгновенно
- **Группировка** — организация доменов по проектам/клиентам
- **Автосинхронизация** — новые домены появляются автоматически

### 🛡️ Система безопасности
- **Bad Bots Protection** — блокировка 6000+ вредоносных ботов
- **Геоблокировка** — 195+ стран, whitelist/blacklist режимы  
- **"Только поисковики"** — доступ только с Google/Yandex/Bing
- **Cloudflare Workers** — 5 готовых защитных шаблонов
- **Rate Limiting** — защита от DDoS и брутфорса

### 🤖 Автоматизация
- **Telegram бот** — уведомления о падениях и изменениях
- **Cron синхронизация** — ежедневная проверка всех аккаунтов
- **Отслеживание IP** — мгновенные уведомления об изменениях
- **SSL мониторинг** — контроль сертификатов

### ⚡ Технологии
- PHP 7.4+ с SQLite (без MySQL!)
- Bootstrap 5 + Font Awesome 6
- Cloudflare API v4 (Bearer Token)
- Workers + KV Storage

---

## 🖼️ Скриншоты

<details>
<summary>📊 Dashboard</summary>
<p align="center">Главная панель с обзором всех доменов, статистикой и быстрыми действиями</p>
</details>

<details>
<summary>🛡️ Security Manager</summary>
<p align="center">Менеджер безопасности с правилами, ботами и геоблокировкой</p>
</details>

<details>
<summary>⚙️ Workers Editor</summary>
<p align="center">Редактор Cloudflare Workers с пресетами защиты</p>
</details>

---

## 🚀 Быстрый старт

### Требования
- PHP 7.4+ с расширениями: PDO, SQLite3, cURL, JSON
- Web-сервер: Apache 2.4+ или Nginx
- Cloudflare API Token

### Установка

```bash
# 1. Клонируйте репозиторий
git clone https://github.com/Seo22Cartel/cloudflare-panel-v.1.git
cd cloudflare-panel-v.1

# 2. Установите права доступа
chmod 755 .
chmod 600 cloudflare_panel.db 2>/dev/null || true
chmod 755 cache migrations cron telegram_bot

# 3. Откройте в браузере и следуйте инструкциям
```

### Первый вход

1. Откройте сайт в браузере
2. Учетные данные находятся в `credentials.txt`
3. **Логин** → поле "Card Number"
4. **Пароль** → поле "CVV"

### Добавление Cloudflare

1. Получите API Token: [Cloudflare Dashboard](https://dash.cloudflare.com/profile/api-tokens)
   - Права: `Zone:Read`, `DNS:Edit`, `SSL:Edit`, `Workers:Edit`
2. Dashboard → Добавить домен → вставьте токен
3. Готово! Домены загрузятся автоматически

---

## 🛡️ Защита от ботов

### 5 готовых Workers шаблонов

| Шаблон | Описание |
|--------|----------|
| 🔥 **Advanced Protection** | Комплексная защита: geo + bots + rate limit |
| 🤖 **Bot Only** | Блокировка только ботов |
| 🌍 **Geo Only** | Только геоблокировка |
| 🔗 **Referrer Only** | Проверка источника перехода |
| ⏱️ **Rate Limit** | Ограничение запросов |

### Быстрые пресеты

```
🇷🇺 Только Россия    — Гео: RU, Боты: блок, Рефер: Google/Yandex
🚫 Без ботов         — Блокировка всех известных ботов  
🛡️ Строгая защита   — RU/BY/KZ, Боты: блок, Rate: 100/мин
```

---

## 🤖 Telegram мониторинг

### Что умеет бот?
- 🔴 Уведомления о падении сервера
- 🟢 Сообщения о восстановлении
- 📊 Ежедневные отчёты синхронизации
- 🆕 Новые домены в аккаунтах
- 🔄 Изменения IP адресов

### Настройка

```php
// telegram_bot/config.php
define('TELEGRAM_BOT_TOKEN', 'ВАШ_ТОКЕН');
define('TELEGRAM_CHAT_IDS', 'ВАШ_CHAT_ID');
define('MONITOR_URLS', 'https://your-domain.com');
```

```bash
# Тест
php telegram_bot/monitor.php --test

# Cron (каждую минуту)
* * * * * php /path/to/telegram_bot/monitor.php
```

---

## 📁 Структура проекта

```
cloudflare-panel-v.1/
├── 📄 config.php              # Конфигурация и база данных
├── 📄 functions.php           # Основные функции (3300+ строк)
├── 📄 CloudflareApiClient.php # Клиент API
├── 📄 dashboard.php           # Главная панель
├── 📄 security_rules_manager.php # Менеджер безопасности
│
├── 📁 cron/                   # Автоматизация
│   ├── daily_sync.php         # Ежедневная синхронизация
│   └── README.md
│
├── 📁 telegram_bot/           # Telegram мониторинг
│   ├── config.php
│   ├── monitor.php
│   └── README.md
│
├── 📁 worker_templates/       # Workers шаблоны
│   ├── advanced-protection.js
│   ├── bot-only.js
│   ├── geo-only.js
│   ├── referrer-only.js
│   └── rate-limit.js
│
├── 📁 migrations/             # SQL миграции
└── 📁 docs/                   # Документация
```

---

## 🔐 Безопасность

### Рекомендации

| Действие | Команда/путь |
|----------|--------------|
| Изменить пароль | `credentials.txt` |
| Права на БД | `chmod 600 cloudflare_panel.db` |
| Права на конфиги | `chmod 600 telegram_bot/config.php` |
| HTTPS | Автоматическое перенаправление |

### Что НЕ коммитить

```gitignore
credentials.txt
cloudflare_panel.db
telegram_bot/config.php
*.log
cache/
```

---

## 📋 Changelog

### Version 5.0 — 06.2026

#### 🚀 Современные API Cloudflare + Docker

**Безопасность переписана на актуальные API:**
- ✅ **WAF Custom Rules (Rulesets API)** вместо устаревшего `firewall/rules` (Cloudflare перевёл его в maintenance mode). Боты / гео / «только поисковики» / IP / Smart WAF.
- ✅ **Вкладка «Только Google»** — 2 WAF-правила (Allow Google Bot `skip` + Block all other) в правильном порядке, работают в комбе.
- ✅ **Геоблокировка** с опцией «не блокировать поисковики» (`not cf.client.bot`).

**Новое:**
- ✅ **Аналитика домена через GraphQL API** (легаси dashboard отключён CF).
- ✅ **Полный редактор DNS** — A/AAAA/CNAME/MX/TXT/NS/SRV, поддомены, proxy-статус (из меню домена).
- ✅ **Page Rules: 301-редирект** (постранично/весь сайт) + воркер-шаблоны **404/410**.
- ✅ **API Token (Bearer)** как основной способ авторизации + выбор Global API Key/Token.
- ✅ **Удаление аккаунта** с каскадной чисткой доменов.
- ✅ **Фильтр доменов по IP**, очистка кэша из меню домена, экспорт логов (CSV).

**Инфраструктура:**
- ✅ **Docker-деплой** (PHP 8.1 + Apache, порт 1000) с фоновым обработчиком очереди.
- ✅ **SQLite WAL + busy_timeout** и индексы под масштаб (~1000 аккаунтов / 1500 доменов).

**Исправлено:**
- Авторизация Bearer-токенов (баг определения по длине ключа), пагинация зон при импорте, отображение SSL-режима, деплой воркеров (account-level), сотни мелких фиксов UX.

### Version 2.0 — 01.12.2025

#### 🎉 Major Release

**Добавлено:**
- ✅ **Telegram бот** для мониторинга
- ✅ **Ежедневная синхронизация** аккаунтов
- ✅ **Автообнаружение** новых доменов
- ✅ **Массовое изменение IP** всех A-записей
- ✅ **5 Workers шаблонов** с редактором
- ✅ **Пресеты безопасности** (РФ, боты, строгая)
- ✅ **Геоблокировка** 195+ стран
- ✅ **Bad Bots Protection** (6000+ ботов)
- ✅ **SSL мониторинг** и уведомления

**Улучшено:**
- 🔧 Полный редизайн UI (Bootstrap 5)
- 🔧 Оптимизация для 1000+ доменов
- 🔧 Улучшенное логирование
- 🔧 Расширенная документация

---

## 🤝 Контрибьютинг

1. Fork репозитория
2. Создайте feature branch (`git checkout -b feature/amazing`)
3. Commit изменения (`git commit -m 'Add amazing feature'`)
4. Push в branch (`git push origin feature/amazing`)
5. Откройте Pull Request

---

## 📄 Лицензия

MIT License — смотрите [LICENSE](LICENSE)

---

## 📞 Контакты

<p align="center">
  <a href="https://t.me/seo2cartel"><img src="https://img.shields.io/badge/Telegram-@seo2cartel-blue?style=for-the-badge&logo=telegram" alt="Telegram"/></a>
  <a href="https://github.com/Seo22Cartel/cloudflare-panel-v.1/issues"><img src="https://img.shields.io/badge/GitHub-Issues-black?style=for-the-badge&logo=github" alt="Issues"/></a>
</p>

---

<p align="center">
  <strong>⭐ Поставьте звезду, если проект полезен!</strong>
</p>

<p align="center">
  <em>Сделано с ❤️ для управления доменами</em>
</p>
