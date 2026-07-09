'use client';

import { ReactNode, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCircleQuestion, faXmark } from '@fortawesome/free-solid-svg-icons';

interface HelpButtonProps {
  title: string;
  children: ReactNode;
  /** Компактная кнопка (только иконка) — для плотных шапок. */
  compact?: boolean;
  className?: string;
}

/**
 * Кнопка «Справка» + модалка с описанием раздела. Единый вид на всех страницах.
 * Текст справки передаётся как children (обычный JSX).
 */
export default function HelpButton({ title, children, compact = false, className = '' }: HelpButtonProps) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={`flex-shrink-0 inline-flex items-center gap-2 px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 ${className}`}
        title="Справка по разделу"
      >
        <FontAwesomeIcon icon={faCircleQuestion} className="text-blue-600" />
        {!compact && <span>Справка</span>}
      </button>

      {open && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
          onClick={() => setOpen(false)}
        >
          <div
            className="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[85vh] overflow-y-auto"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 sticky top-0 bg-white">
              <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <FontAwesomeIcon icon={faCircleQuestion} className="text-blue-600" /> {title}
              </h2>
              <button onClick={() => setOpen(false)} className="text-gray-400 hover:text-gray-700" aria-label="Закрыть">
                <FontAwesomeIcon icon={faXmark} className="text-xl" />
              </button>
            </div>
            <div className="px-6 py-4 space-y-4 text-sm text-gray-700 leading-relaxed">
              {children}
            </div>
            <div className="px-6 py-4 border-t border-gray-200 text-right sticky bottom-0 bg-white">
              <button onClick={() => setOpen(false)} className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                Понятно
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
