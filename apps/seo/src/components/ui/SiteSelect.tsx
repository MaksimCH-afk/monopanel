'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faMagnifyingGlass, faChevronDown, faCheck } from '@fortawesome/free-solid-svg-icons';

// Человекочитаемая подпись свойства GSC. Domain-property показываем как «Домен: …»,
// URL-prefix — полным адресом с протоколом (важно: http:// и https:// — разные
// свойства, и их нельзя путать).
export function siteLabel(site: string): string {
  if (!site) return '';
  return site.startsWith('sc-domain:') ? `Домен: ${site.slice('sc-domain:'.length)}` : site;
}

interface SiteSelectProps {
  sites: string[];
  value: string;
  onChange: (site: string) => void;
  placeholder?: string;
  className?: string;
  id?: string;
}

/**
 * Выпадающий список сайтов с поиском. Заменяет обычный <select>, который при
 * 400 свойствах листать неудобно. Клик открывает панель с полем поиска и
 * прокручиваемым отфильтрованным списком.
 */
export default function SiteSelect({ sites, value, onChange, placeholder = 'Выберите сайт…', className = '', id }: SiteSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Закрытие по клику вне компонента и по Escape.
  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    // Фокус в поиск при открытии.
    setTimeout(() => inputRef.current?.focus(), 0);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return sites;
    return sites.filter((s) => s.toLowerCase().includes(q));
  }, [sites, query]);

  const select = (site: string) => {
    onChange(site);
    setOpen(false);
    setQuery('');
  };

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      <button
        type="button"
        id={id}
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center justify-between gap-2 p-2 border border-gray-300 rounded-md bg-white text-left focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
      >
        <span className={`truncate ${value ? 'text-gray-900' : 'text-gray-400'}`}>
          {value ? siteLabel(value) : placeholder}
        </span>
        <FontAwesomeIcon icon={faChevronDown} className="text-gray-400 flex-shrink-0" />
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg">
          <div className="p-2 border-b border-gray-100">
            <div className="relative">
              <FontAwesomeIcon icon={faMagnifyingGlass} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm" />
              <input
                ref={inputRef}
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Поиск по домену…"
                className="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
            </div>
          </div>
          <ul className="max-h-72 overflow-y-auto py-1">
            {filtered.length === 0 && (
              <li className="px-3 py-2 text-sm text-gray-500">Ничего не найдено</li>
            )}
            {filtered.slice(0, 300).map((site) => (
              <li key={site}>
                <button
                  type="button"
                  onClick={() => select(site)}
                  className={`w-full flex items-center gap-2 px-3 py-2 text-sm text-left hover:bg-blue-50 ${site === value ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-800'}`}
                >
                  <FontAwesomeIcon icon={faCheck} className={`text-xs ${site === value ? 'text-blue-600' : 'text-transparent'}`} />
                  <span className="truncate">{siteLabel(site)}</span>
                </button>
              </li>
            ))}
            {filtered.length > 300 && (
              <li className="px-3 py-2 text-xs text-gray-400">Показаны первые 300 — уточните поиск</li>
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
