// Small numeric helpers used by the aggregator. Kept dependency-free so the
// deterministic core stays trivially testable.

export function median(values) {
  const arr = values.filter((v) => Number.isFinite(v)).slice().sort((a, b) => a - b);
  if (arr.length === 0) return 0;
  const mid = Math.floor(arr.length / 2);
  return arr.length % 2 === 0 ? (arr[mid - 1] + arr[mid]) / 2 : arr[mid];
}

export function countWords(text) {
  if (!text) return 0;
  const m = text.trim().match(/\S+/g);
  return m ? m.length : 0;
}

export function clamp(v, lo, hi) {
  return Math.min(hi, Math.max(lo, v));
}
