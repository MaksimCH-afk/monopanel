import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// The Worker is a Cloudflare module-worker (default export + top-level helpers).
// It uses only web-standard globals (atob, TextDecoder, Response) that Node also
// provides, so we can import the helpers by evaluating the file in a small shim.
// Simplest robust approach: read the source and pull the internal functions out
// via a dynamic import of a rewritten module.

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const src = readFileSync(path.join(__dirname, '..', 'worker', 'src', 'index.js'), 'utf8');

// Strip the `export default { ... };` block and re-export the helpers so we can
// unit-test the parser. The helpers are plain top-level function declarations.
const withoutDefault = src.replace(/export default \{[\s\S]*?\n\};\n/, '');
const testable =
  withoutDefault +
  '\nexport { extractBodies, decodeMimeWords, decodeTransfer, decodeQuotedPrintable };\n';
const dataUrl = 'data:text/javascript;base64,' + Buffer.from(testable).toString('base64');
const mod = await import(dataUrl);

test('decodeMimeWords: UTF-8 Base64 subject', () => {
  // "Привет" encoded
  const enc = '=?UTF-8?B?' + Buffer.from('Привет', 'utf8').toString('base64') + '?=';
  assert.equal(mod.decodeMimeWords(enc), 'Привет');
});

test('decodeMimeWords: Q-encoding with underscore = space', () => {
  const enc = '=?UTF-8?Q?Hello_World=21?=';
  assert.equal(mod.decodeMimeWords(enc), 'Hello World!');
});

test('decodeTransfer: base64 utf-8', () => {
  const b64 = Buffer.from('café ☕', 'utf8').toString('base64');
  assert.equal(mod.decodeTransfer(b64, 'base64', 'utf-8'), 'café ☕');
});

test('decodeQuotedPrintable: soft breaks + hex bytes', () => {
  const qp = 'Caf=C3=A9 =\r\nnext';
  assert.equal(mod.decodeQuotedPrintable(qp, 'utf-8'), 'Café next');
});

test('extractBodies: simple text/plain', () => {
  const raw = ['Content-Type: text/plain; charset=utf-8', '', 'just text body', ''].join('\r\n');
  const { text, html } = mod.extractBodies(raw);
  assert.equal(text, 'just text body');
  assert.equal(html, '');
});

// Proper quoted-printable: every non-printable/non-ASCII byte becomes =XX.
const qpEncode = (s) =>
  Buffer.from(s, 'utf8')
    .toString('hex')
    .match(/../g)
    .map((h) => {
      const b = parseInt(h, 16);
      return b >= 33 && b <= 126 && b !== 61 ? String.fromCharCode(b) : '=' + h.toUpperCase();
    })
    .join('');

test('extractBodies: multipart/alternative with base64 html + qp text', () => {
  const textPart = qpEncode('привет мир'); // valid QP
  const htmlB64 = Buffer.from('<p>Hello <b>world</b></p>', 'utf8').toString('base64');
  const raw = [
    'From: a@b.com',
    'Content-Type: multipart/alternative; boundary="BND"',
    '',
    '--BND',
    'Content-Type: text/plain; charset=utf-8',
    'Content-Transfer-Encoding: quoted-printable',
    '',
    textPart,
    '--BND',
    'Content-Type: text/html; charset=utf-8',
    'Content-Transfer-Encoding: base64',
    '',
    htmlB64,
    '--BND--',
    '',
  ].join('\r\n');
  const { text, html } = mod.extractBodies(raw);
  assert.match(text, /привет/);
  assert.equal(html, '<p>Hello <b>world</b></p>');
});

test('extractBodies: nested multipart/mixed → alternative, attachment ignored', () => {
  const raw = [
    'Content-Type: multipart/mixed; boundary="OUT"',
    '',
    '--OUT',
    'Content-Type: multipart/alternative; boundary="IN"',
    '',
    '--IN',
    'Content-Type: text/plain; charset=utf-8',
    '',
    'inner text',
    '--IN--',
    '--OUT',
    'Content-Type: application/pdf; name="x.pdf"',
    'Content-Disposition: attachment; filename="x.pdf"',
    'Content-Transfer-Encoding: base64',
    '',
    'JVBERi0xLjQK',
    '--OUT--',
    '',
  ].join('\r\n');
  const { text, html } = mod.extractBodies(raw);
  assert.equal(text, 'inner text');
  assert.equal(html, '');
});
