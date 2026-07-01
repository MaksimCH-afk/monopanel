"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { APP_VERSION } from "@/lib/version";

const TABS = [
  { href: "/", label: "Generate" },
  { href: "/results", label: "Results" },
  { href: "/history", label: "History" },
  { href: "/analytics", label: "Analytics" },
  { href: "/logs", label: "Logs" },
  { href: "/settings", label: "Settings" },
];

export function Header() {
  const pathname = usePathname();
  const [activeCount, setActiveCount] = useState<number | null>(null);

  useEffect(() => {
    let alive = true;
    const load = () =>
      fetch("/api/models")
        .then((r) => r.json())
        .then((d) => {
          if (alive)
            setActiveCount(
              (d.models ?? []).filter((m: { enabled: boolean }) => m.enabled).length,
            );
        })
        .catch(() => {});
    load();
    const t = setInterval(load, 8000);
    return () => {
      alive = false;
      clearInterval(t);
    };
  }, [pathname]);

  return (
    <header className="flex h-14 shrink-0 items-center gap-8 border-b border-bg-border bg-bg px-5">
      <Link href="/" className="flex items-center gap-2">
        <span className="inline-block h-3 w-3 rounded-sm bg-action" />
        <span className="mono text-sm font-medium tracking-tight text-fg">
          imagegen<span className="text-fg-faint">/</span>dashboard
        </span>
      </Link>

      <nav className="flex items-center gap-1">
        {TABS.map((tab) => {
          const active =
            tab.href === "/"
              ? pathname === "/"
              : pathname.startsWith(tab.href);
          return (
            <Link
              key={tab.href}
              href={tab.href}
              className={`mono rounded px-3 py-1.5 text-xs uppercase tracking-wider transition-colors ${
                active
                  ? "bg-bg-hover text-fg"
                  : "text-fg-muted hover:text-fg"
              }`}
            >
              {tab.label}
            </Link>
          );
        })}
      </nav>

      <div className="ml-auto flex items-center gap-4">
        <div className="flex items-center gap-2">
          <span
            className={`inline-block h-2 w-2 rounded-full ${
              activeCount ? "animate-pulse-dot bg-action" : "bg-fg-faint"
            }`}
          />
          <span className="mono text-xs text-fg-muted">
            {activeCount ?? "–"} active
          </span>
        </div>
        <span
          className="mono rounded border border-bg-border px-1.5 py-0.5 text-[10px] text-fg-faint"
          title="App version (bumped on each deploy)"
        >
          v{APP_VERSION}
        </span>
      </div>
    </header>
  );
}
