'use client';

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { API_BASE } from '@/lib/api';
import HelpButton from '@/components/ui/HelpButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSpinner, faPlus, faTrash, faCheckCircle, faTimesCircle, faQuestionCircle, faSearch, faPaperPlane, faLink } from '@fortawesome/free-solid-svg-icons';

interface Backlink {
  id: number;
  site_url: string | null;
  source_url: string;
  target_url: string;
  http_status: number | null;
  link_present: boolean | null;
  index_status: string | null;
  index_count: number | null;
  submitted: boolean;
  submitted_at: string | null;
  last_checked: string | null;
}

interface Job {
  running: boolean;
  kind: string | null;
  done: number;
  total: number;
  error: string | null;
}

function HttpBadge({ status }: { status: number | null }) {
  if (status === null || status === undefined) return <span className="text-gray-400 text-xs">—</span>;
  if (status === 0) return <span className="text-red-600 text-xs font-medium">нет ответа</span>;
  const ok = status >= 200 && status < 300;
  const color = ok ? 'text-green-600' : status === 404 ? 'text-red-600' : 'text-orange-600';
  return <span className={`text-xs font-medium ${color}`}>{status}</span>;
}

function PresenceBadge({ present }: { present: boolean | null }) {
  if (present === null || present === undefined) return <FontAwesomeIcon icon={faQuestionCircle} className="text-gray-300" />;
  return present
    ? <FontAwesomeIcon icon={faCheckCircle} className="text-green-600" title="Ссылка найдена" />
    : <FontAwesomeIcon icon={faTimesCircle} className="text-red-600" title="Ссылка не найдена" />;
}

function IndexBadge({ status }: { status: string | null }) {
  if (!status) return <span className="text-gray-400 text-xs">—</span>;
  const map: Record<string, { label: string; color: string }> = {
    indexed: { label: 'в индексе', color: 'text-green-600' },
    not_indexed: { label: 'нет в индексе', color: 'text-red-600' },
    unknown: { label: 'неизвестно', color: 'text-gray-500' },
    error: { label: 'ошибка', color: 'text-orange-600' },
  };
  const m = map[status] || { label: status, color: 'text-gray-500' };
  return <span className={`text-xs font-medium ${m.color}`}>{m.label}</span>;
}

export default function BacklinksPage() {
  const [backlinks, setBacklinks] = useState<Backlink[]>([]);
  const [job, setJob] = useState<Job | null>(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [search, setSearch] = useState('');

  // форма добавления (bulk)
  const [bulkSource, setBulkSource] = useState('');
  const [bulkTarget, setBulkTarget] = useState('');
  const [adding, setAdding] = useState(false);

  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await fetch(`${API_BASE}/api/backlinks`);
      const d = await r.json();
      setBacklinks(Array.isArray(d.backlinks) ? d.backlinks : []);
      setJob(d.job || null);
    } catch { /* ignore */ } finally { setLoading(false); }
  }, []);

  useEffect(() => { load(); }, [load]);

  // поллинг статуса фоновых операций
  useEffect(() => {
    if (job?.running && !pollRef.current) {
      pollRef.current = setInterval(async () => {
        try {
          const r = await fetch(`${API_BASE}/api/backlinks/status`);
          const st: Job = await r.json();
          setJob(st);
          if (!st.running) {
            if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
            load();
          }
        } catch { /* ignore */ }
      }, 2000);
    }
    return () => { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } };
  }, [job?.running, load]);

  const addBacklinks = async () => {
    const sources = bulkSource.split('\n').map(s => s.trim()).filter(Boolean);
    const target = bulkTarget.trim();
    if (!sources.length || !target) return;
    setAdding(true);
    try {
      const items = sources.map(source_url => ({ source_url, target_url: target }));
      const r = await fetch(`${API_BASE}/api/backlinks`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items }),
      });
      const d = await r.json();
      setBulkSource('');
      await load();
      if (d.skipped) console.log(`Пропущено дублей/пустых: ${d.skipped}`);
    } catch { /* ignore */ } finally { setAdding(false); }
  };

  const idsForAction = () => (selected.size ? Array.from(selected) : undefined);

  const runAction = async (path: string) => {
    try {
      const r = await fetch(`${API_BASE}/api/backlinks/${path}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: idsForAction() }),
      });
      const d = await r.json();
      if (d.error) { alert(d.error); return; }
      setJob(d.job || { running: true, kind: path, done: 0, total: 0, error: null });
    } catch { /* ignore */ }
  };

  const deleteSelected = async () => {
    if (!selected.size) return;
    if (!confirm(`Удалить ${selected.size} беклинк(ов)?`)) return;
    await fetch(`${API_BASE}/api/backlinks/delete`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: Array.from(selected) }),
    });
    setSelected(new Set());
    load();
  };

  const filtered = useMemo(
    () => backlinks.filter(b =>
      b.source_url.toLowerCase().includes(search.toLowerCase()) ||
      b.target_url.toLowerCase().includes(search.toLowerCase())),
    [backlinks, search]
  );

  const allSelected = filtered.length > 0 && filtered.every(b => selected.has(b.id));
  const toggleAll = () => {
    setSelected(prev => {
      const next = new Set(prev);
      if (allSelected) filtered.forEach(b => next.delete(b.id));
      else filtered.forEach(b => next.add(b.id));
      return next;
    });
  };
  const toggleOne = (id: number) => {
    setSelected(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n; });
  };

  const progressPct = job && job.total ? Math.round((job.done / job.total) * 100) : 0;
  const jobLabel: Record<string, string> = { check: 'Проверка 404/ссылок', index: 'Проверка индексации', submit: 'Отправка в 2index' };
  const selCount = selected.size;

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto">
        <div className="mb-6 flex items-start justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Беклинки</h1>
            <p className="text-gray-600 mt-1">Мониторинг: 404, наличие ссылки, индексация (XMLRIVER), отправка на индекс (2index)</p>
          </div>
          <HelpButton title="Что такое «Беклинки»">
            <p>
              Беклинк — это <strong>ссылка на ваш сайт с другой (донорской) страницы</strong>. Раздел следит, что с
              этими ссылками всё в порядке.
            </p>
            <ul className="list-disc list-inside space-y-1.5">
              <li><strong>Код ответа (404)</strong> — открывается ли страница-донор вообще (не удалили ли её).</li>
              <li><strong>Наличие ссылки</strong> — стоит ли ещё на донорской странице ссылка на вас.</li>
              <li><strong>Индексация (XMLRIVER)</strong> — в индексе ли Google страница-донор (иначе ссылка почти не работает).</li>
              <li><strong>На индекс (2index)</strong> — отправить донора на переиндексацию, чтобы Google учёл ссылку.</li>
            </ul>
            <p className="text-gray-500 text-xs">XMLRIVER и 2index требуют ключей в Настройках.</p>
          </HelpButton>
        </div>

        {/* Добавление */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
            <FontAwesomeIcon icon={faLink} className="text-gray-500" /> Добавить беклинки
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div className="md:col-span-2">
              <label className="block text-xs text-gray-500 mb-1">Страницы-доноры (по одной в строке)</label>
              <textarea value={bulkSource} onChange={(e) => setBulkSource(e.target.value)} rows={4}
                placeholder={"https://donor1.com/article\nhttps://donor2.com/post"}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm" />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Наш URL/домен (target)</label>
              <input type="text" value={bulkTarget} onChange={(e) => setBulkTarget(e.target.value)}
                placeholder="https://mysite.com/page или mysite.com"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg" />
              <button onClick={addBacklinks} disabled={adding || !bulkSource.trim() || !bulkTarget.trim()}
                className="mt-3 w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 flex items-center justify-center gap-2">
                <FontAwesomeIcon icon={adding ? faSpinner : faPlus} className={adding ? 'animate-spin' : ''} />
                <span>Добавить</span>
              </button>
            </div>
          </div>
        </div>

        {/* Прогресс фоновой операции */}
        {job?.running && (
          <div className="mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div className="flex justify-between text-sm text-blue-800 mb-2">
              <span>{jobLabel[job.kind || ''] || 'Операция'}…</span>
              <span>{job.done}/{job.total}</span>
            </div>
            <div className="w-full bg-blue-100 rounded-full h-2">
              <div className="bg-blue-600 h-2 rounded-full transition-all" style={{ width: `${progressPct}%` }} />
            </div>
          </div>
        )}
        {job?.error && !job.running && (
          <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-3 text-red-800 text-sm">Ошибка: {job.error}</div>
        )}

        {/* Панель действий */}
        <div className="flex flex-wrap items-center gap-2 mb-3">
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Поиск…" className="px-4 py-2 border border-gray-300 rounded-lg flex-1 min-w-[180px]" />
          <span className="text-sm text-gray-500">{selCount ? `Выбрано: ${selCount}` : `Всего: ${backlinks.length}`}</span>
          <button onClick={() => runAction('check')} disabled={job?.running}
            className="px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg hover:bg-gray-200 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faSearch} /> Проверить 404/ссылку
          </button>
          <button onClick={() => runAction('index-check')} disabled={job?.running}
            className="px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg hover:bg-gray-200 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faSearch} /> Индексация (XMLRIVER)
          </button>
          <button onClick={() => runAction('submit-index')} disabled={job?.running}
            className="px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faPaperPlane} /> В индекс (2index)
          </button>
          <button onClick={deleteSelected} disabled={!selCount}
            className="px-3 py-2 bg-red-50 text-red-600 border border-red-200 rounded-lg hover:bg-red-100 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faTrash} /> Удалить
          </button>
        </div>
        <p className="text-xs text-gray-400 mb-3">Операции применяются к выбранным строкам; если ничего не выбрано — ко всем.</p>

        {/* Таблица */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
          {loading && backlinks.length === 0 ? (
            <div className="p-8 text-center text-gray-500"><FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl text-blue-600" /></div>
          ) : filtered.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Беклинков нет. Добавьте выше.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-3 py-3 w-8"><input type="checkbox" checked={allSelected} onChange={toggleAll} /></th>
                  <th className="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Донор</th>
                  <th className="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Target</th>
                  <th className="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">HTTP</th>
                  <th className="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">Ссылка</th>
                  <th className="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">Индекс</th>
                  <th className="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">2index</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {filtered.map(b => (
                  <tr key={b.id} className="hover:bg-gray-50">
                    <td className="px-3 py-2"><input type="checkbox" checked={selected.has(b.id)} onChange={() => toggleOne(b.id)} /></td>
                    <td className="px-3 py-2 max-w-xs truncate">
                      <a href={b.source_url} target="_blank" rel="noopener noreferrer" className="text-blue-700 hover:underline" title={b.source_url}>
                        {b.source_url.replace(/^https?:\/\//, '')}
                      </a>
                    </td>
                    <td className="px-3 py-2 max-w-[200px] truncate text-gray-600" title={b.target_url}>
                      {b.target_url.replace(/^https?:\/\//, '')}
                    </td>
                    <td className="px-3 py-2 text-center"><HttpBadge status={b.http_status} /></td>
                    <td className="px-3 py-2 text-center"><PresenceBadge present={b.link_present} /></td>
                    <td className="px-3 py-2 text-center"><IndexBadge status={b.index_status} /></td>
                    <td className="px-3 py-2 text-center">
                      {b.submitted
                        ? <FontAwesomeIcon icon={faCheckCircle} className="text-green-600" title={b.submitted_at || ''} />
                        : <span className="text-gray-300">—</span>}
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
