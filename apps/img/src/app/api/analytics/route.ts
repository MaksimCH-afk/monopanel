import { NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { cached, CacheTags } from "@/lib/cache";

export const dynamic = "force-dynamic";

// Analytics are not real-time: cache with a short TTL.
const TTL_MS = 60_000;

export async function GET() {
  const data = await cached(
    "analytics:summary",
    async () => {
      const results = await prisma.result.findMany({
        select: {
          modelId: true,
          modelName: true,
          modelColor: true,
          status: true,
          rating: true,
          latencyMs: true,
          createdAt: true,
        },
        orderBy: { createdAt: "asc" },
      });

      type Agg = {
        modelId: string;
        modelName: string;
        modelColor: string;
        total: number;
        done: number;
        errored: number;
        ratedCount: number;
        ratingSum: number;
        latencySum: number;
        latencyCount: number;
        latencyHistory: { at: string; latencyMs: number }[];
      };

      const byModel = new Map<string, Agg>();
      for (const r of results) {
        let a = byModel.get(r.modelId);
        if (!a) {
          a = {
            modelId: r.modelId,
            modelName: r.modelName,
            modelColor: r.modelColor,
            total: 0,
            done: 0,
            errored: 0,
            ratedCount: 0,
            ratingSum: 0,
            latencySum: 0,
            latencyCount: 0,
            latencyHistory: [],
          };
          byModel.set(r.modelId, a);
        }
        a.total++;
        if (r.status === "done") a.done++;
        if (r.status === "error") a.errored++;
        if (r.rating > 0) {
          a.ratedCount++;
          a.ratingSum += r.rating;
        }
        if (typeof r.latencyMs === "number") {
          a.latencySum += r.latencyMs;
          a.latencyCount++;
          a.latencyHistory.push({
            at: r.createdAt.toISOString(),
            latencyMs: r.latencyMs,
          });
        }
      }

      const models = Array.from(byModel.values()).map((a) => ({
        modelId: a.modelId,
        modelName: a.modelName,
        modelColor: a.modelColor,
        total: a.total,
        done: a.done,
        errored: a.errored,
        successRate: a.total ? a.done / a.total : 0,
        avgRating: a.ratedCount ? a.ratingSum / a.ratedCount : null,
        ratedCount: a.ratedCount,
        avgLatencyMs: a.latencyCount ? Math.round(a.latencySum / a.latencyCount) : null,
        latencyHistory: a.latencyHistory.slice(-50),
      }));

      models.sort((x, y) => (y.avgRating ?? -1) - (x.avgRating ?? -1));
      return { models };
    },
    { ttlMs: TTL_MS, tags: [CacheTags.analytics] },
  );

  return NextResponse.json(data);
}
