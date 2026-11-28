import { tokenize } from './numbers';

/**
 * Finding the product (or customer) someone said, even when speech recognition
 * hears it a little wrong ("misumari" / "msumari", "simenti" / "cement",
 * "bati geji 28" / "Bati la geji 28"). Scores 0–1; the caller asks when unsure.
 */
export type Candidate<T> = { item: T; score: number };

const SAME: Record<string, string> = {
  simenti: 'saruji', cement: 'saruji', sementi: 'saruji', nails: 'misumari', nail: 'misumari', msumari: 'misumari',
  timber: 'mbao', wood: 'mbao', plank: 'mbao', planks: 'mbao', paint: 'rangi', sugar: 'sukari', rice: 'mchele', flour: 'unga',
  oil: 'mafuta', soap: 'sabuni', salt: 'chumvi', water: 'maji', iron: 'bati', sheet: 'bati', sheets: 'bati', mabati: 'bati',
};

function stem(w: string): string {
  const s = SAME[w] ?? w;
  // Kiswahili noun classes: "mi-/m-", "ma-/(ji)" prefixes say the same thing.
  return s.length > 4 ? s.replace(/^(mi|ma|vi|wa)(?=[a-z]{3})/, '').replace(/s$/, '') : s.replace(/s$/, '');
}

function editRatio(a: string, b: string): number {
  if (a === b) return 1;
  if (!a.length || !b.length) return 0;
  const dp = Array.from({ length: b.length + 1 }, (_, j) => j);
  for (let i = 1; i <= a.length; i++) {
    let prev = dp[0];
    dp[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const tmp = dp[j];
      dp[j] = Math.min(dp[j] + 1, dp[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return 1 - dp[b.length] / Math.max(a.length, b.length);
}

/** How a word sounds, so "light" finds "Lite", "kola" finds "Cola", "fanta" finds "Phanta". */
function sound(w: string): string {
  return w
    .replace(/ph/g, 'f').replace(/igh(t?)/g, 'ai$1').replace(/ite$/, 'ait').replace(/ck|c(?=[aou])|q/g, 'k').replace(/c/g, 's')
    .replace(/z/g, 's').replace(/y/g, 'i').replace(/(.)\1+/g, '$1').replace(/(?<=..)e$/, '');
}

const words = (s: string) => tokenize(s).filter((w) => !['la', 'ya', 'za', 'wa', 'cha', 'of', 'the', 'na', 'and'].includes(w)).map(stem);

/** How well `said` names `label` (0–1). */
export function similarity(said: string, label: string, code?: string | null): number {
  const a = words(said);
  const b = words(label);
  if (!a.length || !b.length) return 0;
  if (code && a.join('') === tokenize(code).join('')) return 1;
  // Each spoken word finds its best word in the name.
  let sum = 0;
  for (const w of a) {
    let best = 0;
    for (const x of b) {
      const r = w === x ? 1 : x.startsWith(w) && w.length >= 3 ? 0.9 : /^\d+$/.test(w) || /^\d+$/.test(x) ? (w === x ? 1 : 0) : Math.max(editRatio(w, x), editRatio(sound(w), sound(x)) * 0.95);
      if (r > best) best = r;
    }
    sum += best;
  }
  const coverage = sum / a.length;
  // A name with many extra words is a weaker match than one that says just that.
  const extra = Math.max(0, b.length - a.length);
  const whole = editRatio(a.join(' '), b.join(' '));
  // The whole phrase only helps a little (it hides a wrong size like "inchi 3" / "inchi 4").
  return Math.min(1, Math.max(coverage * (1 - Math.min(extra, 4) * 0.04), whole === 1 ? 1 : whole * 0.85));
}

export function rank<T>(said: string, items: T[], label: (t: T) => string, code?: (t: T) => string | null | undefined): Candidate<T>[] {
  return items
    .map((item) => ({ item, score: similarity(said, label(item), code?.(item)) }))
    .filter((c) => c.score > 0.45)
    .sort((x, y) => y.score - x.score);
}

/**
 * The one clear match, or the few to choose from: clear when the best is good
 * and clearly ahead of the second.
 */
export function decide<T>(candidates: Candidate<T>[]): { match: T | null; choices: T[] } {
  const [first, second] = candidates;
  if (!first) return { match: null, choices: [] };
  if (first.score >= 0.82 && (!second || first.score - second.score >= 0.12 || second.score < 0.7)) return { match: first.item, choices: [] };
  if (first.score >= 0.95 && second && second.score < 0.95) return { match: first.item, choices: [] };
  // Only one product is anywhere near: asking "which one?" with one choice helps nobody.
  if (first.score >= 0.75 && (!second || second.score < 0.6)) return { match: first.item, choices: [] };
  return { match: null, choices: candidates.filter((c) => c.score >= Math.max(0.6, first.score - 0.12)).slice(0, 4).map((c) => c.item) };
}
