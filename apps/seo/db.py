"""
Слой БД seo (SQLite через SQLAlchemy).

Фундамент для мультиаккаунта и масштабирования: аккаунты Google, их сайты и
(в следующих фазах) страницы/беклинки/заметки. Файл БД лежит на постоянном
томе /data/seo/seo.db.

Единый паттерн с остальными приложениями монорепо (ank/arc используют SQLite).
"""

import logging
import os
from contextlib import contextmanager
from datetime import datetime

from sqlalchemy import (create_engine, Column, Integer, String, Text, DateTime,
                        ForeignKey, UniqueConstraint)
from sqlalchemy.orm import declarative_base, sessionmaker, relationship

log = logging.getLogger('seo.db')

DATA_DIR = os.environ.get('SEO_DATA_DIR', os.path.dirname(__file__))
DB_PATH = os.path.join(DATA_DIR, 'seo.db')

# check_same_thread=False — Flask threaded=True обращается из разных потоков.
engine = create_engine(
    f'sqlite:///{DB_PATH}',
    connect_args={'check_same_thread': False},
    future=True,
)
SessionLocal = sessionmaker(bind=engine, autoflush=False, expire_on_commit=False, future=True)
Base = declarative_base()


class Account(Base):
    """Подключённый Google-аккаунт (Gmail/GSC) с OAuth-токеном."""
    __tablename__ = 'accounts'

    id = Column(Integer, primary_key=True)
    email = Column(String(320), unique=True, nullable=False, index=True)
    token_json = Column(Text, nullable=False)          # google-auth Credentials.to_json()
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    sites = relationship('Site', back_populates='account', cascade='all, delete-orphan')

    def __repr__(self):
        return f"<Account {self.email}>"


class Site(Base):
    """Сайт (ресурс GSC), принадлежащий аккаунту."""
    __tablename__ = 'sites'
    __table_args__ = (UniqueConstraint('account_id', 'site_url', name='uq_account_site'),)

    id = Column(Integer, primary_key=True)
    account_id = Column(Integer, ForeignKey('accounts.id', ondelete='CASCADE'), index=True)
    site_url = Column(String(2048), nullable=False, index=True)
    permission_level = Column(String(64))
    created_at = Column(DateTime, default=datetime.utcnow)

    account = relationship('Account', back_populates='sites')

    def __repr__(self):
        return f"<Site {self.site_url} (acc={self.account_id})>"


def init_db():
    """Создать таблицы, если их нет. Идемпотентно."""
    Base.metadata.create_all(engine)
    log.info("DB initialized at %s (tables: %s)",
             DB_PATH, ", ".join(Base.metadata.tables.keys()))


@contextmanager
def session_scope():
    """Транзакционная сессия с автокоммитом/роллбэком и логами."""
    session = SessionLocal()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        log.exception("DB session rolled back")
        raise
    finally:
        session.close()


def db_healthy():
    """Быстрая проверка доступности БД (для /api/status)."""
    try:
        from sqlalchemy import text
        with engine.connect() as conn:
            conn.execute(text('SELECT 1'))
        return True
    except Exception as e:  # noqa: BLE001
        log.warning("DB health check failed: %s", e)
        return False
