#!/usr/bin/env node
// Bulk-provision Cloudflare Email Routing for many domains (TZ §7):
//   1) enable Email Routing on the zone,
//   2) add the required MX + SPF (TXT) records,
//   3) create a catch-all rule with action "send to Worker" → the mail-catcher.
//
// Usage:
//   CF_API_TOKEN=xxxx node scripts/setup-domains.mjs example.com other.com
//   CF_API_TOKEN=xxxx node scripts/setup-domains.mjs --file domains.txt --worker mail-catcher
//
// Token needs: Zone → Email Routing (Edit) and Zone → DNS (Edit) on the zones.
// Idempotent: re-running is safe (already-enabled / existing records are fine).

import fs from 'node:fs';

const API = process.env.CF_API_BASE || 'https://api.cloudflare.com/client/v4';
const TOKEN = process.env.CF_API_TOKEN;
if (!TOKEN) {
  console.error('CF_API_TOKEN is required (Email Routing + DNS edit permissions).');
  process.exit(1);
}

// --- args -------------------------------------------------------------------
const args = process.argv.slice(2);
let worker = 'mail-catcher';
const domains = [];
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--worker') worker = args[++i];
  else if (a === '--file') {
    const file = args[++i];
    for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
      const d = line.trim();
      if (d && !d.startsWith('#')) domains.push(d);
    }
  } else if (!a.startsWith('--')) domains.push(a);
}
if (!domains.length) {
  console.error('No domains given. Pass them as args or via --file domains.txt');
  process.exit(1);
}

async function cf(path, { method = 'GET', body } = {}) {
  const res = await fetch(`${API}${path}`, {
    method,
    headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json().catch(() => ({}));
  if (!json.success) {
    const msg = (json.errors || []).map((e) => `${e.code} ${e.message}`).join('; ') || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return json.result;
}

async function zoneId(name) {
  const r = await cf(`/zones?name=${encodeURIComponent(name)}`);
  if (!r.length) throw new Error(`zone not found (is ${name} on Cloudflare DNS?)`);
  return r[0].id;
}

async function setupDomain(name) {
  const zid = await zoneId(name);

  // 1) enable Email Routing (ignore "already enabled").
  try {
    await cf(`/zones/${zid}/email/routing/enable`, { method: 'POST', body: {} });
    console.log(`  · routing enabled`);
  } catch (e) {
    console.log(`  · routing: ${e.message} (continuing)`);
  }

  // 2) add the required MX + SPF (TXT) records automatically.
  try {
    await cf(`/zones/${zid}/email/routing/dns`, { method: 'POST', body: { name } });
    console.log(`  · MX + SPF records added`);
  } catch (e) {
    console.log(`  · DNS records: ${e.message} (continuing)`);
  }

  // 3) catch-all → Worker.
  await cf(`/zones/${zid}/email/routing/rules/catch_all`, {
    method: 'PUT',
    body: {
      name: 'catch-all to mail-catcher',
      enabled: true,
      matchers: [{ type: 'all' }],
      actions: [{ type: 'worker', value: [worker] }],
    },
  });
  console.log(`  · catch-all → worker "${worker}"`);
}

let ok = 0;
let fail = 0;
for (const d of domains) {
  console.log(`\n${d}`);
  try {
    await setupDomain(d);
    ok++;
  } catch (e) {
    console.error(`  ✗ ${e.message}`);
    fail++;
  }
}
console.log(`\nDone. ${ok} ok, ${fail} failed.`);
process.exit(fail ? 1 : 0);
