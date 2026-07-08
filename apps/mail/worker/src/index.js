// Cloudflare Email Worker — ZERO dependencies.
//
// Parses each incoming message with a small built-in MIME reader (no npm
// imports) and appends a row to D1. Because it has no external modules, you can
// paste this whole file straight into the Cloudflare dashboard Worker editor
// (Quick edit) — no `wrangler`, no bundler required.
//
// Cloudflare Email Routing has no mailboxes — it forwards. With a catch-all rule
// pointed at this Worker, EVERY address of the domain (acc1@, acc37@, anything@)
// arrives here; the recipient address (message.to) is the "mailbox". The admin
// reads rows back out of D1 by that column.
//
// Binding required: a D1 database bound as `DB` (Settings → Bindings).

export default {
  async email(message, env, ctx) {
    // message.to      — recipient address (the "mailbox")
    // message.from    — sender
    // message.headers — decoded-ish header map (Headers object)
    // message.raw     — raw MIME stream

    const rawBuffer = await new Response(message.raw).arrayBuffer();
    const rawText = new TextDecoder('utf-8').decode(rawBuffer);

    const bodies = extractBodies(rawText); // { text, html }
    const subject = decodeMimeWords(getHeader(message, 'subject'));
    const from = message.from || decodeMimeWords(getHeader(message, 'from'));

    const row = {
      // Normalize to lower-case so Acc37@ and acc37@ don't split into two boxes.
      mailbox: (message.to || '').toLowerCase(),
      sender: from,
      subject,
      text_body: bodies.text,
      html_body: bodies.html,
      raw_size: rawBuffer.byteLength,
      received_at: new Date().toISOString(),
    };

    await env.DB.prepare(
      `INSERT INTO messages
         (mailbox, sender, subject, text_body, html_body, raw_size, received_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)`
    )
      .bind(
        row.mailbox,
        row.sender,
        row.subject,
        row.text_body,
        row.html_body,
        row.raw_size,
        row.received_at
      )
      .run();

    // Reject unwanted addresses instead of storing them:
    //   message.setReject("Unknown address");
    // With a catch-all we usually accept everything.
  },

  // Optional temp-mail cleanup via a Cron Trigger (see wrangler.toml.example).
  // Deletes messages older than MAIL_RETENTION_DAYS (default: keep forever).
  async scheduled(event, env, ctx) {
    const days = Number(env.MAIL_RETENTION_DAYS || 0);
    if (!days || days <= 0) return;
    const cutoff = new Date(Date.now() - days * 86_400_000).toISOString();
    await env.DB.prepare(`DELETE FROM messages WHERE received_at < ?`).bind(cutoff).run();
  },
};

// ─────────────────────────── tiny MIME reader ──────────────────────────────
// Handles the realistic cases: single-part text/plain or text/html, and
// multipart/* (alternative/mixed, nested), with base64 / quoted-printable
// transfer-encodings and RFC 2047 encoded Subject/From. Attachments are ignored.

function getHeader(message, name) {
  try {
    return message.headers?.get?.(name) || '';
  } catch {
    return '';
  }
}

function splitHeadersBody(block) {
  const m = block.match(/\r?\n\r?\n/);
  if (!m) return [block, ''];
  const idx = m.index;
  return [block.slice(0, idx), block.slice(idx + m[0].length)];
}

function parseHeaders(headerText) {
  const unfolded = headerText.replace(/\r?\n[ \t]+/g, ' '); // RFC 5322 folding
  const map = {};
  for (const line of unfolded.split(/\r?\n/)) {
    const m = line.match(/^([^:]+):\s?([\s\S]*)$/);
    if (m) map[m[1].toLowerCase()] = m[2];
  }
  return map;
}

function getParam(headerValue, name) {
  const re = new RegExp(name + '\\s*=\\s*"([^"]*)"|' + name + "\\s*=\\s*([^;\\s]+)", 'i');
  const m = re.exec(headerValue || '');
  return m ? m[1] || m[2] || '' : '';
}

function bytesFromBinaryString(bin) {
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i) & 0xff;
  return out;
}

function decodeQuotedPrintable(input, charset) {
  const s = input.replace(/=\r?\n/g, ''); // soft line breaks
  const bytes = [];
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (c === '=' && i + 2 < s.length) {
      const hex = s.substr(i + 1, 2);
      if (/^[0-9A-Fa-f]{2}$/.test(hex)) {
        bytes.push(parseInt(hex, 16));
        i += 2;
        continue;
      }
    }
    bytes.push(c.charCodeAt(0) & 0xff);
  }
  try {
    return new TextDecoder(charset || 'utf-8').decode(Uint8Array.from(bytes));
  } catch {
    return new TextDecoder('utf-8').decode(Uint8Array.from(bytes));
  }
}

function decodeTransfer(body, encoding, charset) {
  const enc = (encoding || '').toLowerCase().trim();
  if (enc === 'base64') {
    try {
      const clean = body.replace(/[^A-Za-z0-9+/=]/g, '');
      const bytes = bytesFromBinaryString(atob(clean));
      return new TextDecoder(charset || 'utf-8').decode(bytes);
    } catch {
      return body;
    }
  }
  if (enc === 'quoted-printable') return decodeQuotedPrintable(body, charset);
  return body; // 7bit / 8bit / binary / none
}

// Decode RFC 2047 words like =?UTF-8?B?...?= and =?UTF-8?Q?...?= in headers.
function decodeMimeWords(str) {
  if (!str) return '';
  // Collapse whitespace BETWEEN adjacent encoded words (spec says it's ignorable).
  const joined = str.replace(/(=\?[^?]+\?[BbQq]\?[^?]*\?=)\s+(?==\?[^?]+\?[BbQq]\?)/g, '$1');
  return joined.replace(/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g, (whole, cs, enc, data) => {
    try {
      if (enc.toUpperCase() === 'B') {
        const bytes = bytesFromBinaryString(atob(data));
        return new TextDecoder(cs).decode(bytes);
      }
      return decodeQuotedPrintable(data.replace(/_/g, ' '), cs);
    } catch {
      return whole;
    }
  });
}

function splitParts(body, boundary) {
  const delim = '--' + boundary;
  const chunks = body.split(delim);
  const parts = [];
  for (let i = 1; i < chunks.length; i++) {
    let seg = chunks[i];
    if (seg.startsWith('--')) break; // closing "--boundary--"
    seg = seg.replace(/^\r?\n/, '').replace(/\r?\n$/, '');
    parts.push(seg);
  }
  return parts;
}

function walk(block, out) {
  const [head, body] = splitHeadersBody(block);
  const headers = parseHeaders(head);
  const ctype = (headers['content-type'] || 'text/plain').toLowerCase();

  if (ctype.startsWith('multipart/')) {
    const boundary = getParam(headers['content-type'], 'boundary');
    if (!boundary) return;
    for (const part of splitParts(body, boundary)) walk(part, out);
    return;
  }

  const disposition = (headers['content-disposition'] || '').toLowerCase();
  if (disposition.startsWith('attachment')) return; // skip files

  const charset = getParam(headers['content-type'], 'charset') || 'utf-8';
  const decoded = decodeTransfer(body, headers['content-transfer-encoding'], charset).trim();
  if (ctype.startsWith('text/html')) {
    if (!out.html) out.html = decoded;
  } else if (ctype.startsWith('text/plain')) {
    if (!out.text) out.text = decoded;
  }
}

function extractBodies(raw) {
  const out = { text: '', html: '' };
  try {
    walk(raw, out);
  } catch {
    /* fall through to the raw fallback below */
  }
  if (!out.text && !out.html) {
    const [, body] = splitHeadersBody(raw);
    out.text = body.trim();
  }
  return out;
}
