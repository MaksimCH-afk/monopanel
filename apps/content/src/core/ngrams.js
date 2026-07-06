// N-gram generation (TZ §5.1). Slides a window of size n = 1..nMax over the
// token stream, strictly WITHIN each sentence — never across a sentence boundary
// (§5.1, §12), otherwise phrases glue across a period into nonsense. Levels are
// counted independently over the same stream, so a token contributes to its
// unigram, bigram, trigram … simultaneously (the overlap rule, §6.2).

/**
 * @param {string[][]} sentenceTokens  per-sentence normalized token arrays
 * @param {number} nMax                highest n-gram level (trigrams n=3 always included)
 * @returns {{levels: Map<number, Map<string,{tokens:string[], count:number}>>, N:number}}
 *   `N` is the total word-token count of the document (the common density
 *   denominator for every level, §6.1).
 */
export function countNgrams(sentenceTokens, nMax) {
  const levels = new Map();
  for (let n = 1; n <= nMax; n++) levels.set(n, new Map());

  let N = 0;
  for (const tokens of sentenceTokens) {
    N += tokens.length;
    for (let n = 1; n <= nMax; n++) {
      const level = levels.get(n);
      // sliding window within this sentence only
      for (let i = 0; i + n <= tokens.length; i++) {
        const gram = tokens.slice(i, i + n);
        const key = gram.join(' ');
        const cur = level.get(key);
        if (cur) cur.count += 1;
        else level.set(key, { tokens: gram, count: 1 });
      }
    }
  }
  return { levels, N };
}
