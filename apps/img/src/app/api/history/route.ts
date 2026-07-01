import { NextResponse } from "next/server";
import { prisma } from "@/lib/db";
import { serializePrompt } from "@/lib/serialize";
import { cached, CacheTags } from "@/lib/cache";

export const dynamic = "force-dynamic";

export async function GET() {
  const prompts = await cached(
    "history:all",
    async () => {
      const rows = await prisma.promptHistory.findMany({
        orderBy: { lastUsedAt: "desc" },
        take: 200,
      });
      return rows.map(serializePrompt);
    },
    { tags: [CacheTags.history] },
  );
  return NextResponse.json({ prompts });
}
