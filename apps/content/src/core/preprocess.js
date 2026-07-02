// Text preprocessing (TZ §6.1). Strips menu/nav/footer scraps before the text
// goes to the NL API, normalizes whitespace, and truncates to the API doc cap.

export function cleanText(raw, { minLineWords, maxDocChars }) {
  if (!raw) return { text: '', truncated: false };

  const lines = String(raw)
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((line) => line.replace(/\s+/g, ' ').trim())
    .filter((line) => {
      if (!line) return false;
      // drop short lines — these are typically menu items / nav / breadcrumbs
      const words = line.match(/\S+/g);
      return words && words.length >= minLineWords;
    });

  let text = lines.join('\n');
  let truncated = false;
  if (text.length > maxDocChars) {
    // truncate on a whitespace boundary to avoid splitting a word
    let cut = text.lastIndexOf(' ', maxDocChars);
    if (cut < maxDocChars * 0.9) cut = maxDocChars;
    text = text.slice(0, cut);
    truncated = true;
  }

  return { text, truncated };
}
