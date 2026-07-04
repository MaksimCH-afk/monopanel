'use client';

import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { API_BASE } from '@/lib/api';
import Chart from 'chart.js/auto';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSpinner, faPlus, faTrash, faArrowUp, faArrowDown, faMinus, faTimes, faLink, faWrench, faPen, faNoteSticky } from '@fortawesome/free-solid-svg-icons';

interface DayPoint { date: string; clicks: number; impressions: number; ctr: number; position: number; }
interface Annotation { id: number; site_url: string; url: string | null; date: string; text: string; category: string; }
interface PageRow {
  url: string; clicks: number; impressions: number; ctr: number; position: number;
  prev_clicks: number; prev_impressions: number; prev_ctr: number; prev_position: number;
}
interface Details { url: string; timeseries: DayPoint[]; queries: Array<{ query: string; clicks: number; impressions: number; ctr: number; position: number }>; }

const PERIODS = [{ value: 7, label: '7 дней' }, { value: 28, label: '28 дней' }, { value: 90, label: '90 дней' }];

const CATEGORIES: Record<string, { label: string; color: string; icon: any }> = {
  backlink: { label: 'Беклинк', color: '#8B5CF6', icon: faLink },
  work: { label: 'Работы', color: '#2563EB', icon: faWrench },
  change: { label: 'Изменение', color: '#F59E0B', icon: faPen },
  note: { label: 'Заметка', color: '#6B7280', icon: faNoteSticky },
};

function fmt(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K';
  return String(Math.round(n));
}

function DeltaPct({ cur, prev }: { cur: number; prev: number }) {
  if (!prev && !cur) return <span className="text-gray-400 text-xs">—</span>;
  if (!prev) return <span className="text-green-600 text-xs">new</span>;
  const pct = ((cur - prev) / prev) * 100;
  const flat = Math.abs(pct) < 0.05;
  const up = pct > 0;
  const color = flat ? 'text-gray-400' : up ? 'text-green-600' : 'text-red-600';
  const icon = flat ? faMinus : up ? faArrowUp : faArrowDown;
  return <span className={`text-xs ${color} inline-flex items-center gap-0.5`}><FontAwesomeIcon icon={icon} /> {Math.abs(pct).toFixed(0)}%</span>;
}

function DeltaPos({ cur, prev }: { cur: number; prev: number }) {
  if (!prev && !cur) return <span className="text-gray-400 text-xs">—</span>;
  const diff = cur - prev;
  const flat = Math.abs(diff) < 0.1;
  const better = diff < 0;
  const color = flat ? 'text-gray-400' : better ? 'text-green-600' : 'text-red-600';
  const icon = flat ? faMinus : better ? faArrowUp : faArrowDown;
  return <span className={`text-xs ${color} inline-flex items-center gap-0.5`}><FontAwesomeIcon icon={icon} /> {Math.abs(diff).toFixed(1)}</span>;
}

export default function PagesPage() {
  const [sites, setSites] = useState<string[]>([]);
  const [selectedSite, setSelectedSite] = useState('');
  const [period, setPeriod] = useState(28);

  const [series, setSeries] = useState<DayPoint[]>([]);
  const [annotations, setAnnotations] = useState<Annotation[]>([]);
  const [pages, setPages] = useState<PageRow[]>([]);
  const [loadingPages, setLoadingPages] = useState(false);
  const [search, setSearch] = useState('');

  const [details, setDetails] = useState<Details | null>(null);
  const [detailsLoading, setDetailsLoading] = useState(false);

  // форма заметки
  const [annDate, setAnnDate] = useState('');
  const [annCat, setAnnCat] = useState('backlink');
  const [annText, setAnnText] = useState('');
  const [annUrl, setAnnUrl] = useState('');
  const [savingAnn, setSavingAnn] = useState(false);

  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const chartRef = useRef<Chart | null>(null);

  // Загрузка сайтов
  useEffect(() => {
    fetch(`${API_BASE}/api/sites`).then(r => r.json()).then(d => {
      const s = Array.isArray(d.sites) ? d.sites : [];
      setSites(s);
      if (s.length && !selectedSite) setSelectedSite(s[0]);
    }).catch(() => {});
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const loadSeries = useCallback(async () => {
    if (!selectedSite) return;
    const end = new Date(); end.setDate(end.getDate() - 3);
    const start = new Date(end); start.setDate(start.getDate() - period + 1);
    const params = new URLSearchParams({
      siteUrl: selectedSite,
      startDate: start.toISOString().split('T')[0],
      endDate: end.toISOString().split('T')[0],
      dimensions: 'date',
    });
    try {
      const r = await fetch(`${API_BASE}/api/data?${params}`);
      const d = await r.json();
      const rows = (d.rows || []).map((row: any) => ({
        date: row.keys?.[0], clicks: row.clicks, impressions: row.impressions, ctr: row.ctr, position: row.position,
      })).sort((a: DayPoint, b: DayPoint) => a.date.localeCompare(b.date));
      setSeries(rows);
    } catch { setSeries([]); }
  }, [selectedSite, period]);

  const loadAnnotations = useCallback(async () => {
    if (!selectedSite) return;
    try {
      const r = await fetch(`${API_BASE}/api/annotations?siteUrl=${encodeURIComponent(selectedSite)}`);
      const d = await r.json();
      setAnnotations(Array.isArray(d.annotations) ? d.annotations : []);
    } catch { setAnnotations([]); }
  }, [selectedSite]);

  const loadPages = useCallback(async () => {
    if (!selectedSite) return;
    setLoadingPages(true);
    try {
      const r = await fetch(`${API_BASE}/api/pages/summary?siteUrl=${encodeURIComponent(selectedSite)}&period=${period}`);
      const d = await r.json();
      setPages(Array.isArray(d.pages) ? d.pages : []);
    } catch { setPages([]); } finally { setLoadingPages(false); }
  }, [selectedSite, period]);

  useEffect(() => { loadSeries(); loadAnnotations(); loadPages(); }, [loadSeries, loadAnnotations, loadPages]);

  // Отрисовка графика с маркерами-заметками
  useEffect(() => {
    if (!canvasRef.current || series.length === 0) return;
    const ctx = canvasRef.current.getContext('2d');
    if (!ctx) return;
    if (chartRef.current) chartRef.current.destroy();

    const labels = series.map(p => p.date);
    // заметки по всему сайту (url == null) для этого графика
    const siteAnns = annotations.filter(a => !a.url);

    const annotationPlugin = {
      id: 'annMarkers',
      afterDraw(chart: any) {
        const { ctx, chartArea, scales } = chart;
        siteAnns.forEach((a) => {
          const idx = labels.indexOf(a.date);
          if (idx < 0) return;
          const x = scales.x.getPixelForValue(idx);
          const color = (CATEGORIES[a.category] || CATEGORIES.note).color;
          ctx.save();
          ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.setLineDash([4, 3]);
          ctx.beginPath(); ctx.moveTo(x, chartArea.top); ctx.lineTo(x, chartArea.bottom); ctx.stroke();
          ctx.setLineDash([]);
          ctx.fillStyle = color;
          ctx.beginPath(); ctx.arc(x, chartArea.top + 4, 4, 0, Math.PI * 2); ctx.fill();
          ctx.restore();
        });
      },
    };

    chartRef.current = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Клики', data: series.map(p => p.clicks),
          borderColor: '#2563EB', backgroundColor: '#2563EB20', fill: true, tension: 0.3, pointRadius: 2,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              afterBody: (items: any) => {
                const date = labels[items[0].dataIndex];
                const anns = siteAnns.filter(a => a.date === date);
                return anns.map(a => `📌 ${(CATEGORIES[a.category] || CATEGORIES.note).label}: ${a.text}`);
              },
            },
          },
        },
        scales: { y: { beginAtZero: true } },
      },
      plugins: [annotationPlugin],
    });

    return () => { if (chartRef.current) { chartRef.current.destroy(); chartRef.current = null; } };
  }, [series, annotations]);

  const addAnnotation = async () => {
    if (!annDate || !annText.trim()) return;
    setSavingAnn(true);
    try {
      await fetch(`${API_BASE}/api/annotations`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ siteUrl: selectedSite, date: annDate, text: annText.trim(), category: annCat, url: annUrl.trim() || undefined }),
      });
      setAnnText(''); setAnnUrl('');
      await loadAnnotations();
    } catch { /* ignore */ } finally { setSavingAnn(false); }
  };

  const deleteAnnotation = async (id: number) => {
    try {
      await fetch(`${API_BASE}/api/annotations/delete`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }),
      });
      await loadAnnotations();
    } catch { /* ignore */ }
  };

  const openDetails = async (url: string) => {
    setDetailsLoading(true); setDetails(null);
    try {
      const r = await fetch(`${API_BASE}/api/page/details?siteUrl=${encodeURIComponent(selectedSite)}&url=${encodeURIComponent(url)}&period=${period}`);
      setDetails(await r.json());
    } catch { /* ignore */ } finally { setDetailsLoading(false); }
  };

  const filteredPages = useMemo(
    () => pages.filter(p => p.url.toLowerCase().includes(search.toLowerCase())),
    [pages, search]
  );

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Страницы</h1>
            <p className="text-gray-600 mt-1">Статистика по страницам, динамика и заметки на графике</p>
          </div>
          <div className="flex items-center gap-3">
            <select value={selectedSite} onChange={(e) => setSelectedSite(e.target.value)}
              className="px-3 py-2 border border-gray-300 rounded-lg bg-white max-w-xs">
              {sites.map(s => <option key={s} value={s}>{s.replace(/^https?:\/\//, '').replace(/^sc-domain:/, '')}</option>)}
            </select>
            <select value={period} onChange={(e) => setPeriod(Number(e.target.value))}
              className="px-3 py-2 border border-gray-300 rounded-lg bg-white">
              {PERIODS.map(p => <option key={p.value} value={p.value}>{p.label}</option>)}
            </select>
          </div>
        </div>

        {/* График с заметками */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-3">Динамика кликов сайта</h2>
          <div className="h-72">
            {series.length ? <canvas ref={canvasRef} /> :
              <div className="h-full flex items-center justify-center text-gray-400">Нет данных за период</div>}
          </div>

          {/* Форма заметки */}
          <div className="mt-4 border-t border-gray-100 pt-4">
            <div className="flex flex-wrap items-end gap-2">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Дата</label>
                <input type="date" value={annDate} onChange={(e) => setAnnDate(e.target.value)}
                  className="px-3 py-2 border border-gray-300 rounded-lg" />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Тип</label>
                <select value={annCat} onChange={(e) => setAnnCat(e.target.value)}
                  className="px-3 py-2 border border-gray-300 rounded-lg bg-white">
                  {Object.entries(CATEGORIES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                </select>
              </div>
              <div className="flex-1 min-w-[200px]">
                <label className="block text-xs text-gray-500 mb-1">Текст</label>
                <input type="text" value={annText} onChange={(e) => setAnnText(e.target.value)}
                  placeholder="Что произошло (напр. проставлен беклинк с site.com)"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg" />
              </div>
              <div className="flex-1 min-w-[180px]">
                <label className="block text-xs text-gray-500 mb-1">URL (необязательно)</label>
                <input type="text" value={annUrl} onChange={(e) => setAnnUrl(e.target.value)}
                  placeholder="только для конкретной страницы"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg" />
              </div>
              <button onClick={addAnnotation} disabled={savingAnn || !annDate || !annText.trim()}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 flex items-center gap-2">
                <FontAwesomeIcon icon={savingAnn ? faSpinner : faPlus} className={savingAnn ? 'animate-spin' : ''} />
                <span>Добавить</span>
              </button>
            </div>

            {/* Список заметок */}
            {annotations.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {annotations.map(a => {
                  const c = CATEGORIES[a.category] || CATEGORIES.note;
                  return (
                    <span key={a.id} className="inline-flex items-center gap-1.5 text-xs rounded-full px-2.5 py-1 border"
                      style={{ borderColor: c.color, color: c.color }}>
                      <FontAwesomeIcon icon={c.icon} />
                      <span className="text-gray-700">{a.date}</span>
                      <span className="text-gray-600">· {a.text}</span>
                      {a.url && <span className="text-gray-400">(URL)</span>}
                      <button onClick={() => deleteAnnotation(a.id)} className="text-gray-400 hover:text-red-600 ml-1">
                        <FontAwesomeIcon icon={faTimes} />
                      </button>
                    </span>
                  );
                })}
              </div>
            )}
          </div>
        </div>

        {/* Таблица страниц */}
        <div className="mb-3">
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Поиск по URL…" className="w-full md:w-96 px-4 py-2 border border-gray-300 rounded-lg" />
        </div>
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
          {loadingPages && pages.length === 0 ? (
            <div className="p-8 text-center text-gray-500"><FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl text-blue-600" /></div>
          ) : filteredPages.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Нет данных по страницам</div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Страница</th>
                  <th className="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase">Клики</th>
                  <th className="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase">Показы</th>
                  <th className="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase">CTR</th>
                  <th className="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase">Позиция</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {filteredPages.map(p => (
                  <tr key={p.url} className="hover:bg-blue-50 cursor-pointer" onClick={() => openDetails(p.url)}>
                    <td className="px-3 py-2 text-sm text-blue-700 max-w-md truncate" title={p.url}>
                      {p.url.replace(/^https?:\/\/[^/]+/, '') || '/'}
                    </td>
                    <td className="px-3 py-2 text-right"><div className="text-sm font-medium">{fmt(p.clicks)}</div><DeltaPct cur={p.clicks} prev={p.prev_clicks} /></td>
                    <td className="px-3 py-2 text-right"><div className="text-sm">{fmt(p.impressions)}</div><DeltaPct cur={p.impressions} prev={p.prev_impressions} /></td>
                    <td className="px-3 py-2 text-right"><div className="text-sm">{(p.ctr * 100).toFixed(2)}%</div><DeltaPct cur={p.ctr} prev={p.prev_ctr} /></td>
                    <td className="px-3 py-2 text-right"><div className="text-sm">{p.position.toFixed(1)}</div><DeltaPos cur={p.position} prev={p.prev_position} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {/* Details drawer */}
      {(details || detailsLoading) && (
        <div className="fixed inset-0 bg-black bg-opacity-40 flex justify-end z-50" onClick={() => setDetails(null)}>
          <div className="bg-white w-full max-w-2xl h-full overflow-y-auto p-6" onClick={(e) => e.stopPropagation()}>
            <div className="flex justify-between items-start mb-4">
              <h2 className="text-lg font-semibold text-gray-900 break-all pr-4">
                {details ? (details.url.replace(/^https?:\/\/[^/]+/, '') || '/') : 'Загрузка…'}
              </h2>
              <button onClick={() => setDetails(null)} className="text-gray-500 hover:text-gray-700"><FontAwesomeIcon icon={faTimes} /></button>
            </div>
            {detailsLoading ? (
              <div className="py-12 text-center text-gray-400"><FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl" /></div>
            ) : details ? (
              <>
                <h3 className="text-sm font-semibold text-gray-700 mb-2">Динамика ({details.timeseries.length} дн.)</h3>
                <div className="text-xs text-gray-500 mb-4">
                  Всего кликов: {fmt(details.timeseries.reduce((s, d) => s + d.clicks, 0))} ·
                  показов: {fmt(details.timeseries.reduce((s, d) => s + d.impressions, 0))}
                </div>
                <h3 className="text-sm font-semibold text-gray-700 mb-2">Топ-запросы</h3>
                <table className="w-full text-sm">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-2 py-1 text-left text-xs text-gray-600">Запрос</th>
                      <th className="px-2 py-1 text-right text-xs text-gray-600">Клики</th>
                      <th className="px-2 py-1 text-right text-xs text-gray-600">Показы</th>
                      <th className="px-2 py-1 text-right text-xs text-gray-600">Поз.</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {details.queries.map((q, i) => (
                      <tr key={i}>
                        <td className="px-2 py-1 max-w-xs truncate" title={q.query}>{q.query}</td>
                        <td className="px-2 py-1 text-right">{q.clicks}</td>
                        <td className="px-2 py-1 text-right">{fmt(q.impressions)}</td>
                        <td className="px-2 py-1 text-right">{q.position.toFixed(1)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}
