/**
 * Spoken numbers in Kiswahili and English → digits.
 *   "elfu kumi na tano" → 15000, "mia tano" → 500, "elfu mbili mia tano" → 2500,
 *   "laki moja" → 100000, "nusu" → 0.5, "two thousand five hundred" → 2500,
 *   "10,000" / "10k" → 10000.
 * Kiswahili puts the size word first ("mia tano" = 5 × 100); English after it.
 */

const SW_UNITS: Record<string, number> = {
  sifuri: 0, moja: 1, mmoja: 1, kimoja: 1, mbili: 2, wawili: 2, miwili: 2, viwili: 2, mawili: 2,
  tatu: 3, watatu: 3, mitatu: 3, vitatu: 3, matatu: 3, nne: 4, wanne: 4, minne: 4, vinne: 4, manne: 4,
  tano: 5, watano: 5, mitano: 5, vitano: 5, matano: 5, sita: 6, saba: 7, nane: 8, wanane: 8, minane: 8, vinane: 8, manane: 8, tisa: 9, kenda: 9,
};
const SW_TENS: Record<string, number> = { kumi: 10, ishirini: 20, thelathini: 30, arobaini: 40, hamsini: 50, sitini: 60, sabini: 70, themanini: 80, tisini: 90 };
const SW_SCALES: Record<string, number> = { mia: 100, elfu: 1000, laki: 100000, milioni: 1000000, bilioni: 1000000000 };
const SW_FRACTIONS: Record<string, number> = { nusu: 0.5, robo: 0.25 };

const EN_UNITS: Record<string, number> = {
  zero: 0, one: 1, a: 1, an: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10,
  eleven: 11, twelve: 12, thirteen: 13, fourteen: 14, fifteen: 15, sixteen: 16, seventeen: 17, eighteen: 18, nineteen: 19,
  twenty: 20, thirty: 30, forty: 40, fifty: 50, sixty: 60, seventy: 70, eighty: 80, ninety: 90, dozen: 12,
};
const EN_SCALES: Record<string, number> = { hundred: 100, thousand: 1000, million: 1000000, billion: 1000000000 };
const EN_FRACTIONS: Record<string, number> = { half: 0.5, quarter: 0.25 };

/** A digit token as speech recognition writes it: "10,000", "2.5", "5000/=", "10k", "1.5m". */
export function digitValue(token: string): number | null {
  const m = token.toLowerCase().replace(/\/=?$/, '').replace(/^(tsh|tzs|sh)\.?/, '').match(/^(\d{1,3}(?:[,\s]\d{3})+|\d+(?:[.,]\d+)?)(k|m)?$/);
  if (!m) return null;
  const raw = /^\d{1,3}(,\d{3})+$/.test(m[1]) ? m[1].replace(/,/g, '') : m[1].replace(',', '.');
  const n = Number(raw);
  if (!Number.isFinite(n)) return null;
  return m[2] === 'k' ? n * 1000 : m[2] === 'm' ? n * 1000000 : n;
}

export const isNumberWord = (w: string) =>
  w in SW_UNITS || w in SW_TENS || w in SW_SCALES || w in SW_FRACTIONS || (w in EN_UNITS && w !== 'a' && w !== 'an') || w in EN_SCALES || w in EN_FRACTIONS || digitValue(w) !== null;

/**
 * Kiswahili: the size word comes first and its count after it ("elfu kumi na tano"
 * = 1000 × 15). "mia" takes a count of 1–9 only, so "mia moja na hamsini" = 150.
 * Returns [value, tokens used].
 */
function parseSw(tokens: string[], start: number): [number, number] | null {
  let i = start;
  let total = 0;
  let any = false;
  let lastScale = Infinity;
  const isUnit = (w?: string) => w !== undefined && w in SW_UNITS;
  const isTen = (w?: string) => w !== undefined && w in SW_TENS;

  const count = (limit: number): number | null => {
    let value = 0;
    let got = false;
    while (i < tokens.length) {
      const w = tokens[i];
      const scale = SW_SCALES[w];
      if (scale && scale < limit && !got) {
        // "elfu mia tano": the count of thousands is itself "mia tano".
        i++;
        value += scale * (count(scale) ?? 1);
        got = true;
        continue;
      }
      if (isTen(w) && !got) {
        value += SW_TENS[w];
        i++;
        got = true;
        continue;
      }
      if (isUnit(w)) {
        value += SW_UNITS[w];
        i++;
        got = true;
        break;
      }
      if (!got && digitValue(w) !== null) {
        value += digitValue(w)!;
        i++;
        got = true;
        break;
      }
      // "kumi na tano" inside a count of thousands (not of hundreds).
      if (w === 'na' && got && limit >= 1000 && (isUnit(tokens[i + 1]) || isTen(tokens[i + 1]))) {
        i++;
        continue;
      }
      break;
    }
    return got ? value : null;
  };

  while (i < tokens.length) {
    const w = tokens[i];
    const scale = SW_SCALES[w];
    if (scale) {
      i++;
      total += scale * (count(scale) ?? 1);
      lastScale = scale;
      any = true;
      continue;
    }
    if (isUnit(w) || isTen(w) || digitValue(w) !== null) {
      if (!any) {
        total += count(Infinity) ?? 0;
        any = true;
        // "10 elfu" / "tano elfu" (said the English way): the size word after the count multiplies.
        if (SW_SCALES[tokens[i]] >= 1000) {
          total *= SW_SCALES[tokens[i]];
          lastScale = SW_SCALES[tokens[i]];
          i++;
        }
      } else {
        const add = count(lastScale);
        if (add === null) break;
        total += add;
      }
      continue;
    }
    if (w === 'na' && any && (SW_SCALES[tokens[i + 1]] || isUnit(tokens[i + 1]) || isTen(tokens[i + 1]) || tokens[i + 1] in SW_FRACTIONS)) {
      i++;
      continue;
    }
    if (w in SW_FRACTIONS) {
      total += SW_FRACTIONS[w];
      i++;
      any = true;
      break;
    }
    break;
  }
  return any ? [total, i - start] : null;
}

/** English: count [scale] … ("two thousand five hundred", "one and a half"). */
function parseEn(tokens: string[], start: number): [number, number] | null {
  let i = start;
  let total = 0;
  let current = 0;
  let any = false;
  while (i < tokens.length) {
    const w = tokens[i];
    if (w === 'a' || w === 'an') {
      // "a half" / "a quarter": the fraction alone; "a hundred" / "a dozen": one of them.
      if (tokens[i + 1] in EN_FRACTIONS) {
        i++;
        continue;
      }
      if (!(tokens[i + 1] in EN_SCALES) && tokens[i + 1] !== 'dozen') break;
    }
    if (w in EN_UNITS) {
      current += EN_UNITS[w];
      any = true;
      i++;
    } else if (digitValue(w) !== null && !any) {
      current += digitValue(w)!;
      any = true;
      i++;
    } else if (w in EN_SCALES && any) {
      const s = EN_SCALES[w];
      if (s === 100) current *= 100;
      else {
        total += current * s;
        current = 0;
      }
      i++;
    } else if (w in EN_FRACTIONS && (any || tokens[i - 1] === 'a')) {
      current += EN_FRACTIONS[w];
      any = true;
      i++;
    } else if (w === 'and' && any && i + 1 < tokens.length && (tokens[i + 1] in EN_UNITS || tokens[i + 1] in EN_FRACTIONS || tokens[i + 1] === 'a')) {
      i++;
    } else if (w in EN_FRACTIONS && !any) {
      current += EN_FRACTIONS[w];
      any = true;
      i++;
    } else break;
  }
  return any ? [total + current, i - start] : null;
}

/** The number that starts at `start` (either language), or null. */
export function numberAt(tokens: string[], start: number): { value: number; length: number } | null {
  const w = tokens[start];
  if (w === undefined) return null;
  const sw = parseSw(tokens, start);
  const en = parseEn(tokens, start);
  const best = [sw, en].filter((x): x is [number, number] => !!x).sort((a, b) => b[1] - a[1])[0];
  return best ? { value: best[0], length: best[1] } : null;
}

/** Every number in a sentence, in order, with where it is. */
export function findNumbers(tokens: string[]): { value: number; start: number; length: number }[] {
  const out: { value: number; start: number; length: number }[] = [];
  for (let i = 0; i < tokens.length; ) {
    const w = tokens[i];
    // "a"/"an"/"na" alone are not numbers.
    if (w === 'a' || w === 'an' || w === 'na' || w === 'and') {
      i++;
      continue;
    }
    const n = isNumberWord(w) ? numberAt(tokens, i) : null;
    if (n) {
      out.push({ ...n, start: i });
      i += n.length;
    } else i++;
  }
  return out;
}

/** "elfu kumi" → 10000; null when the words hold no number. */
export function parseNumber(text: string): number | null {
  const tokens = tokenize(text);
  const n = findNumbers(tokens)[0];
  return n ? n.value : null;
}

/** Lower-case words; keeps digits like "10,000" and "2.5" whole. */
export function tokenize(text: string): string[] {
  return text
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/(\d),(\d{3})/g, '$1$2')
    .replace(/[^a-z0-9.\s'-]/g, ' ')
    .replace(/(^|\s)[.'-]+|[.'-]+(\s|$)/g, ' ')
    .split(/\s+/)
    .filter(Boolean);
}
