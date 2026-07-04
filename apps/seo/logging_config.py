"""
Детальное логирование для seo-бэкенда.

Единая точка настройки: вывод в stdout (его собирает supervisord/docker) и в
файл /data/seo/logs/backend.log (переживает пересборку — том примонтирован).
Уровень настраивается через env SEO_LOG_LEVEL (по умолчанию INFO; DEBUG даёт
максимально подробные логи по всем запросам, кэшу и внешним API).
"""

import logging
import os
import sys

_CONFIGURED = False


def setup_logging():
    """Настроить корневой логгер один раз. Возвращает логгер 'seo'."""
    global _CONFIGURED

    level_name = os.environ.get('SEO_LOG_LEVEL', 'INFO').upper()
    level = getattr(logging, level_name, logging.INFO)

    if not _CONFIGURED:
        fmt = '%(asctime)s %(levelname)-7s [%(name)s] %(message)s'
        datefmt = '%Y-%m-%d %H:%M:%S'

        handlers = [logging.StreamHandler(sys.stdout)]

        # Дополнительно пишем в файл на постоянном томе (если доступен).
        data_dir = os.environ.get('SEO_DATA_DIR', os.path.dirname(__file__))
        try:
            logs_dir = os.path.join(data_dir, 'logs')
            os.makedirs(logs_dir, exist_ok=True)
            handlers.append(logging.FileHandler(
                os.path.join(logs_dir, 'backend.log'), encoding='utf-8'))
        except Exception as e:  # noqa: BLE001 — логи не должны ронять старт
            print(f"WARN: cannot set up file logging: {e}", file=sys.stderr)

        logging.basicConfig(level=level, format=fmt, datefmt=datefmt,
                            handlers=handlers, force=True)

        # Приглушаем слишком болтливый werkzeug (每-запросные строки дублируют
        # наш request-логгер); при DEBUG показываем всё.
        wz_level = os.environ.get('SEO_WERKZEUG_LEVEL',
                                  'DEBUG' if level <= logging.DEBUG else 'WARNING').upper()
        logging.getLogger('werkzeug').setLevel(getattr(logging, wz_level, logging.WARNING))

        _CONFIGURED = True

    log = logging.getLogger('seo')
    log.info("Logging configured (level=%s)", level_name)
    return log
