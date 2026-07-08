// Cloudflare Email Worker: parses each incoming message and appends it to D1.
//
// Cloudflare Email Routing has no mailboxes — it forwards. With a catch-all rule
// pointed at this Worker, EVERY address of the domain (acc1@, acc37@, anything@)
// arrives here; the recipient address (message.to) is the "mailbox". The admin
// then reads rows back out of D1 by that column.
//
// Deploy: see worker/README.md (npm i, wrangler d1 create/execute, wrangler deploy).

import PostalMime from 'postal-mime';

export default {
  async email(message, env, ctx) {
    // message.to  — recipient address (the "mailbox")
    // message.from — sender
    // message.raw  — raw MIME stream

    const rawBuffer = await new Response(message.raw).arrayBuffer();

    // postal-mime turns raw MIME into readable fields.
    const parsed = await new PostalMime().parse(rawBuffer);

    const row = {
      // Normalize to lower-case so Acc37@ and acc37@ don't split into two boxes.
      mailbox: (message.to || '').toLowerCase(),
      sender: message.from || parsed.from?.address || '',
      subject: parsed.subject || '',
      text_body: parsed.text || '',
      html_body: parsed.html || '',
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
