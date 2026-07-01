"use client";

import { useState } from "react";
import { StarIcon } from "./icons";

export function Stars({
  value,
  onChange,
  size = 14,
}: {
  value: number;
  onChange?: (v: number) => void;
  size?: number;
}) {
  const [hover, setHover] = useState(0);
  const shown = hover || value;
  return (
    <div className="flex items-center gap-0.5" onMouseLeave={() => setHover(0)}>
      {[1, 2, 3, 4, 5].map((n) => (
        <button
          key={n}
          type="button"
          disabled={!onChange}
          onMouseEnter={() => onChange && setHover(n)}
          onClick={() => onChange?.(value === n ? 0 : n)}
          className={`transition-colors ${onChange ? "cursor-pointer" : "cursor-default"} ${
            n <= shown ? "text-amber-400" : "text-fg-faint hover:text-fg-muted"
          }`}
          aria-label={`${n} star${n > 1 ? "s" : ""}`}
        >
          <StarIcon
            width={size}
            height={size}
            fill={n <= shown ? "currentColor" : "none"}
          />
        </button>
      ))}
    </div>
  );
}
