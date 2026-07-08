-- D1 schema for the mail catcher. The Email Worker appends one row per incoming
-- message; the admin reads from here over the D1 REST API.
CREATE TABLE IF NOT EXISTS messages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  mailbox     TEXT NOT NULL,   -- recipient address = "mailbox", e.g. acc37@mydomain.com
  sender      TEXT,            -- from
  subject     TEXT,
  text_body   TEXT,
  html_body   TEXT,
  raw_size    INTEGER,
  received_at TEXT NOT NULL    -- ISO-8601
);
CREATE INDEX IF NOT EXISTS idx_mailbox     ON messages(mailbox);
CREATE INDEX IF NOT EXISTS idx_received_at ON messages(received_at);
