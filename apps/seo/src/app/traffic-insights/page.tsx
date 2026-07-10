'use client';

import { useState, useEffect, useRef } from 'react';
import { API_BASE } from '@/lib/api';
import HelpButton from '@/components/ui/HelpButton';
import SiteSelect from '@/components/ui/SiteSelect';
import { useData } from '@/contexts/DataContext';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle, faTrophy, faArrowDown } from '@fortawesome/free-solid-svg-icons';
import Chart from 'chart.js/auto';

interface WinnerLoserItem {
  name: string;
  firstHalfClicks: number;
  secondHalfClicks: number;
  change: number;
  changePercent: number;
}

export default function TrafficInsightsPage() {
  const {
    sites,
    selectedSite,
    setSelectedSite,
    device,
    error,
    setError,
    fetchSites,
    winnersLosersData,
    setWinnersLosersData,
    winnersLosersLoading,
    setWinnersLosersLoading
  } = useData();

  // Date range for winners/losers analysis
  // Default end date is 3 days ago to account for API lag
  const [winnersLosersStartDate, setWinnersLosersStartDate] = useState(() => {
    const date = new Date();
    date.setDate(date.getDate() - 33); // 30 days + 3 days buffer
    return date.toISOString().split('T')[0];
  });
  const [winnersLosersEndDate, setWinnersLosersEndDate] = useState(() => {
    const date = new Date();
    date.setDate(date.getDate() - 3); // 3 days ago to account for API lag
    return date.toISOString().split('T')[0];
  });

  const [dateRangePreset, setDateRangePreset] = useState<string>('30days');

  // Chart refs
  const contributionChartRef = useRef<HTMLCanvasElement | null>(null);
  const contributionChartInstance = useRef<Chart | null>(null);

  // Fetch sites on mount
  useEffect(() => {
    fetchSites();
  }, [fetchSites]);

  // Create contribution chart when data changes
  useEffect(() => {
    if (winnersLosersData && winnersLosersData.queries) {
      createContributionChart();
    }

    return () => {
      if (contributionChartInstance.current) {
        contributionChartInstance.current.destroy();
        contributionChartInstance.current = null;
      }
    };
  }, [winnersLosersData]);

  const createContributionChart = () => {
    if (!winnersLosersData || !contributionChartRef.current) return;

    // Destroy existing chart
    if (contributionChartInstance.current) {
      contributionChartInstance.current.destroy();
    }

    // Separate winners and losers
    const winners = winnersLosersData.queries.winners.map(item => ({ ...item, isWinner: true }));
    const losers = winnersLosersData.queries.losers.map(item => ({ ...item, isWinner: false }));

    // Sort winners by change (highest first) - these will be at the top
    const sortedWinners = winners
      .sort((a, b) => b.changePercent - a.changePercent)
      .slice(0, 20); // Top 20 winners

    // Sort losers by change (lowest first, most negative) - these will be at the bottom
    const sortedLosers = losers
      .sort((a, b) => a.changePercent - b.changePercent)
      .slice(0, 20); // Top 20 losers

    // Combine: winners first (positive values grouped at top), then losers (negative values grouped at bottom)
    // For horizontal bar chart: first item in array appears at top of chart
    // Winners are sorted descending (highest first), so highest positive will be at top
    // Losers are sorted ascending (lowest first), so lowest negative will be at bottom
    const chartData = [...sortedWinners, ...sortedLosers];

    const ctx = contributionChartRef.current.getContext('2d');
    if (!ctx) return;

    contributionChartInstance.current = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: chartData.map(item => item.name),
        datasets: [{
          label: '% изменения',
          data: chartData.map(item => item.changePercent),
          backgroundColor: chartData.map(item => 
            item.changePercent >= 0 
              ? 'rgba(34, 197, 94, 0.7)' // Green for positive
              : 'rgba(239, 68, 68, 0.7)'  // Red for negative
          ),
          borderColor: chartData.map(item => 
            item.changePercent >= 0 
              ? 'rgba(34, 197, 94, 1)'
              : 'rgba(239, 68, 68, 1)'
          ),
          borderWidth: 1
        }]
      },
      options: {
        indexAxis: 'y', // Horizontal bar chart
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          title: {
            display: true,
            text: 'Вклад ключевых слов в изменение кликов',
            font: {
              size: 18,
              weight: 'bold'
            },
            padding: {
              top: 10,
              bottom: 20
            }
          },
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label: function(context: any) {
                const item = chartData[context.dataIndex];
                return [
                  `Ключевое слово: ${item.name}`,
                  `% изменения: ${item.changePercent >= 0 ? '+' : ''}${item.changePercent.toFixed(1)}%`,
                  `Изменение кликов: ${item.change >= 0 ? '+' : ''}${item.change.toLocaleString()}`,
                  `Клики на дату начала: ${item.firstHalfClicks.toLocaleString()}`,
                  `Клики на дату окончания: ${item.secondHalfClicks.toLocaleString()}`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            title: {
              display: true,
              text: 'Изменение кликов (%)',
              font: {
                size: 14,
                weight: 'bold'
              }
            },
            grid: {
              color: function(context: any) {
                // Highlight the zero line
                if (context.tick.value === 0) {
                  return 'rgba(0, 0, 0, 0.5)';
                }
                return 'rgba(0, 0, 0, 0.1)';
              },
              lineWidth: function(context: any) {
                return context.tick.value === 0 ? 2 : 1;
              }
            },
            ticks: {
              callback: function(value: any) {
                return value >= 0 ? `+${value}%` : `${value}%`;
              }
            }
          },
          y: {
            title: {
              display: true,
              text: 'Ключевые слова',
              font: {
                size: 14,
                weight: 'bold'
              }
            },
            grid: {
              display: false
            },
            ticks: {
              font: {
                size: 11
              },
              maxRotation: 0,
              autoSkip: false
            }
          }
        }
      }
    });
  };

  // Function to handle date range preset selection
  const handleDateRangePreset = (preset: string) => {
    setDateRangePreset(preset);
    const today = new Date();
    const endDate = new Date();
    endDate.setDate(endDate.getDate() - 3); // Always 3 days ago for end date (API lag)
    
    const startDate = new Date();
    
    switch (preset) {
      case '30days':
        startDate.setDate(startDate.getDate() - 33); // 30 days + 3 days buffer
        break;
      case '3months':
        startDate.setMonth(startDate.getMonth() - 3);
        startDate.setDate(startDate.getDate() - 3); // Buffer
        break;
      case '6months':
        startDate.setMonth(startDate.getMonth() - 6);
        startDate.setDate(startDate.getDate() - 3); // Buffer
        break;
      case '9months':
        startDate.setMonth(startDate.getMonth() - 9);
        startDate.setDate(startDate.getDate() - 3); // Buffer
        break;
      case '12months':
        startDate.setMonth(startDate.getMonth() - 12);
        startDate.setDate(startDate.getDate() - 3); // Buffer
        break;
      case 'custom':
        // Don't change dates for custom, let user set them manually
        return;
      default:
        startDate.setDate(startDate.getDate() - 33);
    }
    
    setWinnersLosersStartDate(startDate.toISOString().split('T')[0]);
    setWinnersLosersEndDate(endDate.toISOString().split('T')[0]);
  };

  const calculateWinnersLosers = async () => {
    if (!selectedSite) {
      alert('Сначала выберите сайт');
      return;
    }

    if (!winnersLosersStartDate || !winnersLosersEndDate) {
      alert('Выберите диапазон дат');
      return;
    }

    setWinnersLosersLoading(true);
    setError('');
    // Don't clear previous data - it will be replaced with new data when calculation completes

    try {
      console.log('=== Starting Winners/Losers Calculation ===');
      console.log('Site:', selectedSite);
      console.log('Start Date:', winnersLosersStartDate);
      console.log('End Date:', winnersLosersEndDate);

      // STEP 1: API Call for queries on first date
      const queriesFirstDateParams = new URLSearchParams({
        siteUrl: selectedSite,
        startDate: winnersLosersStartDate,
        endDate: winnersLosersStartDate,
        dimensions: 'query',
        fetchAll: 'true'
      });
      if (device && device !== 'all') {
        queriesFirstDateParams.append('device', device);
      }

      console.log('Making API call 1: Queries for first date');
      const queriesFirstRes = await fetch(`${API_BASE}/api/data?${queriesFirstDateParams}`);
      
      if (!queriesFirstRes.ok) {
        const errorText = await queriesFirstRes.text();
        console.error('API Error (first date):', errorText);
        throw new Error(`Не удалось загрузить запросы за дату начала: ${queriesFirstRes.status}`);
      }

      const queriesFirstData = await queriesFirstRes.json();
      console.log('API Response 1 (first date):', queriesFirstData);

      if (queriesFirstData.error) {
        throw new Error(queriesFirstData.error);
      }

      // STEP 2: API Call for queries on second date
      const queriesLastDateParams = new URLSearchParams({
        siteUrl: selectedSite,
        startDate: winnersLosersEndDate,
        endDate: winnersLosersEndDate,
        dimensions: 'query',
        fetchAll: 'true'
      });
      if (device && device !== 'all') {
        queriesLastDateParams.append('device', device);
      }

      console.log('Making API call 2: Queries for second date');
      const queriesLastRes = await fetch(`${API_BASE}/api/data?${queriesLastDateParams}`);
      
      if (!queriesLastRes.ok) {
        const errorText = await queriesLastRes.text();
        console.error('API Error (second date):', errorText);
        throw new Error(`Не удалось загрузить запросы за дату окончания: ${queriesLastRes.status}`);
      }

      const queriesLastData = await queriesLastRes.json();
      console.log('API Response 2 (second date):', queriesLastData);

      if (queriesLastData.error) {
        throw new Error(queriesLastData.error);
      }

      // STEP 3: Process queries data
      const queriesFirstRows = queriesFirstData.rows || [];
      const queriesLastRows = queriesLastData.rows || [];
      
      console.log(`Found ${queriesFirstRows.length} queries on first date`);
      console.log(`Found ${queriesLastRows.length} queries on second date`);

      // Create a map to store clicks for each query
      const queriesMap = new Map<string, { first: number; last: number }>();
      
      // Add first date data
      queriesFirstRows.forEach((row: any) => {
        const query = row.keys?.[0] || '';
        const clicks = row.clicks || 0;
        if (query) {
          queriesMap.set(query, { first: clicks, last: 0 });
        }
      });

      // Add second date data
      queriesLastRows.forEach((row: any) => {
        const query = row.keys?.[0] || '';
        const clicks = row.clicks || 0;
        if (query) {
          const existing = queriesMap.get(query) || { first: 0, last: 0 };
          queriesMap.set(query, { ...existing, last: clicks });
        }
      });
      
      console.log(`Total unique queries found: ${queriesMap.size}`);

      // STEP 4: Calculate change for each query
      const queryItems: WinnerLoserItem[] = Array.from(queriesMap.entries())
        .map(([query, clicks]) => {
          const change = clicks.last - clicks.first;
          const changePercent = clicks.first > 0 
            ? ((change / clicks.first) * 100) 
            : (clicks.last > 0 ? 100 : 0);
          
          return {
            name: query,
            firstHalfClicks: clicks.first,
            secondHalfClicks: clicks.last,
            change,
            changePercent
          };
        })
        .filter(item => item.firstHalfClicks > 0 || item.secondHalfClicks > 0);

      console.log(`Total query items after filtering: ${queryItems.length}`);

      // STEP 5: Get top 20 winners (growth in clicks)
      const queryWinners = queryItems
        .filter(item => item.change > 0)
        .sort((a, b) => b.change - a.change)
        .slice(0, 20);

      // STEP 6: Get top 20 losers (decrease in clicks)
      const queryLosers = queryItems
        .filter(item => item.change < 0)
        .sort((a, b) => a.change - b.change)
        .slice(0, 20);

      console.log(`Winners: ${queryWinners.length} queries`);
      console.log(`Losers: ${queryLosers.length} queries`);

      // STEP 7: API Call for pages/URLs on first date
      const pagesFirstDateParams = new URLSearchParams({
        siteUrl: selectedSite,
        startDate: winnersLosersStartDate,
        endDate: winnersLosersStartDate,
        dimensions: 'page',
        fetchAll: 'true'
      });
      if (device && device !== 'all') {
        pagesFirstDateParams.append('device', device);
      }

      console.log('Making API call 3: Pages/URLs for first date');
      const pagesFirstRes = await fetch(`${API_BASE}/api/data?${pagesFirstDateParams}`);
      
      if (!pagesFirstRes.ok) {
        const errorText = await pagesFirstRes.text();
        console.error('API Error (pages first date):', errorText);
        throw new Error(`Не удалось загрузить страницы за дату начала: ${pagesFirstRes.status}`);
      }

      const pagesFirstData = await pagesFirstRes.json();
      console.log('API Response 3 (pages first date):', pagesFirstData);

      if (pagesFirstData.error) {
        throw new Error(pagesFirstData.error);
      }

      // STEP 8: API Call for pages/URLs on second date
      const pagesLastDateParams = new URLSearchParams({
        siteUrl: selectedSite,
        startDate: winnersLosersEndDate,
        endDate: winnersLosersEndDate,
        dimensions: 'page',
        fetchAll: 'true'
      });
      if (device && device !== 'all') {
        pagesLastDateParams.append('device', device);
      }

      console.log('Making API call 4: Pages/URLs for second date');
      const pagesLastRes = await fetch(`${API_BASE}/api/data?${pagesLastDateParams}`);
      
      if (!pagesLastRes.ok) {
        const errorText = await pagesLastRes.text();
        console.error('API Error (pages second date):', errorText);
        throw new Error(`Не удалось загрузить страницы за дату окончания: ${pagesLastRes.status}`);
      }

      const pagesLastData = await pagesLastRes.json();
      console.log('API Response 4 (pages second date):', pagesLastData);

      if (pagesLastData.error) {
        throw new Error(pagesLastData.error);
      }

      // STEP 9: Process pages/URLs data
      const pagesFirstRows = pagesFirstData.rows || [];
      const pagesLastRows = pagesLastData.rows || [];
      
      console.log(`Found ${pagesFirstRows.length} pages/URLs on first date`);
      console.log(`Found ${pagesLastRows.length} pages/URLs on second date`);

      // Create a map to store clicks for each page/URL
      const pagesMap = new Map<string, { first: number; last: number }>();
      
      // Add first date data
      pagesFirstRows.forEach((row: any) => {
        const page = row.keys?.[0] || '';
        const clicks = row.clicks || 0;
        if (page) {
          pagesMap.set(page, { first: clicks, last: 0 });
        }
      });

      // Add second date data
      pagesLastRows.forEach((row: any) => {
        const page = row.keys?.[0] || '';
        const clicks = row.clicks || 0;
        if (page) {
          const existing = pagesMap.get(page) || { first: 0, last: 0 };
          pagesMap.set(page, { ...existing, last: clicks });
        }
      });
      
      console.log(`Total unique pages/URLs found: ${pagesMap.size}`);

      // STEP 10: Calculate change for each page/URL
      const pageItems: WinnerLoserItem[] = Array.from(pagesMap.entries())
        .map(([page, clicks]) => {
          const change = clicks.last - clicks.first;
          const changePercent = clicks.first > 0 
            ? ((change / clicks.first) * 100) 
            : (clicks.last > 0 ? 100 : 0);
          
          return {
            name: page,
            firstHalfClicks: clicks.first,
            secondHalfClicks: clicks.last,
            change,
            changePercent
          };
        })
        .filter(item => item.firstHalfClicks > 0 || item.secondHalfClicks > 0);

      console.log(`Total page items after filtering: ${pageItems.length}`);

      // STEP 11: Get top 20 winners (growth in clicks)
      const pageWinners = pageItems
        .filter(item => item.change > 0)
        .sort((a, b) => b.change - a.change)
        .slice(0, 20);

      // STEP 12: Get top 20 losers (decrease in clicks)
      const pageLosers = pageItems
        .filter(item => item.change < 0)
        .sort((a, b) => a.change - b.change)
        .slice(0, 20);

      console.log(`Page Winners: ${pageWinners.length} URLs`);
      console.log(`Page Losers: ${pageLosers.length} URLs`);

      // STEP 13: Set the final data
      const finalData = {
        queries: {
          winners: queryWinners,
          losers: queryLosers
        },
        pages: {
          winners: pageWinners,
          losers: pageLosers
        }
      };

      console.log('=== Final Data ===', finalData);
      setWinnersLosersData(finalData);
      console.log('Data set successfully!');
      
    } catch (error: any) {
      console.error('Error calculating winners/losers:', error);
      setError(`Не удалось рассчитать лидеров/аутсайдеров: ${error.message || error}. Убедитесь, что бэкенд запущен.`);
      setWinnersLosersData({
        queries: { winners: [], losers: [] },
        pages: { winners: [], losers: [] }
      });
    } finally {
      setWinnersLosersLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Header */}
        <div className="mb-8 flex items-start justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 flex items-center space-x-3">
              <FontAwesomeIcon icon={faTrophy} className="text-green-600" />
              <span>Аналитика трафика</span>
            </h1>
            <p className="mt-2 text-gray-600">
              Анализируйте лидеров и аутсайдеров в трафике вашего Google Search Console
            </p>
          </div>
          <HelpButton title="Что такое «Аналитика трафика»">
            <p>
              Раздел сравнивает трафик <strong>между двумя датами</strong> и показывает, <strong>что выросло, а что
              просело</strong>: топ растущих и топ падающих запросов и страниц.
            </p>
            <p>
              Так быстро видно, какие ключи и URL дали прирост кликов, а какие — потеряли. Полезно после обновлений
              Google или изменений на сайте, чтобы понять причину скачка трафика.
            </p>
            <p className="text-gray-500 text-xs">Учитывается задержка данных GSC (~3 дня).</p>
          </HelpButton>
        </div>

        {/* Error Display */}
        {error && (
          <div className="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
            <div className="flex items-start space-x-3">
              <FontAwesomeIcon icon={faExclamationTriangle} className="text-red-600 mt-1" />
              <div>
                <h3 className="text-red-800 font-medium">Ошибка</h3>
                <p className="text-red-700 text-sm mt-1">{error}</p>
              </div>
            </div>
          </div>
        )}

        {/* Winners & Losers Controls */}
        <div className="mb-6 bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Анализ лидеров и аутсайдеров</h3>
          
          {/* Date Range Preset Selector */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Диапазон дат
            </label>
            <div className="flex flex-wrap gap-2">
              <button
                onClick={() => handleDateRangePreset('30days')}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  dateRangePreset === '30days'
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                30 дней
              </button>
              <button
                onClick={() => handleDateRangePreset('3months')}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  dateRangePreset === '3months'
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                3 месяца
              </button>
              <button
                onClick={() => handleDateRangePreset('6months')}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  dateRangePreset === '6months'
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                6 месяцев
              </button>
              <button
                onClick={() => handleDateRangePreset('9months')}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  dateRangePreset === '9months'
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                9 месяцев
              </button>
              <button
                onClick={() => handleDateRangePreset('12months')}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  dateRangePreset === '12months'
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                12 месяцев
              </button>
              <button
                onClick={() => {
                  setDateRangePreset('custom');
                }}
                className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  dateRangePreset === 'custom'
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                Свой
              </button>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Сайт (доступно: {sites.length})
              </label>
              <SiteSelect sites={sites} value={selectedSite} onChange={setSelectedSite} />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Дата начала
              </label>
              <input
                type="date"
                value={winnersLosersStartDate}
                onChange={(e) => {
                  setWinnersLosersStartDate(e.target.value);
                  setDateRangePreset('custom');
                }}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Дата окончания <span className="text-xs text-gray-500">(3 дня назад из-за задержки API)</span>
              </label>
              <input
                type="date"
                value={winnersLosersEndDate}
                max={(() => {
                  const maxDate = new Date();
                  maxDate.setDate(maxDate.getDate() - 3);
                  return maxDate.toISOString().split('T')[0];
                })()}
                onChange={(e) => {
                  setWinnersLosersEndDate(e.target.value);
                  setDateRangePreset('custom');
                }}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>
            <div className="flex items-end">
              <button
                onClick={calculateWinnersLosers}
                disabled={winnersLosersLoading || !selectedSite}
                className="w-full px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center justify-center space-x-2"
              >
                {winnersLosersLoading && <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>}
                <FontAwesomeIcon icon={faTrophy} />
                <span>{winnersLosersLoading ? 'Расчёт...' : 'Рассчитать'}</span>
              </button>
            </div>
          </div>
          <p className="text-sm text-gray-500">
            Сравнивает клики на дату начала и дату окончания. Лидеры — это топ-20 ключевых слов/URL, которые выросли, аутсайдеры — топ-20, которые не выросли. Дата окончания по умолчанию — 3 дня назад, чтобы учесть задержку данных API Google Search Console.
          </p>
        </div>


        {/* Debug Info */}
        {winnersLosersData && (
          <div className="mb-4 bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-xs">
            <strong>Отладка:</strong> Данные загружены — лидеры (запросы): {winnersLosersData.queries.winners.length},
            аутсайдеры (запросы): {winnersLosersData.queries.losers.length},
            лидеры (страницы): {winnersLosersData.pages.winners.length},
            аутсайдеры (страницы): {winnersLosersData.pages.losers.length}
          </div>
        )}

        {/* Winners & Losers Tables */}
        {winnersLosersData && (
          <div className="space-y-6">
            {/* Keywords Section */}
            <div className="bg-white rounded-lg shadow p-6">
              <h2 className="text-2xl font-semibold text-gray-900 mb-6">Анализ ключевых слов</h2>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Winners - Keywords */}
                <div>
                  <h3 className="text-lg font-semibold text-green-700 mb-4 flex items-center space-x-2">
                    <FontAwesomeIcon icon={faTrophy} />
                    <span>Лидеры (рост кликов)</span>
                  </h3>
                  {winnersLosersData.queries.winners.length > 0 ? (
                    <div className="overflow-x-auto">
                      <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                          <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ключевое слово</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersStartDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersEndDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Изменение</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">% изменения</th>
                          </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                          {winnersLosersData.queries.winners.map((item, index) => (
                            <tr key={index} className="hover:bg-gray-50">
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{item.name}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.firstHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.secondHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-green-600">+{item.change.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-green-600">+{item.changePercent.toFixed(1)}%</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-gray-500 text-sm">Лидеры не найдены</p>
                  )}
                </div>

                {/* Losers - Keywords */}
                <div>
                  <h3 className="text-lg font-semibold text-red-700 mb-4 flex items-center space-x-2">
                    <FontAwesomeIcon icon={faArrowDown} />
                    <span>Аутсайдеры (снижение кликов)</span>
                  </h3>
                  {winnersLosersData.queries.losers.length > 0 ? (
                    <div className="overflow-x-auto">
                      <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                          <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ключевое слово</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersStartDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersEndDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Изменение</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">% изменения</th>
                          </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                          {winnersLosersData.queries.losers.map((item, index) => (
                            <tr key={index} className="hover:bg-gray-50">
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{item.name}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.firstHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.secondHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-red-600">{item.change.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-red-600">{item.changePercent.toFixed(1)}%</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-gray-500 text-sm">Аутсайдеры не найдены</p>
                  )}
                </div>
              </div>

              {/* Contribution Chart */}
              {winnersLosersData && (winnersLosersData.queries.winners.length > 0 || winnersLosersData.queries.losers.length > 0) && (
                <div className="mt-8">
                  <h3 className="text-lg font-semibold text-gray-900 mb-4">Вклад ключевых слов в изменение кликов</h3>
                  <div className="bg-white rounded-lg p-4" style={{ height: '600px' }}>
                    <canvas ref={contributionChartRef}></canvas>
                  </div>
                </div>
              )}
            </div>

            {/* URLs Section */}
            <div className="bg-white rounded-lg shadow p-6">
              <h2 className="text-2xl font-semibold text-gray-900 mb-6">Анализ URL</h2>
              
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Winners - URLs */}
                <div>
                  <h3 className="text-lg font-semibold text-green-700 mb-4 flex items-center space-x-2">
                    <FontAwesomeIcon icon={faTrophy} />
                    <span>Лидеры (рост кликов)</span>
                  </h3>
                  {winnersLosersData.pages.winners.length > 0 ? (
                    <div className="overflow-x-auto">
                      <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                          <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersStartDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersEndDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Изменение</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">% изменения</th>
                          </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                          {winnersLosersData.pages.winners.map((item, index) => (
                            <tr key={index} className="hover:bg-gray-50">
                              <td className="px-4 py-3 text-sm font-medium text-gray-900 break-all max-w-xs">{item.name}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.firstHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.secondHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-green-600">+{item.change.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-green-600">+{item.changePercent.toFixed(1)}%</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-gray-500 text-sm">Лидеры не найдены</p>
                  )}
                </div>

                {/* Losers - URLs */}
                <div>
                  <h3 className="text-lg font-semibold text-red-700 mb-4 flex items-center space-x-2">
                    <FontAwesomeIcon icon={faArrowDown} />
                    <span>Аутсайдеры (снижение кликов)</span>
                  </h3>
                  {winnersLosersData.pages.losers.length > 0 ? (
                    <div className="overflow-x-auto">
                      <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                          <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersStartDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Клики ({winnersLosersEndDate})</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Изменение</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">% изменения</th>
                          </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                          {winnersLosersData.pages.losers.map((item, index) => (
                            <tr key={index} className="hover:bg-gray-50">
                              <td className="px-4 py-3 text-sm font-medium text-gray-900 break-all max-w-xs">{item.name}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.firstHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{item.secondHalfClicks.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-red-600">{item.change.toLocaleString()}</td>
                              <td className="px-4 py-3 whitespace-nowrap text-sm font-semibold text-red-600">{item.changePercent.toFixed(1)}%</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <p className="text-gray-500 text-sm">Аутсайдеры не найдены</p>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Empty State */}
        {!winnersLosersData && !winnersLosersLoading && (
          <div className="bg-white rounded-lg shadow p-12 text-center">
            <FontAwesomeIcon icon={faTrophy} className="text-gray-400 text-6xl mb-4" />
            <h3 className="text-xl font-semibold text-gray-900 mb-2">Анализ ещё не выполнен</h3>
            <p className="text-gray-600 mb-6">
              Выберите сайт и диапазон дат выше, затем нажмите «Рассчитать», чтобы увидеть лидеров и аутсайдеров.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}

