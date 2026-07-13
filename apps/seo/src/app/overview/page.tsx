'use client';

import { useState, useEffect, useRef } from 'react';
import { API_BASE } from '@/lib/api';
import HelpButton from '@/components/ui/HelpButton';
import Chart from 'chart.js/auto';
import { useData } from '@/contexts/DataContext';

interface GSCRow {
  keys?: string[];
  clicks: number;
  impressions: number;
  ctr: number;
  position: number;
}

interface GSCData {
  rows: GSCRow[];
  totalClicks: number;
  totalImpressions: number;
  avgCtr: number;
  avgPosition: number;
}

interface SiteOverviewData {
  site: string;
  data: GSCData | null;
  timeSeriesData: Array<{
    date: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
  }>;
}

export default function OverviewPage() {
  const {
    sites,
    overviewData,
    setOverviewData,
    topSites,
    setTopSites,
    overviewPeriod,
    setOverviewPeriod,
    overviewDevice,
    setOverviewDevice,
    overviewSecondaryMetric,
    setOverviewSecondaryMetric,
    overviewLoading,
    setOverviewLoading,
    error,
    setError
  } = useData();
  
  // Refs for chart canvases
  const overviewChartRefs = useRef<{[key: string]: HTMLCanvasElement | null}>({});
  const overviewChartInstances = useRef<{[key: string]: Chart}>({});

  // Выбор сайтов для обзора прямо здесь (раньше был только в Настройках)
  const [showPicker, setShowPicker] = useState(false);
  const [pickerSel, setPickerSel] = useState<string[]>([]);
  const [pickerSearch, setPickerSearch] = useState('');
  const [savingSites, setSavingSites] = useState(false);

  const openPicker = () => { setPickerSel(topSites); setPickerSearch(''); setShowPicker(true); };
  const togglePick = (site: string) => setPickerSel((prev) =>
    prev.includes(site) ? prev.filter((s) => s !== site) : (prev.length < 6 ? [...prev, site] : prev));
  const savePicker = async () => {
    setSavingSites(true);
    try {
      await fetch(`${API_BASE}/api/settings`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ overviewSites: pickerSel }),
      });
      setTopSites(pickerSel);
      setOverviewData([]); // сбросить старые данные — нажать «Обновить»
      setShowPicker(false);
    } catch { /* ignore */ } finally { setSavingSites(false); }
  };

  // Date range options
  const dateRangeOptions = [
    { value: '7', label: '7 дней' },
    { value: '30', label: '30 дней' },
    { value: '90', label: '90 дней' },
    { value: '180', label: '6 месяцев' },
    { value: '365', label: '1 год' },
    { value: '480', label: '16 месяцев' }
  ];

  // Device options
  const deviceOptions = [
    { value: 'all', label: 'Все устройства' },
    { value: 'desktop', label: 'Компьютер' },
    { value: 'mobile', label: 'Мобильный' },
    { value: 'tablet', label: 'Планшет' }
  ];

  // Load overview sites from settings, don't auto-select
  useEffect(() => {
    if (sites.length > 0 && topSites.length === 0) {
      // Check if there are saved overview sites in settings
      fetch(`${API_BASE}/api/settings`)
        .then(res => res.json())
        .then(settingsData => {
          if (settingsData.overviewSites && settingsData.overviewSites.length > 0) {
            // Use saved overview sites from settings
            setTopSites(settingsData.overviewSites);
          }
          // If no sites in settings, don't auto-select - show message instead
        })
        .catch(() => {
          // If settings fetch fails, don't auto-select
        });
    }
  }, [sites, topSites, setTopSites]);

  // Don't auto-fetch overview data - only fetch when user clicks "Refresh Data"
  // Data should persist when navigating between pages
  // Removed auto-fetch useEffect - data will only load when handleRefreshData is called

  // Update overview charts
  useEffect(() => {
    if (overviewData.length > 0) {
      createOverviewCharts();
    }
    
    return () => {
      Object.values(overviewChartInstances.current).forEach(chart => {
        if (chart) chart.destroy();
      });
      overviewChartInstances.current = {};
    };
  }, [overviewData, overviewSecondaryMetric]);

  const fetchOverviewData = async () => {
    if (topSites.length === 0) return;
    
    setOverviewLoading(true);
    const newOverviewData: any[] = [];

    try {
      for (const site of topSites) {
        const daysBack = parseInt(overviewPeriod);
        const startDateOverview = new Date(Date.now() - daysBack * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
        const endDateOverview = new Date().toISOString().split('T')[0];

        const params = new URLSearchParams({
          siteUrl: site,
          startDate: startDateOverview,
          endDate: endDateOverview,
          dimensions: 'date',
          fetchAll: 'false'
        });

        if (overviewDevice !== 'all') {
          params.append('device', overviewDevice);
        }

        const response = await fetch(`${API_BASE}/api/data?${params}`);
        
        if (!response.ok) {
          const errorText = await response.text();
          let errorMessage = `HTTP ${response.status}: ${errorText}`;
          try {
            const errorJson = JSON.parse(errorText);
            errorMessage = errorJson.error || errorMessage;
          } catch (e) {
            // If not JSON, use the text as is
          }
          newOverviewData.push({
            site,
            data: null,
            timeSeriesData: [],
            error: errorMessage
          });
          continue;
        }
        
        const result = await response.json();
        
        if (!result.error) {
          const gscData = result as GSCData;
          const timeSeriesData = processTimeSeriesData(gscData);
          
          newOverviewData.push({
            site,
            data: gscData,
            timeSeriesData
          });
        } else {
          newOverviewData.push({
            site,
            data: null,
            timeSeriesData: []
          });
        }
      }
      
      setOverviewData(newOverviewData);
      setError(''); // Clear any previous errors
    } catch (error) {
      console.error('Error fetching overview data:', error);
      setError('Не удалось загрузить данные. Проверьте, запущен ли бэкенд.');
    } finally {
      setOverviewLoading(false);
    }
  };

  const processTimeSeriesData = (gscData: GSCData) => {
    if (!gscData.rows) return [];

    const dateMap = new Map<string, any>();
    
    gscData.rows.forEach((row: GSCRow) => {
      const date = row.keys?.[0] || 'Unknown';
      
      if (dateMap.has(date)) {
        const existing = dateMap.get(date)!;
        existing.clicks += row.clicks;
        existing.impressions += row.impressions;
        const totalImpressions = existing.impressions + row.impressions;
        existing.ctr = totalImpressions > 0 ? 
          ((existing.ctr * existing.impressions) + (row.ctr * row.impressions)) / totalImpressions : 0;
        existing.position = totalImpressions > 0 ?
          ((existing.position * existing.impressions) + (row.position * row.impressions)) / totalImpressions : 0;
      } else {
        dateMap.set(date, {
          date,
          clicks: row.clicks,
          impressions: row.impressions,
          ctr: row.ctr,
          position: row.position
        });
      }
    });

    return Array.from(dateMap.values())
      .sort((a, b) => new Date(a.date).getTime() - new Date(b.date).getTime());
  };

  const createOverviewCharts = () => {
    overviewData.forEach((siteData, index) => {
      if (!siteData.timeSeriesData.length) return;
      
      const chartKey = `overview-${index}`;
      const canvasRef = overviewChartRefs.current[chartKey];
      
      if (!canvasRef) return;

      // Destroy existing chart
      if (overviewChartInstances.current[chartKey]) {
        overviewChartInstances.current[chartKey].destroy();
      }

      const ctx = canvasRef.getContext('2d');
      if (!ctx) return;

      const labels = siteData.timeSeriesData.map((item: any) => {
        const date = new Date(item.date);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      });

      const datasets: any[] = [
        {
          label: 'Клики',
          data: siteData.timeSeriesData.map((item: any) => item.clicks),
          borderColor: '#3B82F6',
          backgroundColor: '#3B82F620',
          borderWidth: 2,
          fill: false,
          tension: 0.4,
          pointRadius: 2,
          pointHoverRadius: 4,
          yAxisID: 'y'
        },
        {
          label: 'Показы',
          data: siteData.timeSeriesData.map((item: any) => item.impressions),
          borderColor: '#10B981',
          backgroundColor: '#10B98120',
          borderWidth: 2,
          fill: false,
          tension: 0.4,
          pointRadius: 2,
          pointHoverRadius: 4,
          yAxisID: 'y1'
        }
      ];

      // Add secondary metric if selected
      if (overviewSecondaryMetric === 'ctr') {
        datasets.push({
          label: 'CTR (%)',
          data: siteData.timeSeriesData.map((item: any) => item.ctr * 100),
          borderColor: '#F59E0B',
          backgroundColor: '#F59E0B20',
          borderWidth: 1,
          fill: false,
          tension: 0.4,
          pointRadius: 1,
          pointHoverRadius: 3,
          borderDash: [5, 5]
        });
      } else if (overviewSecondaryMetric === 'position') {
        datasets.push({
          label: 'Позиция',
          data: siteData.timeSeriesData.map((item: any) => item.position),
          borderColor: '#EF4444',
          backgroundColor: '#EF444420',
          borderWidth: 1,
          fill: false,
          tension: 0.4,
          pointRadius: 1,
          pointHoverRadius: 3,
          borderDash: [5, 5]
        });
      }

      overviewChartInstances.current[chartKey] = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: datasets
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            title: {
              display: false
            }
          },
          scales: {
            x: {
              title: {
                display: false
              },
              grid: {
                color: '#E5E7EB'
              }
            },
            y: {
              type: 'linear',
              display: true,
              position: 'left',
              title: {
                display: true,
                text: 'Клики',
                color: '#3B82F6'
              },
              grid: {
                color: '#E5E7EB'
              }
            },
            y1: {
              type: 'linear',
              display: true,
              position: 'right',
              title: {
                display: true,
                text: 'Показы',
                color: '#10B981'
              },
              grid: {
                drawOnChartArea: false
              }
            }
          },
          interaction: {
            intersect: false,
            mode: 'index'
          }
        }
      });
    });
  };

  const formatNumber = (num: number): string => {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toLocaleString();
  };

  const getSiteName = (url: string): string => {
    // Return the full URL without https:// prefix, but keep the path
    return url.replace('https://', '').replace('http://', '');
  };

  // Manual refresh function
  const handleRefreshData = () => {
    fetchOverviewData();
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-8">
          <div className="flex justify-between items-center">
            <div>
              <h1 className="text-4xl font-bold text-gray-900 mb-2">
                📈 Обзор сайтов
              </h1>
              <p className="text-gray-600">
                Отслеживание эффективности нескольких сайтов и анализ трендов
              </p>
            </div>
            <div className="flex items-center gap-3">
              <HelpButton title="Что такое «Обзор сайтов»">
                <p>
                  Раздел показывает <strong>несколько сайтов рядом</strong> — с ключевыми метриками и мини-графиком
                  тренда по каждому. Удобно быстро сравнить проекты между собой.
                </p>
                <p>
                  Какие сайты показывать (до 6) — выбираются кнопкой <strong>«Выбрать сайты»</strong> вверху.
                  Период и фильтр по устройствам задаются в параметрах обзора ниже.
                </p>
              </HelpButton>
              <button
                onClick={openPicker}
                className="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded hover:bg-gray-50"
              >
                🗂 Выбрать сайты ({topSites.length}/6)
              </button>
              <button
                onClick={handleRefreshData}
                disabled={overviewLoading || topSites.length === 0}
                className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {overviewLoading ? 'Загрузка...' : '🔄 Обновить данные'}
              </button>
            </div>
          </div>
        </div>

        {/* Controls */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-xl font-semibold mb-4">Параметры обзора</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label htmlFor="time-period" className="block text-sm font-medium text-gray-700 mb-2">
                Период
              </label>
              <select id="time-period" className="w-full" onChange={(e) => setOverviewPeriod(e.target.value)} value={overviewPeriod}>
                {dateRangeOptions.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="device-type" className="block text-sm font-medium text-gray-700 mb-2">
                Тип устройства
              </label>
              <select id="device-type" className="w-full" onChange={(e) => setOverviewDevice(e.target.value)} value={overviewDevice}>
                {deviceOptions.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="secondary-metric" className="block text-sm font-medium text-gray-700 mb-2">
                Дополнительная метрика
              </label>
              <div className="flex gap-2">
                <button
                  onClick={() => setOverviewSecondaryMetric('none')}
                  className={`px-3 py-2 text-sm rounded ${overviewSecondaryMetric === 'none' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'}`}
                >
                  Нет
                </button>
                <button
                  onClick={() => setOverviewSecondaryMetric('ctr')}
                  className={`px-3 py-2 text-sm rounded ${overviewSecondaryMetric === 'ctr' ? 'bg-orange-600 text-white' : 'bg-gray-200 text-gray-700'}`}
                >
                  CTR
                </button>
                <button
                  onClick={() => setOverviewSecondaryMetric('position')}
                  className={`px-3 py-2 text-sm rounded ${overviewSecondaryMetric === 'position' ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-700'}`}
                >
                  Позиция
                </button>
              </div>
            </div>
          </div>
        </div>

        {/* Error Display */}
        {error && (
          <div className="bg-red-50 border border-red-200 rounded p-4 mb-6">
            <h3 className="text-red-800 font-medium">Ошибка</h3>
            <p className="text-red-700 text-sm">{error}</p>
          </div>
        )}

        {/* Loading State */}
        {overviewLoading && topSites.length > 0 && (
          <div className="bg-blue-50 border border-blue-200 rounded p-8 text-center mb-6">
            <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-4"></div>
            <p className="text-blue-800">Загрузка данных обзора для {topSites.length} сайтов...</p>
          </div>
        )}

        {/* No Sites Selected Message */}
        {topSites.length === 0 && !overviewLoading && (
          <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-8 text-center mb-6">
            <div className="text-4xl mb-4">📋</div>
            <h3 className="text-xl font-semibold text-yellow-900 mb-2">
              Сайты не выбраны
            </h3>
            <p className="text-yellow-800 mb-4">
              Выберите сайты (до 6), чтобы увидеть данные обзора.
            </p>
            <button
              onClick={openPicker}
              className="inline-flex items-center space-x-2 px-6 py-3 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors font-medium"
            >
              <span>🗂 Выбрать сайты</span>
            </button>
          </div>
        )}

        {/* Модалка выбора сайтов для обзора (до 6) */}
        {showPicker && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" onClick={() => setShowPicker(false)}>
            <div className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[85vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
              <div className="px-6 py-4 border-b border-gray-200">
                <h2 className="text-lg font-semibold text-gray-900">Сайты для обзора (до 6) — выбрано {pickerSel.length}</h2>
              </div>
              <div className="p-4 border-b border-gray-100">
                <input
                  type="text"
                  value={pickerSearch}
                  onChange={(e) => setPickerSearch(e.target.value)}
                  placeholder="Поиск по домену…"
                  className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div className="flex-1 overflow-y-auto px-2 py-1">
                {sites
                  .filter((s) => s.toLowerCase().includes(pickerSearch.trim().toLowerCase()))
                  .slice(0, 300)
                  .map((site) => {
                    const checked = pickerSel.includes(site);
                    const disabled = !checked && pickerSel.length >= 6;
                    return (
                      <label key={site} className={`flex items-center gap-2 px-3 py-2 rounded text-sm cursor-pointer ${disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50'}`}>
                        <input type="checkbox" checked={checked} disabled={disabled} onChange={() => togglePick(site)} />
                        <span className="truncate" title={site}>{site.replace(/^https?:\/\//, '').replace(/^sc-domain:/, '')}</span>
                      </label>
                    );
                  })}
              </div>
              <div className="px-6 py-4 border-t border-gray-200 flex justify-end gap-2">
                <button onClick={() => setShowPicker(false)} className="px-4 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Отмена</button>
                <button onClick={savePicker} disabled={savingSites} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
                  {savingSites ? 'Сохранение…' : 'Сохранить'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Site Selection Info */}
        {topSites.length > 0 && (
          <div className="bg-blue-50 border border-blue-200 rounded p-4 mb-6">
            <h3 className="text-blue-800 font-medium mb-3">
              📊 Показаны {topSites.length} сайтов
              {overviewData.length > 0 && (
                <span className="ml-2 text-sm font-normal">(Данные из кэша — измените настройки или нажмите «Обновить»)</span>
              )}
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-sm text-blue-700">
              {topSites.map((site, index) => (
                <div key={site} className="bg-white rounded p-2 border border-blue-200">
                  <span className="font-medium">{index + 1}.</span> {getSiteName(site)}
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Overview Charts - Full Width */}
        <div className="grid grid-cols-1 gap-6">
          {overviewData.map((siteData, index) => (
            <div key={index} className="bg-white rounded-lg shadow p-6">
              {/* Site Header with Stats */}
              <div className="mb-6">
                <h3 className="text-xl font-semibold text-gray-900 mb-3" title={siteData.site}>
                  {getSiteName(siteData.site)}
                </h3>
                {siteData.data && (
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div className="bg-blue-50 p-3 rounded-lg">
                      <div className="text-blue-600 font-medium text-sm">Всего кликов</div>
                      <div className="text-lg font-bold text-blue-800">{formatNumber(siteData.data.totalClicks)}</div>
                    </div>
                    <div className="bg-green-50 p-3 rounded-lg">
                      <div className="text-green-600 font-medium text-sm">Всего показов</div>
                      <div className="text-lg font-bold text-green-800">{formatNumber(siteData.data.totalImpressions)}</div>
                    </div>
                    <div className="bg-orange-50 p-3 rounded-lg">
                      <div className="text-orange-600 font-medium text-sm">Средний CTR</div>
                      <div className="text-lg font-bold text-orange-800">{(siteData.data.avgCtr * 100).toFixed(2)}%</div>
                    </div>
                    <div className="bg-red-50 p-3 rounded-lg">
                      <div className="text-red-600 font-medium text-sm">Средняя позиция</div>
                      <div className="text-lg font-bold text-red-800">{siteData.data.avgPosition.toFixed(1)}</div>
                    </div>
                  </div>
                )}
              </div>

              {/* Chart - Full Width */}
              <div className="h-80">
                {siteData.timeSeriesData.length > 0 ? (
                  <canvas
                    ref={(el) => {
                      overviewChartRefs.current[`overview-${index}`] = el;
                    }}
                    className="w-full h-full"
                  />
                ) : (
                  <div className="h-full flex items-center justify-center text-gray-400">
                    <div className="text-center">
                      <div className="text-2xl mb-2">📊</div>
                      <div>Нет данных за этот период</div>
                    </div>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>

        {/* Legend and Instructions */}
        {overviewData.length > 0 && (
          <div className="mt-6 bg-white rounded-lg shadow p-6">
            <h3 className="text-lg font-semibold mb-4">📖 Легенда графика и инструкция</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <h4 className="font-medium text-gray-900 mb-3">Элементы графика:</h4>
                <ul className="space-y-2 text-sm text-gray-700">
                  <li className="flex items-center gap-2">
                    <div className="w-4 h-0.5 bg-blue-500"></div>
                    <span><strong>Синяя линия:</strong> Клики (левая ось)</span>
                  </li>
                  <li className="flex items-center gap-2">
                    <div className="w-4 h-0.5 bg-green-500"></div>
                    <span><strong>Зелёная линия:</strong> Показы (правая ось)</span>
                  </li>
                  {overviewSecondaryMetric === 'ctr' && (
                    <li className="flex items-center gap-2">
                      <div className="w-4 h-0.5 bg-orange-500 border-dashed border-t"></div>
                      <span><strong>Оранжевый пунктир:</strong> CTR % (без оси)</span>
                    </li>
                  )}
                  {overviewSecondaryMetric === 'position' && (
                    <li className="flex items-center gap-2">
                      <div className="w-4 h-0.5 bg-red-500 border-dashed border-t"></div>
                      <span><strong>Красный пунктир:</strong> Средняя позиция (без оси)</span>
                    </li>
                  )}
                </ul>
              </div>
              <div>
                <h4 className="font-medium text-gray-900 mb-3">Как пользоваться:</h4>
                <ul className="space-y-1 text-sm text-gray-700">
                  <li>• <strong>Период:</strong> измените, чтобы посмотреть разные диапазоны дат (до 16 месяцев)</li>
                  <li>• <strong>Фильтр устройств:</strong> сфокусируйтесь на эффективности конкретного устройства</li>
                  <li>• <strong>Дополнительные метрики:</strong> наложите тренды CTR или позиции</li>
                  <li>• <strong>Наведение:</strong> смотрите точные значения в любой точке данных</li>
                  <li>• <strong>Две оси:</strong> сравнивайте клики и показы с корректным масштабированием</li>
                </ul>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
} 