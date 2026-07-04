'use client';

import { useState, useEffect } from 'react';
import { API_BASE } from '@/lib/api';
import { useRouter } from 'next/navigation';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faKey, faFile, faCheckCircle, faExclamationTriangle, faSpinner, faEye, faEyeSlash, faTrash, faRobot, faWandMagicSparkles, faUsers, faPlus } from '@fortawesome/free-solid-svg-icons';
import { useData } from '@/contexts/DataContext';

interface SettingsData {
  openaiApiKey: string;
  openaiModel: string;
  credentialsPath: string;
  trendsCredentialsPath: string;
  isAuthorized: boolean;
  overviewSites: string[];
  xmlriverUser: string;
  xmlriverKey: string;
  twoindexKey: string;
}

// Список моделей OpenAI, доступных для выбора. Значение "custom" позволяет
// ввести название модели вручную.
const OPENAI_MODELS = [
  { value: 'gpt-4o', label: 'GPT-4o' },
  { value: 'gpt-4o-mini', label: 'GPT-4o mini' },
  { value: 'gpt-4.1', label: 'GPT-4.1' },
  { value: 'gpt-4.1-mini', label: 'GPT-4.1 mini' },
  { value: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
  { value: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo' },
  { value: 'o3', label: 'o3' },
  { value: 'o3-mini', label: 'o3-mini' },
  { value: 'o1', label: 'o1' },
  { value: 'o1-mini', label: 'o1-mini' },
];

const DEFAULT_MODEL = 'gpt-4o';

// Рекомендуемая модель для генерации SEO-аналитики. Задача — краткий,
// содержательный анализ небольших таблиц GSC (текст-в-текст, без vision и
// длинного контекста). gpt-4.1 даёт лучшее следование инструкциям и качество
// анализа, чем gpt-4o, и при этом чуть дешевле; reasoning-модели (o1/o3) для
// суммаризации избыточны и медленнее.
const RECOMMENDED_MODEL = 'gpt-4.1';
const RECOMMENDED_MODEL_LABEL =
  OPENAI_MODELS.find((m) => m.value === RECOMMENDED_MODEL)?.label || RECOMMENDED_MODEL;

export default function SettingsPage() {
  const router = useRouter();
  const { clearAllData } = useData();
  const [settings, setSettings] = useState<SettingsData>({
    openaiApiKey: '',
    openaiModel: DEFAULT_MODEL,
    credentialsPath: '',
    trendsCredentialsPath: '',
    isAuthorized: false,
    overviewSites: [],
    xmlriverUser: '',
    xmlriverKey: '',
    twoindexKey: ''
  });
  const [availableSites, setAvailableSites] = useState<string[]>([]);
  const [siteSearchFilter, setSiteSearchFilter] = useState('');
  // Подключённые Google-аккаунты (мультиаккаунт)
  const [accounts, setAccounts] = useState<Array<{ email: string; sites: number; created_at: string | null }>>([]);
  const [deletingAccount, setDeletingAccount] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [authorizing, setAuthorizing] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [showApiKey, setShowApiKey] = useState(true);
  const [checkingKey, setCheckingKey] = useState(false);
  // Результат проверки ключа: ok=true (зелёный) / false (красный)
  const [keyCheck, setKeyCheck] = useState<{ ok: boolean; text: string } | null>(null);
  // true, когда модель не входит в список готовых вариантов и вводится вручную
  const [customModel, setCustomModel] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Load settings on mount
  useEffect(() => {
    loadSettings();
    loadAccounts();
  }, []);

  // Обработка возврата после веб-авторизации Google (?gscAuth=success|error)
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const gscAuth = params.get('gscAuth');
    if (!gscAuth) return;
    const msg = params.get('msg');
    if (gscAuth === 'success') {
      setMessage({ type: 'success', text: msg || 'Авторизация прошла успешно!' });
      loadSettings();
      loadAccounts();
    } else {
      setMessage({ type: 'error', text: msg || 'Ошибка авторизации' });
    }
    // убрать query-параметры из URL, чтобы сообщение не всплывало при обновлении
    window.history.replaceState({}, '', '/settings');
  }, []);

  const loadSettings = async () => {
    setLoading(true);
    try {
      const [settingsResponse, sitesResponse] = await Promise.all([
        fetch(`${API_BASE}/api/settings`),
        fetch(`${API_BASE}/api/sites`)
      ]);

      if (settingsResponse.ok) {
        const data = await settingsResponse.json();
        console.log('Loaded settings from backend:', data); // Debug log
        // Ensure all values are strings (not null/undefined) to prevent controlled/uncontrolled input warnings
        const loadedModel = String(data.openaiModel || DEFAULT_MODEL);
        setCustomModel(!OPENAI_MODELS.some((m) => m.value === loadedModel));
        setSettings({
          openaiApiKey: String(data.openaiApiKey || ''),
          openaiModel: loadedModel,
          credentialsPath: String(data.credentialsPath || ''),
          trendsCredentialsPath: String(data.trendsCredentialsPath || ''),
          isAuthorized: Boolean(data.isAuthorized || false),
          overviewSites: Array.isArray(data.overviewSites) ? data.overviewSites : [],
          xmlriverUser: String(data.xmlriverUser || ''),
          xmlriverKey: String(data.xmlriverKey || ''),
          twoindexKey: String(data.twoindexKey || '')
        });
      } else {
        setMessage({ type: 'error', text: 'Не удалось загрузить настройки' });
      }

      if (sitesResponse.ok) {
        const sitesData = await sitesResponse.json();
        setAvailableSites(sitesData.sites || []);
      } else {
        // If sites endpoint fails, still show the section with a message
        setAvailableSites([]);
      }
    } catch (error) {
      console.error('Error loading settings:', error);
      setMessage({ type: 'error', text: 'Не удалось загрузить настройки. Убедитесь, что бэкенд запущен.' });
    } finally {
      setLoading(false);
    }
  };

  const loadAccounts = async () => {
    try {
      const res = await fetch(`${API_BASE}/api/accounts`);
      if (res.ok) {
        const data = await res.json();
        setAccounts(Array.isArray(data.accounts) ? data.accounts : []);
      }
    } catch (error) {
      console.error('Error loading accounts:', error);
    }
  };

  const deleteAccount = async (email: string) => {
    if (!confirm(`Отключить аккаунт ${email}? Его сайты пропадут из дашборда.`)) return;
    setDeletingAccount(email);
    try {
      const res = await fetch(`${API_BASE}/api/accounts/delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      });
      const data = await res.json();
      if (res.ok) {
        setAccounts(Array.isArray(data.accounts) ? data.accounts : []);
        setMessage({ type: 'success', text: `Аккаунт ${email} отключён.` });
      } else {
        setMessage({ type: 'error', text: data.error || 'Не удалось отключить аккаунт' });
      }
    } catch (error) {
      console.error('Error deleting account:', error);
      setMessage({ type: 'error', text: 'Не удалось отключить аккаунт. Убедитесь, что бэкенд запущен.' });
    } finally {
      setDeletingAccount(null);
    }
  };

  const checkApiKey = async () => {
    if (!settings.openaiApiKey) {
      setKeyCheck({ ok: false, text: 'Сначала введите API-ключ.' });
      return;
    }
    setCheckingKey(true);
    setKeyCheck(null);
    try {
      const response = await fetch(`${API_BASE}/api/openai/validate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          apiKey: settings.openaiApiKey,
          model: settings.openaiModel || DEFAULT_MODEL,
        }),
      });
      const data = await response.json();
      if (data.valid) {
        // valid=true, но модель может быть недоступна для ключа — тогда предупреждаем
        setKeyCheck({ ok: data.model_available !== false, text: data.message || 'Ключ рабочий.' });
      } else {
        setKeyCheck({ ok: false, text: data.message || 'Ключ недействителен.' });
      }
    } catch (error) {
      console.error('Error validating OpenAI key:', error);
      setKeyCheck({ ok: false, text: 'Не удалось проверить ключ. Убедитесь, что бэкенд запущен.' });
    } finally {
      setCheckingKey(false);
    }
  };

  const selectRecommendedModel = () => {
    setCustomModel(false);
    setSettings({ ...settings, openaiModel: RECOMMENDED_MODEL });
    // Прошлый результат проверки мог относиться к другой модели — сбрасываем
    setKeyCheck(null);
  };

  const saveSettings = async () => {
    setSaving(true);
    setMessage(null);
    try {
      const response = await fetch(`${API_BASE}/api/settings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          openaiApiKey: settings.openaiApiKey,
          openaiModel: settings.openaiModel || DEFAULT_MODEL,
          credentialsPath: settings.credentialsPath,
          trendsCredentialsPath: settings.trendsCredentialsPath,
          overviewSites: settings.overviewSites,
          xmlriverUser: settings.xmlriverUser,
          xmlriverKey: settings.xmlriverKey,
          twoindexKey: settings.twoindexKey
        })
      });

      if (response.ok) {
        const result = await response.json();
        console.log('Save response from backend:', result); // Debug log
        console.log('Current settings before update:', settings); // Debug log
        // Update settings with the saved values returned from backend
        // Always use the values from the response if they exist, otherwise keep current settings
        // Always use the values from the backend response, ensuring they're strings
        const updatedSettings = {
          openaiApiKey: String(result.openaiApiKey !== undefined && result.openaiApiKey !== null ? result.openaiApiKey : settings.openaiApiKey || ''),
          openaiModel: String(result.openaiModel !== undefined && result.openaiModel !== null ? result.openaiModel : settings.openaiModel || DEFAULT_MODEL),
          credentialsPath: String(result.credentialsPath !== undefined && result.credentialsPath !== null ? result.credentialsPath : settings.credentialsPath || ''),
          trendsCredentialsPath: String(result.trendsCredentialsPath !== undefined && result.trendsCredentialsPath !== null ? result.trendsCredentialsPath : settings.trendsCredentialsPath || ''),
          isAuthorized: Boolean(result.isAuthorized !== undefined ? result.isAuthorized : settings.isAuthorized),
          overviewSites: Array.isArray(result.overviewSites) ? result.overviewSites : (Array.isArray(settings.overviewSites) ? settings.overviewSites : []),
          xmlriverUser: String(result.xmlriverUser ?? settings.xmlriverUser ?? ''),
          xmlriverKey: String(result.xmlriverKey ?? settings.xmlriverKey ?? ''),
          twoindexKey: String(result.twoindexKey ?? settings.twoindexKey ?? '')
        };
        console.log('Updated settings:', updatedSettings); // Debug log
        setSettings(updatedSettings);
        setMessage({ type: 'success', text: 'Настройки успешно сохранены!' });
      } else {
        const error = await response.json();
        setMessage({ type: 'error', text: error.error || 'Не удалось сохранить настройки' });
      }
    } catch (error) {
      console.error('Error saving settings:', error);
      setMessage({ type: 'error', text: 'Не удалось сохранить настройки. Убедитесь, что бэкенд запущен.' });
    } finally {
      setSaving(false);
    }
  };

  const authorizeCredentials = async () => {
    setAuthorizing(true);
    setMessage(null);
    try {
      // Веб-флоу OAuth: бэкенд отдаёт ссылку на согласие Google, уводим туда
      // браузер. После подтверждения Google вернёт на /settings?gscAuth=...
      const response = await fetch(`${API_BASE}/api/oauth/google/start`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          credentialsPath: settings.credentialsPath
        })
      });
      const data = await response.json();

      if (response.ok && data.authUrl) {
        window.location.href = data.authUrl;
        return; // уходим на Google, состояние authorizing не сбрасываем
      }
      setMessage({ type: 'error', text: data.error || 'Не удалось начать авторизацию' });
      setAuthorizing(false);
    } catch (error) {
      console.error('Error starting Google authorization:', error);
      setMessage({ type: 'error', text: 'Не удалось начать авторизацию. Убедитесь, что бэкенд запущен.' });
      setAuthorizing(false);
    }
  };

  const clearAllSettings = async () => {
    if (!confirm('Вы уверены, что хотите удалить все данные для доступа и авторизацию? Это удалит ваш API-ключ, путь к учётным данным и файл авторизованных данных.')) {
      return;
    }

    setClearing(true);
    setMessage(null);
    try {
      const response = await fetch(`${API_BASE}/api/settings/clear`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        }
      });

      if (response.ok) {
        const result = await response.json();
        setCustomModel(false);
        setSettings({
          openaiApiKey: '',
          openaiModel: DEFAULT_MODEL,
          credentialsPath: '',
          trendsCredentialsPath: '',
          isAuthorized: false,
          overviewSites: [],
          xmlriverUser: '',
          xmlriverKey: '',
          twoindexKey: ''
        });

        // Clear all data from DataContext
        clearAllData();

        // Clear available sites
        setAvailableSites([]);

        setMessage({ type: 'success', text: result.message || 'Все данные для доступа и данные успешно удалены!' });

        // Refresh the page after a short delay to show the cleared state
        setTimeout(() => {
          router.refresh();
        }, 1000);
      } else {
        const error = await response.json();
        setMessage({ type: 'error', text: error.error || 'Не удалось очистить настройки' });
      }
    } catch (error) {
      console.error('Error clearing settings:', error);
      setMessage({ type: 'error', text: 'Не удалось очистить настройки. Убедитесь, что бэкенд запущен.' });
    } finally {
      setClearing(false);
    }
  };

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      {/* Page Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900">Настройки</h1>
        <p className="text-gray-600 mt-2">Настройте свои API-ключи и данные для доступа</p>
      </div>

      {/* Message Display */}
      {message && (
        <div className={`p-4 rounded-lg flex items-center space-x-2 ${
          message.type === 'success'
            ? 'bg-green-50 text-green-800 border border-green-200'
            : 'bg-red-50 text-red-800 border border-red-200'
        }`}>
          <FontAwesomeIcon
            icon={message.type === 'success' ? faCheckCircle : faExclamationTriangle}
            className={message.type === 'success' ? 'text-green-600' : 'text-red-600'}
          />
          <span>{message.text}</span>
        </div>
      )}

      {/* Settings Form */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6 space-y-6">
        {loading ? (
          <div className="flex items-center justify-center py-8">
            <FontAwesomeIcon icon={faSpinner} className="animate-spin text-blue-600 text-2xl" />
            <span className="ml-3 text-gray-600">Загрузка настроек...</span>
          </div>
        ) : (
          <>
            {/* OpenAI API Key */}
            <div className="space-y-2">
              <label htmlFor="openai-key" className="flex items-center space-x-2 text-sm font-medium text-gray-700">
                <FontAwesomeIcon icon={faKey} className="text-gray-500" />
                <span>API-ключ OpenAI</span>
              </label>
              <div className="relative">
                <input
                  id="openai-key"
                  type={showApiKey ? "text" : "password"}
                  value={settings.openaiApiKey}
                  onChange={(e) => {
                    setSettings({ ...settings, openaiApiKey: e.target.value });
                    setKeyCheck(null);
                  }}
                  placeholder="sk-proj-..."
                  className="w-full px-4 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                <button
                  type="button"
                  onClick={() => setShowApiKey(!showApiKey)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700"
                  aria-label={showApiKey ? "Скрыть API-ключ" : "Показать API-ключ"}
                >
                  <FontAwesomeIcon icon={showApiKey ? faEyeSlash : faEye} />
                </button>
              </div>
              <div className="flex items-center flex-wrap gap-3">
                <button
                  type="button"
                  onClick={checkApiKey}
                  disabled={checkingKey || !settings.openaiApiKey}
                  className="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg border border-gray-300 hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2 text-sm"
                >
                  {checkingKey && <FontAwesomeIcon icon={faSpinner} className="animate-spin" />}
                  <span>{checkingKey ? 'Проверка...' : 'Проверить ключ'}</span>
                </button>
                {keyCheck && (
                  <span className={`flex items-center space-x-1 text-sm ${keyCheck.ok ? 'text-green-700' : 'text-red-700'}`}>
                    <FontAwesomeIcon icon={keyCheck.ok ? faCheckCircle : faExclamationTriangle} />
                    <span>{keyCheck.text}</span>
                  </span>
                )}
              </div>
              <p className="text-xs text-gray-500">
                Ваш API-ключ OpenAI используется для генерации аналитики. Получить ключ можно на{' '}
                <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">
                  платформе OpenAI
                </a>
              </p>
            </div>

            {/* OpenAI Model */}
            <div className="space-y-2">
              <label htmlFor="openai-model" className="flex items-center space-x-2 text-sm font-medium text-gray-700">
                <FontAwesomeIcon icon={faRobot} className="text-gray-500" />
                <span>Модель OpenAI</span>
              </label>
              <select
                id="openai-model"
                value={customModel ? 'custom' : settings.openaiModel}
                onChange={(e) => {
                  if (e.target.value === 'custom') {
                    setCustomModel(true);
                  } else {
                    setCustomModel(false);
                    setSettings({ ...settings, openaiModel: e.target.value });
                  }
                }}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
              >
                {OPENAI_MODELS.map((model) => (
                  <option key={model.value} value={model.value}>
                    {model.label}
                  </option>
                ))}
                <option value="custom">Другая (ввести вручную)…</option>
              </select>
              {customModel && (
                <input
                  type="text"
                  value={settings.openaiModel}
                  onChange={(e) => setSettings({ ...settings, openaiModel: e.target.value })}
                  placeholder="например, gpt-4.1-nano"
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
              )}
              <div className="flex items-center flex-wrap gap-3">
                <button
                  type="button"
                  onClick={selectRecommendedModel}
                  disabled={settings.openaiModel === RECOMMENDED_MODEL && !customModel}
                  className="px-4 py-2 bg-blue-50 text-blue-700 rounded-lg border border-blue-200 hover:bg-blue-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2 text-sm"
                >
                  <FontAwesomeIcon icon={faWandMagicSparkles} />
                  <span>Выбрать рекомендуемую ({RECOMMENDED_MODEL_LABEL})</span>
                </button>
                {settings.openaiModel === RECOMMENDED_MODEL && !customModel && (
                  <span className="flex items-center space-x-1 text-sm text-green-700">
                    <FontAwesomeIcon icon={faCheckCircle} />
                    <span>Выбрана рекомендуемая модель</span>
                  </span>
                )}
              </div>
              <p className="text-xs text-gray-500">
                Модель, которая будет использоваться для генерации аналитики. Убедитесь, что она доступна для вашего API-ключа.
                Рекомендуем {RECOMMENDED_MODEL_LABEL}: лучшее соотношение качества анализа и цены для этой задачи.
              </p>
            </div>

            {/* Credentials Path */}
            <div className="space-y-2">
              <label htmlFor="credentials-path" className="flex items-center space-x-2 text-sm font-medium text-gray-700">
                <FontAwesomeIcon icon={faFile} className="text-gray-500" />
                <span>Путь к учётным данным Google Search Console</span>
              </label>
              <input
                id="credentials-path"
                type="text"
                value={settings.credentialsPath}
                onChange={(e) => setSettings({ ...settings, credentialsPath: e.target.value })}
                placeholder="/path/to/client_secret.json"
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
              <p className="text-xs text-gray-500">
                Путь к файлу client_secret.json для Google Search Console. Скачать его можно в{' '}
                <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">
                  Google Cloud Console
                </a>
              </p>
            </div>

            {/* Google Trends Credentials Path */}
            <div className="space-y-2">
              <label htmlFor="trends-credentials-path" className="flex items-center space-x-2 text-sm font-medium text-gray-700">
                <FontAwesomeIcon icon={faFile} className="text-gray-500" />
                <span>Путь к учётным данным Google Trends</span>
              </label>
              <input
                id="trends-credentials-path"
                type="text"
                value={settings.trendsCredentialsPath}
                onChange={(e) => setSettings({ ...settings, trendsCredentialsPath: e.target.value })}
                placeholder="/path/to/trends_client_secret.json"
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
              <p className="text-xs text-gray-500">
                Путь к файлу client_secret.json OAuth для Google Trends. В Google Cloud Console должен быть включён доступ (scope) <code>searchtrends</code>.
              </p>
            </div>

            {/* Ключи мониторинга беклинков */}
            <div className="space-y-3 border-t border-gray-200 pt-6">
              <h3 className="text-sm font-semibold text-gray-800">Мониторинг беклинков</h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                  <label htmlFor="xmlriver-user" className="block text-xs font-medium text-gray-700 mb-1">XMLRIVER — user ID</label>
                  <input id="xmlriver-user" type="text" value={settings.xmlriverUser}
                    onChange={(e) => setSettings({ ...settings, xmlriverUser: e.target.value })}
                    placeholder="напр. 12345"
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                </div>
                <div>
                  <label htmlFor="xmlriver-key" className="block text-xs font-medium text-gray-700 mb-1">XMLRIVER — API key</label>
                  <input id="xmlriver-key" type="password" value={settings.xmlriverKey}
                    onChange={(e) => setSettings({ ...settings, xmlriverKey: e.target.value })}
                    placeholder="ключ XMLRIVER"
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                </div>
                <div>
                  <label htmlFor="twoindex-key" className="block text-xs font-medium text-gray-700 mb-1">2index Ninja — API key</label>
                  <input id="twoindex-key" type="password" value={settings.twoindexKey}
                    onChange={(e) => setSettings({ ...settings, twoindexKey: e.target.value })}
                    placeholder="ключ 2index"
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
                </div>
              </div>
              <p className="text-xs text-gray-500">
                XMLRIVER — проверка индексации беклинков (<a href="https://xmlriver.com/api/" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">docs</a>).
                2index Ninja — отправка беклинков на индексацию (<a href="https://2index.ninja/api-documentation" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">docs</a>).
                Используются на странице «Беклинки».
              </p>
            </div>

            {/* Connected Google accounts (мультиаккаунт) */}
            <div className="space-y-2">
              <label className="flex items-center space-x-2 text-sm font-medium text-gray-700">
                <FontAwesomeIcon icon={faUsers} className="text-gray-500" />
                <span>Подключённые Google-аккаунты ({accounts.length})</span>
              </label>
              <p className="text-xs text-gray-500">
                Можно подключить несколько Gmail / Search Console — сайты со всех аккаунтов
                объединяются в общем дашборде.
              </p>

              {accounts.length > 0 ? (
                <div className="border border-gray-200 rounded-lg divide-y divide-gray-100">
                  {accounts.map((acc) => (
                    <div key={acc.email} className="flex items-center justify-between px-4 py-2">
                      <div className="flex items-center space-x-2 min-w-0">
                        <FontAwesomeIcon icon={faCheckCircle} className="text-green-600 flex-shrink-0" />
                        <span className="text-sm text-gray-800 truncate">{acc.email}</span>
                        <span className="text-xs text-gray-500 flex-shrink-0">· сайтов: {acc.sites}</span>
                      </div>
                      <button
                        type="button"
                        onClick={() => deleteAccount(acc.email)}
                        disabled={deletingAccount === acc.email}
                        className="text-red-600 hover:text-red-800 disabled:opacity-50 text-sm flex items-center space-x-1 flex-shrink-0"
                      >
                        <FontAwesomeIcon icon={deletingAccount === acc.email ? faSpinner : faTrash}
                          className={deletingAccount === acc.email ? 'animate-spin' : ''} />
                        <span>Отключить</span>
                      </button>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="border border-gray-300 rounded-lg p-4 bg-gray-50">
                  <p className="text-sm text-gray-600">
                    Аккаунтов пока нет. Укажите путь к client_secret.json выше, сохраните настройки
                    и нажмите «Добавить аккаунт Google».
                  </p>
                </div>
              )}

              <button
                type="button"
                onClick={authorizeCredentials}
                disabled={authorizing || !settings.credentialsPath}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center space-x-2 text-sm"
              >
                {authorizing ? <FontAwesomeIcon icon={faSpinner} className="animate-spin" />
                             : <FontAwesomeIcon icon={faPlus} />}
                <span>{authorizing ? 'Открываю Google…' : 'Добавить аккаунт Google'}</span>
              </button>
            </div>

            {/* Overview Sites Selection */}
            <div className="space-y-2">
              <label className="flex items-center space-x-2 text-sm font-medium text-gray-700">
                <FontAwesomeIcon icon={faFile} className="text-gray-500" />
                <span>Выбор сайтов для обзора</span>
              </label>
              <p className="text-xs text-gray-500 mb-3">
                Выберите до 6 сайтов для отображения на странице «Обзор сайтов» (выбрано {settings.overviewSites.length}/6)
              </p>
              {availableSites.length === 0 ? (
                <div className="border border-gray-300 rounded-lg p-4 bg-gray-50">
                  <p className="text-sm text-gray-600">
                    {settings.isAuthorized
                      ? "Загрузка сайтов..."
                      : "Сначала авторизуйте данные для доступа, чтобы увидеть доступные сайты."}
                  </p>
                </div>
              ) : (
                <>
                  {/* Search/Filter Input */}
                  <div className="mb-3">
                    <input
                      type="text"
                      value={siteSearchFilter}
                      onChange={(e) => setSiteSearchFilter(e.target.value)}
                      placeholder="Поиск сайтов (например, a.com)..."
                      className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    />
                  </div>

                  {/* Filtered Sites List */}
                  <div className="border border-gray-300 rounded-lg p-4 max-h-64 overflow-y-auto">
                    {availableSites
                      .filter(site =>
                        site.toLowerCase().includes(siteSearchFilter.toLowerCase())
                      )
                      .length === 0 ? (
                        <p className="text-sm text-gray-500 text-center py-4">
                          Не найдено сайтов по запросу «{siteSearchFilter}»
                        </p>
                      ) : (
                        availableSites
                          .filter(site =>
                            site.toLowerCase().includes(siteSearchFilter.toLowerCase())
                          )
                          .map((site) => {
                            const isSelected = settings.overviewSites.includes(site);
                            const canSelect = isSelected || settings.overviewSites.length < 6;

                            return (
                              <label
                                key={site}
                                className={`flex items-center space-x-2 p-2 rounded hover:bg-gray-50 cursor-pointer ${
                                  !canSelect ? 'opacity-50 cursor-not-allowed' : ''
                                }`}
                              >
                                <input
                                  type="checkbox"
                                  checked={isSelected}
                                  disabled={!canSelect}
                                  onChange={(e) => {
                                    if (e.target.checked) {
                                      if (settings.overviewSites.length < 6) {
                                        setSettings({
                                          ...settings,
                                          overviewSites: [...settings.overviewSites, site]
                                        });
                                      }
                                    } else {
                                      setSettings({
                                        ...settings,
                                        overviewSites: settings.overviewSites.filter(s => s !== site)
                                      });
                                    }
                                  }}
                                  className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                />
                                <span className="text-sm text-gray-700">{site}</span>
                              </label>
                            );
                          })
                      )}
                  </div>
                  {settings.overviewSites.length === 6 && (
                    <p className="text-xs text-yellow-600 mt-2">
                      Выбрано максимум 6 сайтов. Снимите отметку с одного, чтобы выбрать другой.
                    </p>
                  )}
                </>
              )}
            </div>

            {/* Action Buttons */}
            <div className="flex items-center space-x-3 pt-4 border-t border-gray-200">
              <button
                onClick={saveSettings}
                disabled={saving}
                className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center space-x-2"
              >
                {saving && <FontAwesomeIcon icon={faSpinner} className="animate-spin" />}
                <span>{saving ? 'Сохранение...' : 'Сохранить настройки'}</span>
              </button>

              <button
                onClick={loadSettings}
                disabled={loading}
                className="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 disabled:bg-gray-100 disabled:cursor-not-allowed flex items-center space-x-2"
              >
                {loading && <FontAwesomeIcon icon={faSpinner} className="animate-spin" />}
                <span>Обновить</span>
              </button>

              <button
                onClick={clearAllSettings}
                disabled={clearing}
                className="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center space-x-2"
              >
                {clearing && <FontAwesomeIcon icon={faSpinner} className="animate-spin" />}
                <FontAwesomeIcon icon={faTrash} />
                <span>{clearing ? 'Очистка...' : 'Очистить всё'}</span>
              </button>
            </div>
          </>
        )}
      </div>

      {/* Instructions */}
      <div className="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <h2 className="text-lg font-semibold text-blue-900 mb-3">Инструкция по настройке</h2>
        <ol className="list-decimal list-inside space-y-2 text-sm text-blue-800">
          <li>Получите API-ключ OpenAI на платформе OpenAI и вставьте его выше</li>
          <li>Выберите модель OpenAI, которую хотите использовать для аналитики</li>
          <li>Создайте OAuth-клиент типа «Веб-приложение» в Google Cloud Console и скачайте client_secret.json</li>
          <li>
            В настройках OAuth-клиента добавьте <strong>Authorized redirect URI</strong>:{' '}
            <code>http://localhost:5001/api/oauth/google/callback</code>
          </li>
          <li>Укажите полный путь к файлу client_secret.json выше и нажмите «Сохранить настройки»</li>
          <li>Нажмите «Авторизовать данные» — откроется страница согласия Google, после подтверждения вы вернётесь сюда автоматически</li>
          <li>После авторизации можно начинать работу с дашбордом!</li>
        </ol>
      </div>
    </div>
  );
}
