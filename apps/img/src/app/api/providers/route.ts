import { NextResponse } from "next/server";
import { listProviders, defaultSchemaFor } from "@/lib/adapters/registry";

export const dynamic = "force-dynamic";

// Used by the "add model" form in Settings to offer providers + default schema.
export async function GET() {
  const providers = listProviders().map((p) => ({
    id: p,
    defaultParamsSchema: defaultSchemaFor(p),
  }));
  return NextResponse.json({ providers });
}
