'use client';

import { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { API_BASE } from '@/lib/api';
import HelpButton from '@/components/ui/HelpButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRefresh, faSpinner, faArrowUp, faArrowDown, faMinus, faRotate, faTriangleExclamation, faXmark } from '@fortawesome/free-solid-svg-icons';

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
  daily_clicks: number[];
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

// Мини-график (спарклайн) дневных кликов текущего периода.
function Sparkline({ data, color = '#2563eb' }: { data: number[]; color?: string }) {
  if (!data || data.length < 2) {
    return <div className="h-12 flex items-center justify-center text-xs text-gray-300">нет данных за период</div>;
  }
  const w = 100, h = 32;
  const max = Math.max(...data, 1);
  const min = Math.min(...data, 0);
  const range = max - min || 1;
  const pts = data.map((v, i) => {
    const x = (i / (data.length - 1)) * w;
    const y = h - ((v - min) / range) * h;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
  return (
    <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" className="w-full h-12" aria-hidden>
      <polyline points={`0,${h} ${pts} ${w},${h}`} fill={color} fillOpacity="0.08" stroke="none" />
      <polyline points={pts} fill="none" stroke={color} strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
    </svg>
  );
}

// Варианты сортировки карточек (ключ + направление).
const SORT_OPTIONS: { value: string; label: string; key: SortKey; dir: 'asc' | 'desc' }[] = [
  { value: 'clicks-desc', label: 'Клики ↓', key: 'clicks', dir: 'desc' },
  { value: 'impressions-desc', label: 'Показы ↓', key: 'impressions', dir: 'desc' },
  { value: 'ctr-desc', label: 'CTR ↓', key: 'ctr', dir: 'desc' },
  { value: 'position-asc', label: 'Позиция ↑ (лучшие)', key: 'position', dir: 'asc' },
  { value: 'site_url-asc', label: 'Домен A→Я', key: 'site_url', dir: 'asc' },
];

export default function MainDashboardPage() {
  const [period, setPeriod] = useState(28);
  const [sites, setSites] = useState<SiteRow[]>([]);
  const [job, setJob] = useState<Job | null>(null);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [sortKey, setSortKey] = useState<SortKey>('clicks');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  // Бесконечный скролл: сколько карточек показываем сейчас (догружаем при прокрутке).
  const [visibleCount, setVisibleCount] = useState(24);
  const sentinelRef = useRef<HTMLDivElement | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
  // Вкладка «Не подтверждённые»
  const [showUnverified, setShowUnverified] = useState(false);
  const [unverified, setUnverified] = useState<{ site_url: string; account_email: string | null }[]>([]);
  const [unverifiedLoading, setUnverifiedLoading] = useState(false);

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

  // Синхронизация: подтянуть новые сайты из GSC и посчитать метрики ТОЛЬКО для них
  // (уже загруженные не пересчитываются).
  const syncSites = async () => {
    try {
      const res = await fetch(`${API_BASE}/api/dashboard/sync`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period }),
      });
      const data = await res.json();
      setJob(data.job || { running: true, done: 0, total: 0, period, error: null });
    } catch (e) {
      console.error('Error syncing sites:', e);
    }
  };

  const openUnverified = async () => {
    setShowUnverified(true);
    setUnverifiedLoading(true);
    try {
      const res = await fetch(`${API_BASE}/api/accounts/unverified`);
      const data = await res.json();
      setUnverified(Array.isArray(data.sites) ? data.sites : []);
    } catch {
      setUnverified([]);
    } finally {
      setUnverifiedLoading(false);
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

  // При смене поиска/сортировки/периода показываем снова с начала.
  useEffect(() => { setVisibleCount(24); }, [search, sortKey, sortDir, period]);

  const visible = useMemo(() => filteredSorted.slice(0, visibleCount), [filteredSorted, visibleCount]);

  // Догружаем следующую порцию, когда «маячок» внизу попадает в зону видимости.
  useEffect(() => {
    const el = sentinelRef.current;
    if (!el || visible.length >= filteredSorted.length) return;
    const obs = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) setVisibleCount((c) => c + 24);
    }, { rootMargin: '600px' });
    obs.observe(el);
    return () => obs.disconnect();
  }, [visible.length, filteredSorted.length]);

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
          <div className="flex flex-wrap items-center gap-2">
            <button
              onClick={syncSites}
              disabled={job?.running}
              className="px-3 py-2 border border-gray-300 bg-white text-gray-700 rounded-lg hover:bg-gray-50 disabled:opacity-50 flex items-center gap-2 text-sm"
              title="Подтянуть новые сайты из Google (уже загруженные не пересчитываются)"
            >
              <FontAwesomeIcon icon={job?.running ? faSpinner : faRotate} className={job?.running ? 'animate-spin' : ''} />
              <span>Синхронизировать</span>
            </button>
            <button
              onClick={openUnverified}
              className="px-3 py-2 border border-gray-300 bg-white text-gray-700 rounded-lg hover:bg-gray-50 flex items-center gap-2 text-sm"
              title="Сайты со статусом «не подтверждён в консоли» по всем аккаунтам"
            >
              <FontAwesomeIcon icon={faTriangleExclamation} className="text-yellow-500" />
              <span>Не подтверждённые</span>
            </button>
            <HelpButton title="Что такое «Главный дашборд»">
              <p>
                Это <strong>единая сводка сразу по всем вашим сайтам и Google-аккаунтам</strong>. По каждому сайту —
                клики, показы, CTR и средняя позиция за выбранный период, а рядом стрелка изменения к <strong>прошлому
                периоду такой же длины</strong> (например, эти 28 дней против предыдущих 28).
              </p>
              <ul className="list-disc list-inside space-y-1.5">
                <li>Вверху — общие итоги по всем сайтам; карточки «Клики/Показы/CTR».</li>
                <li>Таблица ниже — по каждому сайту; столбцы можно <strong>сортировать</strong>, а поле поиска фильтрует по домену или аккаунту.</li>
                <li><strong>«Обновить»</strong> пересчитывает данные по всем сайтам из Google (идёт в фоне — виден прогресс).</li>
                <li><strong>«Синхронизировать»</strong> — подтянуть только <em>новые</em> сайты из Google и посчитать метрики лишь для них (уже загруженные сотни сайтов заново не считаются — быстро).</li>
                <li><strong>«Не подтверждённые»</strong> — список сайтов, добавленных в аккаунт, но без подтверждённого владения; в анализ они не идут.</li>
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

        {/* Search + sort */}
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Поиск по сайту или аккаунту…"
            className="flex-1 min-w-[220px] px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          />
          <select
            value={`${sortKey}-${sortDir}`}
            onChange={(e) => {
              const opt = SORT_OPTIONS.find((o) => o.value === e.target.value);
              if (opt) { setSortKey(opt.key); setSortDir(opt.dir); }
            }}
            className="px-3 py-2 border border-gray-300 rounded-lg bg-white"
          >
            {SORT_OPTIONS.map((o) => <option key={o.value} value={o.value}>Сортировка: {o.label}</option>)}
          </select>
          <span className="text-sm text-gray-500">Найдено: {filteredSorted.length}</span>
        </div>

        {/* Карточки сайтов с мини-графиками */}
        {loading && sites.length === 0 ? (
          <div className="p-8 text-center text-gray-500 bg-white rounded-lg border border-gray-200">
            <FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl mb-3 text-blue-600" />
            <p>Загрузка…</p>
          </div>
        ) : filteredSorted.length === 0 ? (
          <div className="p-8 text-center text-gray-500 bg-white rounded-lg border border-gray-200">
            {job?.running
              ? 'Метрики считаются, скоро появятся…'
              : 'Нет данных. Подключите аккаунт в настройках и нажмите «Обновить».'}
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {visible.map((s) => (
              <div key={s.site_url} className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:shadow-md transition-shadow">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="font-medium text-gray-900 truncate" title={s.site_url}>
                      {s.site_url.replace(/^https?:\/\//, '').replace(/^sc-domain:/, '')}
                    </div>
                    <div className="text-xs text-gray-400 truncate" title={s.account_email || ''}>{s.account_email || '—'}</div>
                  </div>
                  <div className="text-right flex-shrink-0">
                    <div className="text-xl font-bold text-blue-700 leading-none">{fmt(s.clicks)}</div>
                    <div className="text-[11px] text-gray-400">кликов</div>
                    <DeltaPct cur={s.clicks} prev={s.prev_clicks} />
                  </div>
                </div>

                <div className="my-3">
                  <Sparkline data={s.daily_clicks} />
                </div>

                <div className="grid grid-cols-3 gap-2 text-center border-t border-gray-100 pt-3">
                  <div>
                    <div className="text-sm font-medium text-gray-900">{fmt(s.impressions)}</div>
                    <div className="text-[11px] text-gray-400">показы</div>
                    <DeltaPct cur={s.impressions} prev={s.prev_impressions} />
                  </div>
                  <div>
                    <div className="text-sm font-medium text-gray-900">{(s.ctr * 100).toFixed(2)}%</div>
                    <div className="text-[11px] text-gray-400">CTR</div>
                    <DeltaPct cur={s.ctr} prev={s.prev_ctr} />
                  </div>
                  <div>
                    <div className="text-sm font-medium text-gray-900">{s.position.toFixed(1)}</div>
                    <div className="text-[11px] text-gray-400">позиция</div>
                    <DeltaPos cur={s.position} prev={s.prev_position} />
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Маячок бесконечной прокрутки + счётчик */}
        {filteredSorted.length > 0 && (
          <div ref={sentinelRef} className="py-6 text-center text-sm text-gray-400">
            {visible.length < filteredSorted.length
              ? 'Прокрутите, чтобы показать ещё…'
              : `Показаны все: ${filteredSorted.length}`}
          </div>
        )}

        {/* Модалка «Не подтверждённые» — собирает неподтверждённые сайты по всем аккаунтам */}
        {showUnverified && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" onClick={() => setShowUnverified(false)}>
            <div className="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
              <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                  <FontAwesomeIcon icon={faTriangleExclamation} className="text-yellow-500" />
                  Не подтверждённые сайты{!unverifiedLoading ? ` (${unverified.length})` : ''}
                </h2>
                <button onClick={() => setShowUnverified(false)} className="text-gray-400 hover:text-gray-700" aria-label="Закрыть">
                  <FontAwesomeIcon icon={faXmark} className="text-xl" />
                </button>
              </div>
              <div className="px-6 py-3 text-xs text-gray-500 border-b border-gray-100">
                Эти сайты добавлены в аккаунт Google, но право собственности не подтверждено. В анализ они не
                попадают. Подтвердите их в Google Search Console, затем нажмите «Синхронизировать».
              </div>
              <div className="flex-1 overflow-y-auto">
                {unverifiedLoading ? (
                  <div className="p-8 text-center text-gray-500">
                    <FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl text-blue-600" />
                  </div>
                ) : unverified.length === 0 ? (
                  <div className="p-8 text-center text-gray-500">Неподтверждённых сайтов нет 🎉</div>
                ) : (
                  <ul className="divide-y divide-gray-100">
                    {unverified.map((u) => (
                      <li key={`${u.account_email}|${u.site_url}`} className="px-6 py-2.5 flex items-center justify-between gap-3">
                        <a href={u.site_url.replace(/^sc-domain:/, 'https://')} target="_blank" rel="noopener noreferrer"
                           className="text-sm text-blue-700 hover:underline truncate" title={u.site_url}>
                          {u.site_url.replace(/^https?:\/\//, '').replace(/^sc-domain:/, '')}
                        </a>
                        <span className="text-xs text-gray-400 flex-shrink-0 truncate max-w-[200px]" title={u.account_email || ''}>{u.account_email || '—'}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <div className="px-6 py-4 border-t border-gray-200 text-right">
                <button onClick={() => setShowUnverified(false)} className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                  Закрыть
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
