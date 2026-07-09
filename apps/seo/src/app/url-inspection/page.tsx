'use client';

import { useState, useEffect } from 'react';
import { API_BASE } from '@/lib/api';
import { useData } from '@/contexts/DataContext';
import { Button } from '@/components/ui/button';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faMagnifyingGlass, faSpinner, faCheckCircle, faExclamationTriangle, faInfoCircle, faHouse, faCopy, faRotateRight } from '@fortawesome/free-solid-svg-icons';

interface UrlInspectionResult {
  inspectionResult?: {
    indexStatusResult?: {
      verdict?: string;
      coverageState?: string;
      indexingState?: string;
      lastCrawlTime?: string;
      pageFetchState?: string;
      googleCanonical?: string;
      userCanonical?: string;
      referringUrls?: string[];
      crawledAs?: string;
      robotsTxtState?: string;
      sitemap?: string[];
    };
    ampResult?: {
      verdict?: string;
      issues?: Array<{
        severity?: string;
        issueMessage?: string;
      }>;
      ampIndexable?: boolean;
    };
    mobileUsabilityResult?: {
      verdict?: string;
      issues?: Array<{
        severity?: string;
        issueMessage?: string;
      }>;
    };
    richResultsResult?: {
      verdict?: string;
      detectedItems?: Array<{
        richResultType?: string;
        items?: Array<{
          name?: string;
          value?: string;
        }>;
      }>;
    };
  };
  error?: string;
}

export default function UrlInspectionPage() {
  const { sites, fetchSites } = useData();
  const [inspectionUrl, setInspectionUrl] = useState('');
  const [selectedSite, setSelectedSite] = useState('');
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<UrlInspectionResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [reindexing, setReindexing] = useState(false);
  const [reindexMsg, setReindexMsg] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    fetchSites();
    if (sites.length > 0 && !selectedSite) {
      setSelectedSite(sites[0]);
    }
  }, [sites]);

  // Главная страница выбранного сайта в виде https://site.com/.
  // Учитывает и domain-property Search Console (sc-domain:example.com).
  const homepageOf = (site: string): string => {
    if (!site) return '';
    if (site.startsWith('sc-domain:')) {
      return `https://${site.slice('sc-domain:'.length)}/`;
    }
    return site.endsWith('/') ? site : `${site}/`;
  };

  const fillHomepage = () => {
    const home = homepageOf(selectedSite);
    if (home) {
      setInspectionUrl(home);
      setError(null);
    }
  };

  const copyHomepage = async () => {
    const home = homepageOf(selectedSite);
    if (!home) return;
    try {
      await navigator.clipboard.writeText(home);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      // Фолбэк, если clipboard недоступен — просто подставим в поле.
      setInspectionUrl(home);
    }
  };

  // Переобход через 2index (в GSC API «Запросить индексирование» отсутствует).
  const requestReindex = async () => {
    const url = inspectionUrl.trim();
    if (!url) return;
    setReindexing(true);
    setReindexMsg(null);
    try {
      const response = await fetch(`${API_BASE}/api/index/submit-url`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url })
      });
      const data = await response.json();
      if (response.ok && data.success) {
        setReindexMsg({ type: 'success', text: `Отправлено на переобход через 2index (${data.accepted}).` });
      } else {
        setReindexMsg({ type: 'error', text: data.error || 'Не удалось отправить на переобход' });
      }
    } catch {
      setReindexMsg({ type: 'error', text: 'Не удалось отправить на переобход. Проверьте бэкенд.' });
    } finally {
      setReindexing(false);
    }
  };

  const handleInspect = async () => {
    if (!inspectionUrl.trim()) {
      setError('Введите URL для проверки');
      return;
    }

    if (!selectedSite) {
      setError('Выберите сайт');
      return;
    }

    // Validate URL format
    try {
      new URL(inspectionUrl);
    } catch {
      setError('Введите корректный URL (например, https://example.com/page)');
      return;
    }

    setLoading(true);
    setError(null);
    setResult(null);

    try {
      const response = await fetch(`${API_BASE}/api/url-inspect`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          inspectionUrl: inspectionUrl.trim(),
          siteUrl: selectedSite,
          languageCode: 'en-US'
        })
      });

      const data = await response.json();

      if (!response.ok) {
        setError(data.error || 'Не удалось проверить URL');
        return;
      }

      setResult(data);
    } catch (err) {
      console.error('Error inspecting URL:', err);
      setError('Не удалось проверить URL. Убедитесь, что бэкенд запущен на порту 5001.');
    } finally {
      setLoading(false);
    }
  };

  const getVerdictColor = (verdict?: string) => {
    if (!verdict) return 'text-gray-600';
    const lowerVerdict = verdict.toLowerCase();
    if (lowerVerdict.includes('pass') || lowerVerdict.includes('valid')) {
      return 'text-green-600';
    }
    if (lowerVerdict.includes('fail') || lowerVerdict.includes('error')) {
      return 'text-red-600';
    }
    if (lowerVerdict.includes('warning') || lowerVerdict.includes('partial')) {
      return 'text-yellow-600';
    }
    return 'text-gray-600';
  };

  const getVerdictIcon = (verdict?: string) => {
    if (!verdict) return faInfoCircle;
    const lowerVerdict = verdict.toLowerCase();
    if (lowerVerdict.includes('pass') || lowerVerdict.includes('valid')) {
      return faCheckCircle;
    }
    if (lowerVerdict.includes('fail') || lowerVerdict.includes('error')) {
      return faExclamationTriangle;
    }
    return faInfoCircle;
  };

  const formatDate = (dateString?: string) => {
    if (!dateString) return 'N/A';
    try {
      const date = new Date(dateString);
      return date.toLocaleString();
    } catch {
      return dateString;
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-6xl mx-auto">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-4xl font-bold text-gray-900 mb-2">
            🔍 Проверка URL
          </h1>
          <p className="text-gray-600">
            Проверьте статус индексации URL в Google Search Console
          </p>
        </div>

        {/* Input Section */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-xl font-semibold mb-4">Проверка URL</h2>
          
          <div className="space-y-4">
            <div>
              <label htmlFor="site-select" className="block text-sm font-medium text-gray-700 mb-2">
                Сайт (доступно: {sites.length})
              </label>
              <select
                id="site-select"
                value={selectedSite}
                onChange={(e) => setSelectedSite(e.target.value)}
                className="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              >
                <option value="">Выберите сайт...</option>
                {sites.map((site) => (
                  <option key={site} value={site}>
                    {site.replace('https://', '').replace('http://', '')}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label htmlFor="url-input" className="block text-sm font-medium text-gray-700 mb-2">
                URL для проверки
              </label>
              <input
                id="url-input"
                type="text"
                value={inspectionUrl}
                onChange={(e) => setInspectionUrl(e.target.value)}
                placeholder="https://example.com/page"
                className="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                onKeyPress={(e) => {
                  if (e.key === 'Enter' && !loading) {
                    handleInspect();
                  }
                }}
              />
              <div className="flex flex-wrap items-center gap-2 mt-2">
                <button
                  type="button"
                  onClick={fillHomepage}
                  disabled={!selectedSite}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  title="Подставить главную страницу выбранного сайта"
                >
                  <FontAwesomeIcon icon={faHouse} className="text-gray-500" />
                  Проверить главную
                </button>
                <button
                  type="button"
                  onClick={copyHomepage}
                  disabled={!selectedSite}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                  title="Скопировать адрес главной в буфер"
                >
                  <FontAwesomeIcon icon={copied ? faCheckCircle : faCopy} className={copied ? 'text-green-600' : 'text-gray-500'} />
                  {copied ? 'Скопировано' : 'Копировать главную'}
                </button>
                {selectedSite && (
                  <span className="text-xs text-gray-500 break-all">{homepageOf(selectedSite)}</span>
                )}
              </div>
            </div>

            <Button
              onClick={handleInspect}
              disabled={loading || !inspectionUrl.trim() || !selectedSite}
              className="w-full bg-blue-600 hover:bg-blue-700 text-white disabled:bg-gray-400 disabled:cursor-not-allowed"
            >
              {loading ? (
                <>
                  <FontAwesomeIcon icon={faSpinner} className="mr-2 animate-spin" />
                  Проверка...
                </>
              ) : (
                <>
                  <FontAwesomeIcon icon={faMagnifyingGlass} className="mr-2" />
                  Проверить URL
                </>
              )}
            </Button>
          </div>
        </div>

        {/* Error Display */}
        {error && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <div className="flex items-center">
              <FontAwesomeIcon icon={faExclamationTriangle} className="text-red-600 mr-2" />
              <p className="text-red-800">{error}</p>
            </div>
          </div>
        )}

        {/* Results Display */}
        {result && result.inspectionResult && (
          <div className="bg-white rounded-lg shadow p-6 space-y-6">
            <h2 className="text-2xl font-semibold text-gray-900">Результаты проверки</h2>

            {/* Index Status Result */}
            {result.inspectionResult.indexStatusResult && (
              <div className="border border-gray-200 rounded-lg p-4">
                <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                  <FontAwesomeIcon icon={faInfoCircle} className="mr-2 text-blue-600" />
                  Статус индексации
                </h3>
                
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Вердикт</p>
                    <div className="flex items-center">
                      <FontAwesomeIcon 
                        icon={getVerdictIcon(result.inspectionResult.indexStatusResult.verdict)} 
                        className={`mr-2 ${getVerdictColor(result.inspectionResult.indexStatusResult.verdict)}`}
                      />
                      <p className={`font-semibold ${getVerdictColor(result.inspectionResult.indexStatusResult.verdict)}`}>
                        {result.inspectionResult.indexStatusResult.verdict || 'N/A'}
                      </p>
                    </div>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Состояние покрытия</p>
                    <p className="text-gray-900">{result.inspectionResult.indexStatusResult.coverageState || 'N/A'}</p>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Состояние индексации</p>
                    <p className="text-gray-900">{result.inspectionResult.indexStatusResult.indexingState || 'N/A'}</p>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Состояние загрузки страницы</p>
                    <p className="text-gray-900">{result.inspectionResult.indexStatusResult.pageFetchState || 'N/A'}</p>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Время последнего сканирования</p>
                    <p className="text-gray-900">{formatDate(result.inspectionResult.indexStatusResult.lastCrawlTime)}</p>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Состояние robots.txt</p>
                    <p className="text-gray-900">{result.inspectionResult.indexStatusResult.robotsTxtState || 'N/A'}</p>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Каноническая (Google определил)</p>
                    <p className="text-gray-900 break-all">{result.inspectionResult.indexStatusResult.googleCanonical || '—'}</p>
                  </div>

                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Каноническая (указана на странице)</p>
                    <p className="text-gray-900 break-all">{result.inspectionResult.indexStatusResult.userCanonical || '—'}</p>
                  </div>

                  {result.inspectionResult.indexStatusResult.crawledAs && (
                    <div>
                      <p className="text-sm font-medium text-gray-700 mb-1">Просканировано как</p>
                      <p className="text-gray-900">{result.inspectionResult.indexStatusResult.crawledAs}</p>
                    </div>
                  )}

                  {result.inspectionResult.indexStatusResult.referringUrls && result.inspectionResult.indexStatusResult.referringUrls.length > 0 && (
                    <div className="md:col-span-2">
                      <p className="text-sm font-medium text-gray-700 mb-1">Ссылающиеся URL</p>
                      <ul className="list-disc list-inside space-y-1">
                        {result.inspectionResult.indexStatusResult.referringUrls.map((url, index) => (
                          <li key={index} className="text-gray-900 break-all">{url}</li>
                        ))}
                      </ul>
                    </div>
                  )}

                  {result.inspectionResult.indexStatusResult.sitemap && result.inspectionResult.indexStatusResult.sitemap.length > 0 && (
                    <div className="md:col-span-2">
                      <p className="text-sm font-medium text-gray-700 mb-1">Карта сайта</p>
                      <ul className="list-disc list-inside space-y-1">
                        {result.inspectionResult.indexStatusResult.sitemap.map((sitemap, index) => (
                          <li key={index} className="text-gray-900 break-all">{sitemap}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>

                {/* Переобход: в GSC API нет «Запросить индексирование», поэтому
                    отправляем через 2index (как на вкладке «Индексация»). */}
                <div className="mt-4 pt-4 border-t border-gray-100">
                  <button
                    type="button"
                    onClick={requestReindex}
                    disabled={reindexing || !inspectionUrl.trim()}
                    className="inline-flex items-center gap-2 px-4 py-2 text-sm bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    title="Отправить этот URL на переобход через 2index"
                  >
                    <FontAwesomeIcon icon={reindexing ? faSpinner : faRotateRight} className={reindexing ? 'animate-spin' : ''} />
                    {reindexing ? 'Отправка…' : 'Отправить на переобход (2index)'}
                  </button>
                  {reindexMsg && (
                    <p className={`text-sm mt-2 ${reindexMsg.type === 'success' ? 'text-green-700' : 'text-red-700'}`}>
                      {reindexMsg.text}
                    </p>
                  )}
                  <p className="text-xs text-gray-500 mt-2">
                    В Google Search Console API нет прямого «Запросить индексирование» — переобход идёт через сервис 2index (нужен ключ в Настройках).
                  </p>
                </div>
              </div>
            )}

            {/* AMP Result */}
            {result.inspectionResult.ampResult && (
              <div className="border border-gray-200 rounded-lg p-4">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Результат AMP</h3>
                
                <div className="space-y-3">
                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Вердикт</p>
                    <div className="flex items-center">
                      <FontAwesomeIcon 
                        icon={getVerdictIcon(result.inspectionResult.ampResult.verdict)} 
                        className={`mr-2 ${getVerdictColor(result.inspectionResult.ampResult.verdict)}`}
                      />
                      <p className={`font-semibold ${getVerdictColor(result.inspectionResult.ampResult.verdict)}`}>
                        {result.inspectionResult.ampResult.verdict || 'N/A'}
                      </p>
                    </div>
                  </div>

                  {result.inspectionResult.ampResult.ampIndexable !== undefined && (
                    <div>
                      <p className="text-sm font-medium text-gray-700 mb-1">AMP индексируется</p>
                      <p className="text-gray-900">{result.inspectionResult.ampResult.ampIndexable ? 'Да' : 'Нет'}</p>
                    </div>
                  )}

                  {result.inspectionResult.ampResult.issues && result.inspectionResult.ampResult.issues.length > 0 && (
                    <div>
                      <p className="text-sm font-medium text-gray-600 mb-2">Проблемы</p>
                      <ul className="space-y-2">
                        {result.inspectionResult.ampResult.issues.map((issue, index) => (
                          <li key={index} className="bg-yellow-50 border border-yellow-200 rounded p-2">
                            <p className="text-sm font-medium text-gray-800">
                              {issue.severity || 'Проблема'}: {issue.issueMessage || 'N/A'}
                            </p>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Mobile Usability Result */}
            {result.inspectionResult.mobileUsabilityResult && (
              <div className="border border-gray-200 rounded-lg p-4">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Удобство для мобильных</h3>
                
                <div className="space-y-3">
                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Вердикт</p>
                    <div className="flex items-center">
                      <FontAwesomeIcon 
                        icon={getVerdictIcon(result.inspectionResult.mobileUsabilityResult.verdict)} 
                        className={`mr-2 ${getVerdictColor(result.inspectionResult.mobileUsabilityResult.verdict)}`}
                      />
                      <p className={`font-semibold ${getVerdictColor(result.inspectionResult.mobileUsabilityResult.verdict)}`}>
                        {result.inspectionResult.mobileUsabilityResult.verdict || 'N/A'}
                      </p>
                    </div>
                  </div>

                  {result.inspectionResult.mobileUsabilityResult.issues && result.inspectionResult.mobileUsabilityResult.issues.length > 0 && (
                    <div>
                      <p className="text-sm font-medium text-gray-600 mb-2">Проблемы</p>
                      <ul className="space-y-2">
                        {result.inspectionResult.mobileUsabilityResult.issues.map((issue, index) => (
                          <li key={index} className="bg-yellow-50 border border-yellow-200 rounded p-2">
                            <p className="text-sm font-medium text-gray-800">
                              {issue.severity || 'Проблема'}: {issue.issueMessage || 'N/A'}
                            </p>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Rich Results Result */}
            {result.inspectionResult.richResultsResult && (
              <div className="border border-gray-200 rounded-lg p-4">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Расширенные результаты</h3>
                
                <div className="space-y-3">
                  <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">Вердикт</p>
                    <div className="flex items-center">
                      <FontAwesomeIcon 
                        icon={getVerdictIcon(result.inspectionResult.richResultsResult.verdict)} 
                        className={`mr-2 ${getVerdictColor(result.inspectionResult.richResultsResult.verdict)}`}
                      />
                      <p className={`font-semibold ${getVerdictColor(result.inspectionResult.richResultsResult.verdict)}`}>
                        {result.inspectionResult.richResultsResult.verdict || 'N/A'}
                      </p>
                    </div>
                  </div>

                  {result.inspectionResult.richResultsResult.detectedItems && result.inspectionResult.richResultsResult.detectedItems.length > 0 && (
                    <div>
                      <p className="text-sm font-medium text-gray-600 mb-2">Обнаруженные элементы</p>
                      <div className="space-y-4">
                        {result.inspectionResult.richResultsResult.detectedItems.map((item, index) => (
                          <div key={index} className="bg-blue-50 border border-blue-200 rounded p-3">
                            <p className="text-sm font-semibold text-gray-800 mb-2">
                              Тип: {item.richResultType || 'Неизвестно'}
                            </p>
                            {item.items && item.items.length > 0 && (
                              <ul className="space-y-1">
                                {item.items.map((subItem, subIndex) => (
                                  <li key={subIndex} className="text-sm text-gray-700">
                                    <span className="font-medium">{subItem.name}:</span> {subItem.value}
                                  </li>
                                ))}
                              </ul>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

