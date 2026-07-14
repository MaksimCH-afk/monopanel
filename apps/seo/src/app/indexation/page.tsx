'use client';

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { API_BASE } from '@/lib/api';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSpinner, faTrash, faCheckCircle, faPaperPlane, faSitemap, faSearch, faWallet, faRotate, faPlus } from '@fortawesome/free-solid-svg-icons';
import SiteSelect from '@/components/ui/SiteSelect';
import HelpButton from '@/components/ui/HelpButton';

interface IdxPage {
  id: number;
  url: string;
  coverage_state: string | null;
  verdict: string | null;
  last_crawl_time: string | null;
  index_status: string | null;
  index_count: number | null;
  submitted: boolean;
  submitted_at: string | null;
  last_checked: string | null;
}
interface Job { running: boolean; kind: string | null; done: number; total: number; error: string | null; }

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

export default function IndexationPage() {
  const [sites, setSites] = useState<string[]>([]);
  const [selectedSite, setSelectedSite] = useState('');
  const [sitemapUrl, setSitemapUrl] = useState('');
  const [pages, setPages] = useState<IdxPage[]>([]);
  const [job, setJob] = useState<Job | null>(null);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  // Баланс XMLRIVER
  const [balance, setBalance] = useState<{ loading: boolean; ok?: boolean; value?: number | null; raw?: string; error?: string }>({ loading: true });
  // Ручная вставка URL
  const [manualUrls, setManualUrls] = useState('');
  const [addingUrls, setAddingUrls] = useState(false);
  const [addMsg, setAddMsg] = useState<string | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const loadBalance = useCallback(async () => {
    setBalance({ loading: true });
    try {
      const r = await fetch(`${API_BASE}/api/index/xmlriver-balance`);
      const d = await r.json();
      setBalance({ loading: false, ok: !!d.ok, value: d.balance ?? null, raw: d.raw, error: d.error });
    } catch {
      setBalance({ loading: false, ok: false, error: 'Не удалось получить баланс' });
    }
  }, []);

  useEffect(() => {
    fetch(`${API_BASE}/api/sites`).then(r => r.json()).then(d => {
      const s = Array.isArray(d.sites) ? d.sites : [];
      setSites(s);
      if (s.length && !selectedSite) setSelectedSite(s[0]);
    }).catch(() => {});
    loadBalance();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const load = useCallback(async () => {
    if (!selectedSite) return;
    setLoading(true);
    try {
      const r = await fetch(`${API_BASE}/api/index/pages?siteUrl=${encodeURIComponent(selectedSite)}`);
      const d = await r.json();
      setPages(Array.isArray(d.pages) ? d.pages : []);
      setJob(d.job || null);
    } catch { /* ignore */ } finally { setLoading(false); }
  }, [selectedSite]);

  useEffect(() => { setSelected(new Set()); load(); }, [load]);

  useEffect(() => {
    if (job?.running && !pollRef.current) {
      pollRef.current = setInterval(async () => {
        try {
          const r = await fetch(`${API_BASE}/api/index/status`);
          const st: Job = await r.json();
          setJob(st);
          if (!st.running) { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } load(); loadBalance(); }
        } catch { /* ignore */ }
      }, 2000);
    }
    return () => { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } };
  }, [job?.running, load, loadBalance]);

  const post = async (path: string, body: any) => {
    const r = await fetch(`${API_BASE}/api/index/${path}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    const d = await r.json();
    if (d.error) { alert(d.error); return; }
    setJob(d.job || { running: true, kind: path, done: 0, total: 0, error: null });
  };

  const crawl = () => post('crawl', { siteUrl: selectedSite, sitemapUrl: sitemapUrl.trim() || undefined });

  // Ручная вставка списка URL в таблицу (для проверки/отправки без sitemap)
  const addUrls = async () => {
    if (!selectedSite || !manualUrls.trim()) return;
    setAddingUrls(true);
    setAddMsg(null);
    try {
      const r = await fetch(`${API_BASE}/api/index/add-urls`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ siteUrl: selectedSite, urls: manualUrls }),
      });
      const d = await r.json();
      if (d.error) { setAddMsg(d.error); return; }
      setAddMsg(`Добавлено: ${d.added}` +
        (d.duplicates ? `, уже были: ${d.duplicates}` : '') +
        (d.skipped ? `, пропущено (не http): ${d.skipped}` : ''));
      setManualUrls('');
      load();
    } catch {
      setAddMsg('Не удалось добавить URL');
    } finally {
      setAddingUrls(false);
    }
  };

  const idsForAction = () => (selected.size ? Array.from(selected) : undefined);
  const runAction = (path: string) => post(path, { siteUrl: selectedSite, ids: idsForAction() });

  const deleteSelected = async () => {
    if (!selected.size) return;
    if (!confirm(`Удалить ${selected.size} страниц(ы) из списка?`)) return;
    await fetch(`${API_BASE}/api/index/delete`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: Array.from(selected) }),
    });
    setSelected(new Set()); load();
  };

  const filtered = useMemo(
    () => pages.filter(p => p.url.toLowerCase().includes(search.toLowerCase())),
    [pages, search]
  );
  const allSelected = filtered.length > 0 && filtered.every(p => selected.has(p.id));
  const toggleAll = () => setSelected(prev => {
    const n = new Set(prev);
    if (allSelected) filtered.forEach(p => n.delete(p.id)); else filtered.forEach(p => n.add(p.id));
    return n;
  });
  const toggleOne = (id: number) => setSelected(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n; });

  const progressPct = job && job.total ? Math.round((job.done / job.total) * 100) : 0;
  const jobLabel: Record<string, string> = {
    crawl: 'Обход sitemap', inspect: 'Google URL Inspection', xmlriver: 'Проверка XMLRIVER', submit: 'Отправка в 2index',
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto">
        <div className="mb-6 flex items-start justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Индексация</h1>
            <p className="text-gray-600 mt-1">Обход sitemap, статус индексации (Google/XMLRIVER), массовая отправка на индекс</p>
            <div className="mt-2 inline-flex items-center gap-2 text-sm bg-white border border-gray-200 rounded-lg px-3 py-1.5">
              <FontAwesomeIcon icon={faWallet} className="text-green-600" />
              <span className="text-gray-500">Баланс XMLRIVER:</span>
              {balance.loading ? (
                <FontAwesomeIcon icon={faSpinner} className="animate-spin text-gray-400" />
              ) : balance.ok ? (
                <span className="font-semibold text-gray-900">{balance.value != null ? balance.value.toLocaleString('ru-RU') : balance.raw} ₽</span>
              ) : (
                <span className="text-red-500 text-xs" title={balance.error}>{balance.error || 'нет данных'}</span>
              )}
              <button type="button" onClick={loadBalance} className="text-gray-400 hover:text-gray-700" title="Обновить баланс">
                <FontAwesomeIcon icon={faRotate} className={balance.loading ? 'animate-spin' : ''} />
              </button>
            </div>
          </div>
          <HelpButton title="Что такое «Индексация»">
            <p>
              «Индексация» — это про то, <strong>попали ли страницы вашего сайта в поиск Google</strong>.
              Если страница не в индексе, по ней не идёт трафик из поиска. Раздел помогает собрать список
              страниц, проверить их статус и отправить недостающие на переобход.
            </p>
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600">
              Не путать с разделом <strong>«Карты сайта»</strong>: там вы <em>управляете</em> самими sitemap-файлами
              в Google (добавить/удалить/статус). Здесь sitemap используется только как <em>источник списка
              страниц</em> для проверки индексации — карту в Google этот раздел не меняет.
            </div>
            <div>
              <p className="font-semibold text-gray-900 mb-1">Как пользоваться — по шагам:</p>
              <ol className="list-decimal list-inside space-y-1.5">
                <li><strong>Выберите сайт</strong> в списке вверху.</li>
                <li>
                  Заполните список страниц одним из двух способов: <strong>«Обойти sitemap»</strong> (программа
                  откроет карту сайта <code>sitemap.xml</code> и соберёт все страницы) <strong>или вставьте свои
                  URL</strong> в поле «Вставить свои URL» (по одному в строке — удобно, когда нужно проверить/отправить
                  конкретные 100 ссылок).
                </li>
                <li>
                  <strong>«Статус в Google»</strong> — по каждой странице спрашивает у Google (через официальный
                  URL Inspection API), в индексе ли она; результат появится в колонках «Google (покрытие)» и «Индекс».
                  Работает <strong>только для страниц выбранного сайта-свойства</strong> (для ссылок с других доменов
                  вернёт «error» — для них используйте «XMLRIVER»). У Google есть суточный лимит (~2000 URL).
                </li>
                <li>
                  <strong>«XMLRIVER»</strong> — альтернативная проверка индексации в Google через сторонний
                  сервис XMLRIVER (нужны user ID и key в Настройках). Каждая проверка тратит баланс XMLRIVER —
                  его текущее значение показано вверху раздела (обновляется кнопкой ↻ и после проверки).
                </li>
                <li>
                  <strong>«На индекс (2index)»</strong> — отправляет страницы на переобход/индексацию через
                  сервис 2index (нужен ключ 2index в Настройках). Это и есть «попросить Google заглянуть заново».
                </li>
                <li>
                  <strong>«Удалить»</strong> — убирает выбранные строки из этого списка (на сам сайт и на Google не влияет).
                </li>
              </ol>
            </div>
            <div className="bg-blue-50 border border-blue-100 rounded-lg p-3">
              <p className="text-blue-900">
                <strong>Про галочки:</strong> отметьте нужные строки — действие применится только к ним.
                Если ничего не отмечено — действие идёт по <strong>всем</strong> строкам в списке.
                <br /><strong>«Поиск по URL»</strong> — это просто фильтр таблицы: он прячет строки, не содержащие
                введённый текст (по сайту/Google ничего не запрашивает), удобно найти нужные страницы в длинном списке.
              </p>
            </div>
            <p className="text-gray-500 text-xs">
              Проверка и отправка идут в фоне — виден прогресс-бар. «Статус в Google» и «XMLRIVER» только
              смотрят состояние и ничего не меняют; «На индекс» — единственное действие, которое реально
              отправляет страницы на переобход.
            </p>
          </HelpButton>
        </div>

        {/* Обход sitemap */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-[260px]">
              <label className="block text-xs text-gray-500 mb-1">Сайт</label>
              <SiteSelect sites={sites} value={selectedSite} onChange={setSelectedSite} />
            </div>
            <div className="flex-1 min-w-[240px]">
              <label className="block text-xs text-gray-500 mb-1">URL sitemap (необязательно — по умолчанию /sitemap.xml)</label>
              <input type="text" value={sitemapUrl} onChange={(e) => setSitemapUrl(e.target.value)}
                placeholder="https://site.com/sitemap.xml"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg" />
            </div>
            <button onClick={crawl} disabled={job?.running || !selectedSite}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 flex items-center gap-2">
              <FontAwesomeIcon icon={faSitemap} /> Обойти sitemap
            </button>
          </div>
          <p className="text-xs text-gray-400 mt-2">Список страниц можно заполнить двумя способами: обойти sitemap выше — или вставить свои URL ниже.</p>
        </div>

        {/* Добавить свои URL вручную */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
          <label htmlFor="manual-urls" className="block text-sm font-medium text-gray-800 mb-1">
            Вставить свои URL (по одному в строке; можно без https:// — добавим сами)
          </label>
          <p className="text-xs text-gray-500 mb-2">
            Ссылки добавятся в список ниже под выбранным сайтом <strong>{selectedSite ? selectedSite.replace(/^https?:\/\//, '').replace(/^sc-domain:/, '') : '—'}</strong>.
            Затем выделите нужные строки (или ничего — тогда действие применится ко всем) и запускайте операцию.
          </p>
          <p className="text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded p-2 mb-2">
            <strong>Важно про чужие домены:</strong> «XMLRIVER» и «На индекс (2index)» работают для <strong>любых</strong> URL.
            А «Статус в Google» — только для страниц <strong>того же свойства</strong>, что выбрано в «Сайт»
            (это ограничение Google API); для ссылок с других доменов он вернёт «error». Для проверки чужих
            доменов используйте «XMLRIVER».
          </p>
          <textarea
            id="manual-urls"
            value={manualUrls}
            onChange={(e) => setManualUrls(e.target.value)}
            rows={5}
            placeholder={'https://site.com/page-1\nhttps://site.com/page-2\nhttps://site.com/page-3'}
            className="w-full px-3 py-2 border border-gray-300 rounded-lg font-mono text-xs focus:ring-2 focus:ring-blue-500"
          />
          <div className="flex items-center gap-3 mt-2">
            <button
              onClick={addUrls}
              disabled={addingUrls || !selectedSite || !manualUrls.trim()}
              className="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 disabled:opacity-50 flex items-center gap-2"
            >
              <FontAwesomeIcon icon={addingUrls ? faSpinner : faPlus} className={addingUrls ? 'animate-spin' : ''} />
              Добавить в список
            </button>
            {addMsg && <span className="text-sm text-gray-600">{addMsg}</span>}
          </div>
        </div>

        {/* Прогресс */}
        {job?.running && (
          <div className="mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div className="flex justify-between text-sm text-blue-800 mb-2">
              <span>{jobLabel[job.kind || ''] || 'Операция'}…</span>
              <span>{job.total ? `${job.done}/${job.total}` : '…'}</span>
            </div>
            <div className="w-full bg-blue-100 rounded-full h-2">
              <div className="bg-blue-600 h-2 rounded-full transition-all" style={{ width: `${progressPct}%` }} />
            </div>
          </div>
        )}
        {job?.error && !job.running && (
          <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-3 text-red-800 text-sm">Ошибка: {job.error}</div>
        )}

        {/* Действия */}
        <div className="flex flex-wrap items-center gap-2 mb-2">
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Поиск по URL…" className="px-4 py-2 border border-gray-300 rounded-lg flex-1 min-w-[180px]" />
          <span className="text-sm text-gray-500">{selected.size ? `Выбрано: ${selected.size}` : `Всего: ${pages.length}`}</span>
          <button onClick={() => runAction('inspect')} disabled={job?.running}
            className="px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg hover:bg-gray-200 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faSearch} /> Статус в Google
          </button>
          <button onClick={() => runAction('xmlriver')} disabled={job?.running}
            className="px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg hover:bg-gray-200 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faSearch} /> XMLRIVER
          </button>
          <button onClick={() => runAction('submit')} disabled={job?.running}
            className="px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faPaperPlane} /> На индекс (2index)
          </button>
          <button onClick={deleteSelected} disabled={!selected.size}
            className="px-3 py-2 bg-red-50 text-red-600 border border-red-200 rounded-lg hover:bg-red-100 disabled:opacity-50 text-sm flex items-center gap-2">
            <FontAwesomeIcon icon={faTrash} /> Удалить
          </button>
        </div>
        <p className="text-xs text-gray-400 mb-3">Операции применяются к выбранным строкам; если ничего не выбрано — ко всем страницам сайта.</p>

        {/* Таблица */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
          {loading && pages.length === 0 ? (
            <div className="p-8 text-center text-gray-500"><FontAwesomeIcon icon={faSpinner} className="animate-spin text-2xl text-blue-600" /></div>
          ) : filtered.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Страниц нет. Нажмите «Обойти sitemap» или вставьте свои URL выше.</div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-3 py-3 w-8"><input type="checkbox" checked={allSelected} onChange={toggleAll} /></th>
                  <th className="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase">URL</th>
                  <th className="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Google (покрытие)</th>
                  <th className="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">Индекс</th>
                  <th className="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">2index</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {filtered.map(p => (
                  <tr key={p.id} className="hover:bg-gray-50">
                    <td className="px-3 py-2"><input type="checkbox" checked={selected.has(p.id)} onChange={() => toggleOne(p.id)} /></td>
                    <td className="px-3 py-2 max-w-md truncate">
                      <a href={p.url} target="_blank" rel="noopener noreferrer" className="text-blue-700 hover:underline" title={p.url}>
                        {p.url.replace(/^https?:\/\/[^/]+/, '') || '/'}
                      </a>
                    </td>
                    <td className="px-3 py-2 text-xs text-gray-600 max-w-[220px] truncate" title={p.coverage_state || ''}>
                      {p.coverage_state || '—'}
                    </td>
                    <td className="px-3 py-2 text-center"><IndexBadge status={p.index_status} /></td>
                    <td className="px-3 py-2 text-center">
                      {p.submitted ? <FontAwesomeIcon icon={faCheckCircle} className="text-green-600" title={p.submitted_at || ''} />
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
