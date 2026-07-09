'use client';

import { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { API_BASE } from '@/lib/api';
import HelpButton from '@/components/ui/HelpButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRefresh, faSpinner, faArrowUp, faArrowDown, faMinus, faSort } from '@fortawesome/free-solid-svg-icons';

interface SiteRow {
  site_url: string;
  account_email: string | null;
  clicks: number;
  impressions: number;
  ctr: number;
  position: number;
  prev_clicks: number;
  prev_impressions: number;
  prev_ctr: number;
  prev_position: number;
  updated_at: string | null;
}

interface Job {
  running: boolean;
  done: number;
  total: number;
  period: number | null;
  error: string | null;
}

type SortKey = 'site_url' | 'clicks' | 'impressions' | 'ctr' | 'position';

const PERIODS = [
  { value: 7, label: '7 дней' },
  { value: 28, label: '28 дней' },
  { value: 90, label: '90 дней' },
];

function fmt(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K';
  return String(Math.round(n));
}

// Δ в процентах для clicks/impressions; знак и цвет
function DeltaPct({ cur, prev }: { cur: number; prev: number }) {
  if (!prev && !cur) return <span className="text-gray-400 text-xs">—</span>;
  if (!prev) return <span className="text-green-600 text-xs">new</span>;
  const pct = ((cur - prev) / prev) * 100;
  const up = pct > 0;
  const flat = Math.abs(pct) < 0.05;
  const color = flat ? 'text-gray-400' : up ? 'text-green-600' : 'text-red-600';
  const icon = flat ? faMinus : up ? faArrowUp : faArrowDown;
  return (
    <span className={`text-xs ${color} inline-flex items-center gap-0.5`}>
      <FontAwesomeIcon icon={icon} /> {Math.abs(pct).toFixed(0)}%
    </span>
  );
}

// Δ для позиции (меньше — лучше): рост позиции (число вниз) = хорошо
function DeltaPos({ cur, prev }: { cur: number; prev: number }) {
  if (!prev && !cur) return <span className="text-gray-400 text-xs">—</span>;
  const diff = cur - prev; // отрицательное = позиция улучшилась
  const flat = Math.abs(diff) < 0.1;
  const better = diff < 0;
  const color = flat ? 'text-gray-400' : better ? 'text-green-600' : 'text-red-600';
  const icon = flat ? faMinus : better ? faArrowUp : faArrowDown;
  return (
    <span className={`text-xs ${color} inline-flex items-center gap-0.5`}>
      <FontAwesomeIcon icon={icon} /> {Math.abs(diff).toFixed(1)}
    </span>
  );
}

export default function MainDashboardPage() {
  const [period, setPeriod] = useState(28);
  const [sites, setSites] = useState<SiteRow[]>([]);
  const [job, setJob] = useState<Job | null>(null);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [sortKey, setSortKey] = useState<SortKey>('clicks');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const loadSummary = useCallback(async (p: number) => {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE}/api/dashboard/summary?period=${p}`);
      const data = await res.json();
      setSites(Array.isArray(data.sites) ? data.sites : []);
      setJob(data.job || null);
    } catch (e) {
      console.error('Error loading dashboard summary:', e);
    } finally {
      setLoading(false);
    }
  }, []);

  // Поллинг статуса, пока идёт фоновое обновление
  useEffect(() => {
    if (job?.running) {
      if (!pollRef.current) {
        pollRef.current = setInterval(async () => {
          try {
            const res = await fetch(`${API_BASE}/api/dashboard/status`);
            const st: Job = await res.json();
            setJob(st);
            if (!st.running) {
              if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
              loadSummary(period);
            }
          } catch { /* ignore */ }
        }, 2000);
      }
    }
    return () => {
      if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    };
  }, [job?.running, period, loadSummary]);

  useEffect(() => {
    loadSummary(period);
  }, [period, loadSummary]);

  const triggerRefresh = async () => {
    try {
      const res = await fetch(`${API_BASE}/api/dashboard/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period }),
      });
      const data = await res.json();
      setJob(data.job || { running: true, done: 0, total: 0, period, error: null });
    } catch (e) {
      console.error('Error triggering refresh:', e);
    }
  };

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortDir(sortDir === 'desc' ? 'asc' : 'desc');
    } else {
      setSortKey(key);
      setSortDir(key === 'site_url' ? 'asc' : 'desc');
    }
  };

  const filteredSorted = useMemo(() => {
    const q = search.toLowerCase();
    const filtered = sites.filter(
      (s) => s.site_url.toLowerCase().includes(q) ||
             (s.account_email || '').toLowerCase().includes(q)
    );
    const dir = sortDir === 'asc' ? 1 : -1;
    return [...filtered].sort((a, b) => {
      const av = a[sortKey];
      const bv = b[sortKey];
      if (typeof av === 'string' && typeof bv === 'string') return av.localeCompare(bv) * dir;
      return ((av as number) - (bv as number)) * dir;
    });
  }, [sites, search, sortKey, sortDir]);

  const totals = useMemo(() => {
    return sites.reduce(
      (acc, s) => {
        acc.clicks += s.clicks;
        acc.impressions += s.impressions;
        acc.prevClicks += s.prev_clicks;
        acc.prevImpr += s.prev_impressions;
        return acc;
      },
      { clicks: 0, impressions: 0, prevClicks: 0, prevImpr: 0 }
    );
  }, [sites]);

  const lastUpdated = useMemo(() => {
    const ts = sites.map((s) => s.updated_at).filter(Boolean).sort();
    return ts.length ? new Date(ts[ts.length - 1] as string).toLocaleString() : null;
  }, [sites]);

  const progressPct = job && job.total ? Math.round((job.done / job.total) * 100) : 0;

  const SortHeader = ({ label, k, right }: { label: string; k: SortKey; right?: boolean }) => (
    <th
      onClick={() => toggleSort(k)}
      className={`px-3 py-3 text-xs font-semibold text-gray-700 uppercase tracking-wider cursor-pointer select-none hover:text-blue-600 ${right ? 'text-right' : 'text-left'}`}
    >
      <span className="inline-flex items-center gap-1">
        {label}
        <FontAwesomeIcon icon={faSort} className={sortKey === k ? 'text-blue-600' : 'text-gray-300'} />
      </span>
    </th>
  );

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Главный дашборд</h1>
            <p className="text-gray-600 mt-1">
              Единый обзор по всем сайтам и аккаунтам{lastUpdated ? ` · обновлено ${lastUpdated}` : ''}
            </p>
          </div>
          <div className="flex items-center gap-3">
            <HelpButton title="Что такое «Главный дашборд»">
              <p>
                Это <strong>единая сводка сразу по всем вашим сайтам и Google-аккаунтам</strong>. По каждому сайту —
                клики, показы, CTR и средняя позиция за выбранный период, а рядом стрелка изменения к <strong>прошлому
                периоду такой же длины</strong> (например, эти 28 дней против предыдущих 28).
              </p>
              <ul className="list-disc list-inside space-y-1.5">
                <li>Вверху — общие итоги по всем сайтам; карточки «Клики/Показы/CTR».</li>
                <li>Таблица ниже — по каждому сайту; столбцы можно <strong>сортировать</strong>, а поле поиска фильтрует по домену или аккаунту.</li>
                <li><strong>«Обновить»</strong> пересчитывает данные из Google (идёт в фоне — виден прогресс).</li>
              </ul>
              <p className="text-gray-500 text-xs">
                Метка «обновлено …» показывает время последнего пересчёта. «new» вместо стрелки означает, что в прошлом
                периоде данных не было.
              </p>
            </HelpButton>
            <select
              value={period}
              onChange={(e) => setPeriod(Number(e.target.value))}
              className="px-3 py-2 border border-gray-300 rounded-lg bg-white"
            >
              {PERIODS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
            </select>
            <button
              onClick={triggerRefresh}
              disabled={job?.running}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 flex items-center gap-2"
            >
              <FontAwesomeIcon icon={job?.running ? faSpinner : faRefresh} className={job?.running ? 'animate-spin' : ''} />
              <span>{job?.running ? 'Обновление…' : 'Обновить'}</span>
            </button>
          </div>
        </div>

        {/* Progress */}
        {job?.running && (
          <div className="mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div className="flex justify-between text-sm text-blue-800 mb-2">
              <span>Считаем метрики по сайтам…</span>
              <span>{job.done}/{job.total}</span>
            </div>
            <div className="w-full bg-blue-100 rounded-full h-2">
              <div className="bg-blue-600 h-2 rounded-full transition-all" style={{ width: `${progressPct}%` }} />
            </div>
          </div>
        )}
        {job?.error && (
          <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-3 text-red-800 text-sm">
            Ошибка обновления: {job.error}
          </div>
        )}

        {/* Summary cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div className="text-sm text-gray-500">Сайтов</div>
            <div className="text-2xl font-bold text-gray-900">{sites.length}</div>
          </div>
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div className="text-sm text-gray-500">Клики</div>
            <div className="text-2xl font-bold text-blue-700">{fmt(totals.clicks)}</div>
            <DeltaPct cur={totals.clicks} prev={totals.prevClicks} />
          </div>
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div className="text-sm text-gray-500">Показы</div>
            <div className="text-2xl font-bold text-green-700">{fmt(totals.impressions)}</div>
            <DeltaPct cur={totals.impressions} prev={totals.prevImpr} />
          </div>
          <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div className="text-sm text-gray-500">Средний CTR</div>
            <div className="text-2xl font-bold text-orange-600">
              {totals.impressions ? ((totals.clicks / totals.impressions) * 100).toFixed(2) : '0.00'}%
            </div>
          </div>
        </div>

        {/* Search */}
        <div className="mb-3">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Поиск по сайту или аккаунту…"
            className="w-full md:w-96 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          />
        </div>

        {/* Table */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
          {loading && sites.length === 0 ? (
            <div className="p-8 text-center text-gray-500">
              <FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl mb-3 text-blue-600" />
              <p>Загрузка…</p>
            </div>
          ) : filteredSorted.length === 0 ? (
            <div className="p-8 text-center text-gray-500">
              {job?.running
                ? 'Метрики считаются, скоро появятся…'
                : 'Нет данных. Подключите аккаунт в настройках и нажмите «Обновить».'}
            </div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <SortHeader label="Сайт" k="site_url" />
                  <th className="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Аккаунт</th>
                  <SortHeader label="Клики" k="clicks" right />
                  <SortHeader label="Показы" k="impressions" right />
                  <SortHeader label="CTR" k="ctr" right />
                  <SortHeader label="Позиция" k="position" right />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {filteredSorted.map((s) => (
                  <tr key={s.site_url} className="hover:bg-gray-50">
                    <td className="px-3 py-2 text-sm text-gray-900 max-w-xs truncate" title={s.site_url}>
                      {s.site_url.replace(/^https?:\/\//, '').replace(/^sc-domain:/, '')}
                    </td>
                    <td className="px-3 py-2 text-xs text-gray-500 max-w-[180px] truncate" title={s.account_email || ''}>
                      {s.account_email || '—'}
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="text-sm font-medium text-gray-900">{fmt(s.clicks)}</div>
                      <DeltaPct cur={s.clicks} prev={s.prev_clicks} />
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="text-sm text-gray-900">{fmt(s.impressions)}</div>
                      <DeltaPct cur={s.impressions} prev={s.prev_impressions} />
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="text-sm text-gray-900">{(s.ctr * 100).toFixed(2)}%</div>
                      <DeltaPct cur={s.ctr} prev={s.prev_ctr} />
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="text-sm text-gray-900">{s.position.toFixed(1)}</div>
                      <DeltaPos cur={s.position} prev={s.prev_position} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
