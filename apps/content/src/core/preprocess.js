// Text preprocessing. The operator pastes only the real content by hand, so
// there is no menu/nav/footer scrap to strip here — this only normalizes
// whitespace and, as a last-resort safeguard, truncates to the NL API's hard
// document-size limit. It does not drop short lines or shorten realistic docs.

export function cleanText(raw, { maxDocChars }) {
  if (!raw) return { text: '', truncated: false };

  const lines = String(raw)
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((line) => line.replace(/\s+/g, ' ').trim())
    .filter((line) => line.length > 0); // drop only empty lines; keep short ones

  let text = lines.join('\n');
  let truncated = false;

  // Safeguard against the NL API's hard length limit — not a quality "cleanup".
  // Realistic pages stay well under maxDocChars and pass through untouched.
  if (text.length > maxDocChars) {
    // truncate on a whitespace boundary to avoid splitting a word
    let cut = text.lastIndexOf(' ', maxDocChars);
    if (cut < maxDocChars * 0.9) cut = maxDocChars;
    text = text.slice(0, cut);
    truncated = true;
  }

  return { text, truncated };
}
