"use client";

import { useEffect } from "react";
import { ResultDto } from "@/lib/types";
import { CloseIcon } from "./icons";

export function Lightbox({
  result,
  blind,
  onClose,
}: {
  result: ResultDto | null;
  blind: boolean;
  onClose: () => void;
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  if (!result || !result.imageUrl) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-8"
      onClick={onClose}
    >
      <button
        className="absolute right-5 top-5 rounded p-2 text-fg-muted hover:bg-bg-hover hover:text-fg"
        onClick={onClose}
      >
        <CloseIcon width={20} height={20} />
      </button>
      <div
        className="flex max-h-full max-w-5xl flex-col gap-3"
        onClick={(e) => e.stopPropagation()}
      >
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={result.imageUrl}
          alt={blind ? "result" : result.modelName}
          className="max-h-[80vh] rounded-md object-contain"
        />
        <div className="mono flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-fg-muted">
          <span className="flex items-center gap-2">
            <span
              className="inline-block h-2.5 w-2.5 rounded-full"
              style={{ background: blind ? "#3a3a3a" : result.modelColor }}
            />
            {blind ? "model ??" : result.modelName}
          </span>
          {result.seed != null && <span>seed {result.seed}</span>}
          {result.latencyMs != null && (
            <span>{(result.latencyMs / 1000).toFixed(1)}s</span>
          )}
        </div>
      </div>
    </div>
  );
}
