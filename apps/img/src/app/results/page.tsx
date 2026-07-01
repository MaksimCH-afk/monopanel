"use client";

import { useEffect, useState } from "react";
import { SessionDto } from "@/lib/types";
import { Gallery } from "@/components/Gallery";

export default function ResultsPage() {
  const [sessions, setSessions] = useState<{ id: string; createdAt: string; status: string; prompts: string[] }[]>([]);
  const [session, setSession] = useState<SessionDto | null>(null);

  useEffect(() => {
    fetch("/api/sessions")
      .then((r) => r.json())
      .then((d) => {
        const list = d.sessions ?? [];
        setSessions(list);
        const last =
          typeof window !== "undefined" ? localStorage.getItem("lastSessionId") : null;
        const pick = list.find((s: { id: string }) => s.id === last) ?? list[0];
        if (pick) load(pick.id);
      })
      .catch(() => {});
  }, []);

  function load(id: string) {
    fetch(`/api/sessions/${id}`)
      .then((r) => r.json())
      .then((d) => d.session && setSession(d.session))
      .catch(() => {});
  }

  if (sessions.length === 0) {
    return (
      <div className="flex h-full items-center justify-center text-fg-faint">
        <span className="mono text-xs">no sessions yet — generate something first</span>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex shrink-0 items-center gap-3 border-b border-bg-border px-4 py-2">
        <span className="label">Session</span>
        <select
          value={session?.id ?? ""}
          onChange={(e) => load(e.target.value)}
          className="mono max-w-md flex-1 truncate rounded border border-bg-border bg-bg px-2 py-1 text-xs text-fg outline-none focus:border-action"
        >
          {sessions.map((s) => (
            <option key={s.id} value={s.id}>
              {new Date(s.createdAt).toLocaleString()} · {s.prompts[0]?.slice(0, 40) || "—"}
            </option>
          ))}
        </select>
      </div>
      <div className="min-h-0 flex-1">
        {session && <Gallery key={session.id} session={session} />}
      </div>
    </div>
  );
}
