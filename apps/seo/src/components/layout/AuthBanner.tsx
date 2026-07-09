'use client';

import { useState, useEffect } from 'react';
import { API_BASE } from '@/lib/api';
import { usePathname } from 'next/navigation';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle, faTimes, faCog, faArrowRight } from '@fortawesome/free-solid-svg-icons';
import Link from 'next/link';

export default function AuthBanner() {
  const pathname = usePathname();
  const [isAuthenticated, setIsAuthenticated] = useState(true);
  // Загружен ли client_secret.json — чтобы показать, какой именно шаг остался.
  const [hasClientSecret, setHasClientSecret] = useState(false);
  const [loading, setLoading] = useState(true);
  const [dismissed, setDismissed] = useState(false);

  const checkAuthStatus = async () => {
    try {
      const [statusRes, settingsRes] = await Promise.all([
        fetch(`${API_BASE}/api/status`),
        fetch(`${API_BASE}/api/settings`),
      ]);
      setIsAuthenticated(statusRes.ok ? Boolean((await statusRes.json()).gsc_connected) : false);
      if (settingsRes.ok) {
        setHasClientSecret(Boolean((await settingsRes.json()).hasClientSecret));
      }
    } catch (error) {
      console.error('Error checking auth status:', error);
      setIsAuthenticated(false);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    checkAuthStatus();
    // Check every 30 seconds
    const interval = setInterval(checkAuthStatus, 30000);
    return () => clearInterval(interval);
  }, []);

  // Re-check when pathname changes (e.g., returning from settings)
  useEffect(() => {
    if (pathname !== '/settings') {
      checkAuthStatus();
    }
  }, [pathname]);

  // Don't show if authenticated, loading, dismissed, or on settings page
  if (isAuthenticated || loading || dismissed || pathname === '/settings') {
    return null;
  }

  return (
    <div className="sticky top-0 z-50 bg-yellow-50 border-b border-yellow-200 shadow-md">
      <div className="max-w-full px-6 py-4">
        <div className="flex items-start justify-between">
          <div className="flex items-start space-x-3 flex-1">
            <FontAwesomeIcon 
              icon={faExclamationTriangle} 
              className="text-yellow-600 mt-1 flex-shrink-0"
            />
            <div className="flex-1">
              <h3 className="text-sm font-semibold text-yellow-900 mb-1">
                Требуется подключение Google Search Console
              </h3>
              {hasClientSecret ? (
                <p className="text-sm text-yellow-800 mb-3">
                  Учётные данные Google загружены, но ни один аккаунт ещё не подключён.
                  Откройте «Настройки» и нажмите <strong>«Добавить аккаунт Google»</strong> —
                  после подтверждения доступа дашборд заработает.
                </p>
              ) : (
                <>
                  <p className="text-sm text-yellow-800 mb-2">
                    Чтобы пользоваться дашбордом, подключите Google Search Console.
                    Откройте «Настройки» и выполните шаги:
                  </p>
                  <ol className="text-sm text-yellow-800 list-decimal list-inside space-y-1 mb-3">
                    <li>Вставьте содержимое <code>client_secret.json</code> и нажмите «Сохранить учётные данные»</li>
                    <li>Нажмите «Добавить аккаунт Google» и подтвердите доступ</li>
                  </ol>
                  <p className="text-xs text-yellow-700 mb-3">
                    Ключ OpenAI нужен только для AI-инсайтов и необязателен.
                  </p>
                </>
              )}
              <Link
                href="/settings"
                className="inline-flex items-center space-x-2 px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors text-sm font-medium"
              >
                <FontAwesomeIcon icon={faCog} />
                <span>Перейти в настройки</span>
                <FontAwesomeIcon icon={faArrowRight} className="text-xs" />
              </Link>
            </div>
          </div>
          <button
            onClick={() => setDismissed(true)}
            className="ml-4 text-yellow-600 hover:text-yellow-800 transition-colors flex-shrink-0"
            aria-label="Закрыть"
          >
            <FontAwesomeIcon icon={faTimes} />
          </button>
        </div>
      </div>
    </div>
  );
}

