// Deterministic demo data so the UI works end-to-end without a Cloudflare
// account (MOCK mode — no credentials configured, or MOCK_MODE=true). Mirrors
// the `messages` table the Email Worker writes into D1 (see worker/schema.sql).

const DOMAIN = process.env.MAIL_DOMAIN || 'mydomain.com';

function iso(minutesAgo) {
  return new Date(Date.now() - minutesAgo * 60_000).toISOString();
}

// A few fake messages across three "mailboxes".
export const MESSAGES = [
  {
    id: 1,
    mailbox: `acc37@${DOMAIN}`,
    sender: 'no-reply@github.com',
    subject: '[GitHub] A third-party OAuth application has been added',
    text_body:
      'Hey there!\n\nA third-party OAuth application (Monopanel) with read:org and repo scopes was recently authorized to access your account.\n\nVisit https://github.com/settings/connections/applications for more information.\n\nThanks,\nThe GitHub Team',
    html_body: '',
    raw_size: 4210,
    received_at: iso(4),
  },
  {
    id: 2,
    mailbox: `acc37@${DOMAIN}`,
    sender: 'security@cloudflare.com',
    subject: 'Your verification code is 481920',
    text_body: 'Your Cloudflare verification code is 481920. It expires in 10 minutes. If you did not request this, ignore this email.',
    html_body: '<p>Your Cloudflare verification code is <b>481920</b>. It expires in 10 minutes.</p>',
    raw_size: 1890,
    received_at: iso(31),
  },
  {
    id: 3,
    mailbox: `billing@${DOMAIN}`,
    sender: 'invoices@vendor.example',
    subject: 'Invoice #2026-0714 is ready',
    text_body: 'Hello,\n\nYour invoice #2026-0714 for $49.00 is attached and due on 2026-07-21.\n\nRegards,\nBilling',
    html_body: '',
    raw_size: 3050,
    received_at: iso(95),
  },
  {
    id: 4,
    mailbox: `acc1@${DOMAIN}`,
    sender: 'newsletter@news.example',
    subject: 'Weekly digest — 12 stories you missed',
    text_body: 'This week in tech: a roundup of the 12 most-read stories. Unsubscribe any time.',
    html_body: '<h1>Weekly digest</h1><p>This week in tech…</p>',
    raw_size: 15230,
    received_at: iso(240),
  },
  {
    id: 5,
    mailbox: `acc1@${DOMAIN}`,
    sender: 'support@service.example',
    subject: 'Re: Ticket #55123 — resolved',
    text_body: 'Hi,\n\nWe have resolved your ticket #55123. Let us know if the issue persists.\n\nBest,\nSupport',
    html_body: '',
    raw_size: 2110,
    received_at: iso(1440),
  },
];

export function mockListMailboxes() {
  const by = new Map();
  for (const m of MESSAGES) {
    const cur = by.get(m.mailbox) || { mailbox: m.mailbox, count: 0, last_at: '' };
    cur.count += 1;
    if (m.received_at > cur.last_at) cur.last_at = m.received_at;
    by.set(m.mailbox, cur);
  }
  return [...by.values()].sort((a, b) => (a.last_at < b.last_at ? 1 : -1));
}

export function mockListMessages(mailbox, { limit = 50, search = '' } = {}) {
  const q = search.trim().toLowerCase();
  return MESSAGES.filter((m) => m.mailbox === mailbox)
    .filter((m) =>
      !q ||
      (m.subject || '').toLowerCase().includes(q) ||
      (m.sender || '').toLowerCase().includes(q) ||
      (m.text_body || '').toLowerCase().includes(q)
    )
    .sort((a, b) => (a.received_at < b.received_at ? 1 : -1))
    .slice(0, limit)
    .map(({ id, sender, subject, received_at, raw_size }) => ({ id, sender, subject, received_at, raw_size }));
}

export function mockGetMessage(id) {
  return MESSAGES.find((m) => m.id === Number(id)) || null;
}

export function mockPing() {
  return { total: MESSAGES.length, mailboxes: mockListMailboxes().length };
}
