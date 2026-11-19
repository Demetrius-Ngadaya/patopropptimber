import { findNumbers, tokenize } from './numbers';

/**
 * What the person said → one action. Works on the phone (also offline) for the
 * usual ways of saying things in Kiswahili and English; anything else goes to the
 * server's AI (voice/interpret), which returns the same shape.
 */
export type Screen = 'home' | 'sell' | 'orders' | 'products' | 'more' | 'customers' | 'sales' | 'settings' | 'sync' | 'notifications' | 'checkout' | 'scan';
export type SpokenItem = { name: string; quantity: number; unit?: string; unit_cost?: number };
export type PayMethod = 'cash' | 'mobile_money' | 'bank' | 'credit';
export type OrderTarget = 'confirmed' | 'processing' | 'ready' | 'completed' | 'cancelled';

export type Command =
  | { intent: 'navigate'; args: { screen: Screen } }
  | { intent: 'sell_add'; args: { items: SpokenItem[] } }
  | { intent: 'sell_remove'; args: { name: string } }
  | { intent: 'sell_clear'; args: Record<string, never> }
  | { intent: 'sell_total'; args: Record<string, never> }
  | { intent: 'sell_customer'; args: { name: string } }
  | { intent: 'sell_pay'; args: { method: PayMethod; amount?: number; reference?: string } }
  | { intent: 'customer_add'; args: { name: string; phone?: string } }
  | { intent: 'customer_balance'; args: { name: string } }
  | { intent: 'orders_list'; args: Record<string, never> }
  | { intent: 'order_open'; args: { number?: string } }
  | { intent: 'order_status'; args: { number?: string; status: OrderTarget } }
  | { intent: 'expense_add'; args: { amount?: number; title?: string; method?: Exclude<PayMethod, 'credit'> } }
  | { intent: 'purchase_add'; args: { items: SpokenItem[]; supplier?: string; paid?: boolean } }
  | { intent: 'stock_check'; args: { name: string } }
  | { intent: 'sales_today'; args: Record<string, never> }
  | { intent: 'help'; args: Record<string, never> }
  | { intent: 'yes'; args: Record<string, never> }
  | { intent: 'no'; args: Record<string, never> }
  | { intent: 'choose'; args: { index: number } }
  | { intent: 'stop'; args: Record<string, never> }
  | { intent: 'unknown'; args: Record<string, never> };

export type Parsed = Command & { confidence: number };

const FILLER = new Set(['tafadhali', 'please', 'naomba', 'nisaidie', 'hey', 'hi', 'ok', 'okay', 'patopro', 'pato', 'pro', 'sasa', 'now', 'just', 'kindly', 'can', 'you', 'could', 'would', 'naweza', 'nataka', 'i', 'want', 'to', 'me', 'my', 'basi', 'haya']);
const SEPARATORS = new Set(['na', 'and', 'pamoja', 'plus', 'pia', 'also', 'then', 'kisha', 'halafu', 'with']);
const LINKS = new Set(['of', 'ya', 'za', 'la', 'wa', 'cha', 'vya', 'pcs', 'pc', 'piece', 'pieces', 'vipande', 'kipande', 'the', 'some']);
export const UNIT_WORDS: Record<string, string> = {
  kilo: 'kg', kg: 'kg', kgs: 'kg', kilogram: 'kg', kilograms: 'kg', gramu: 'g', grams: 'g',
  lita: 'l', litre: 'l', liter: 'l', litres: 'l', liters: 'l', l: 'l',
  mita: 'm', meter: 'm', metre: 'm', meters: 'm', metres: 'm',
  mfuko: 'bag', mifuko: 'bag', bag: 'bag', bags: 'bag', gunia: 'bag', magunia: 'bag',
  boksi: 'box', box: 'box', boxes: 'box', katoni: 'carton', carton: 'carton', cartons: 'carton',
  dazeni: 'dozen', dozen: 'dozen', pakiti: 'packet', packet: 'packet', packets: 'packet', paketi: 'packet',
  ndoo: 'bucket', bucket: 'bucket', debe: 'tin', tin: 'tin', chupa: 'bottle', bottle: 'bottle', bottles: 'bottle',
};

const has = (tokens: string[], words: string[]) => words.some((w) => (w.includes(' ') ? tokens.join(' ').includes(w) : tokens.includes(w)));
const startsWith = (tokens: string[], words: string[]) => words.some((w) => tokens.slice(0, w.split(' ').length).join(' ') === w);
const strip = (tokens: string[], words: string[]) => tokens.filter((t) => !words.includes(t));

const SCREEN_WORDS: [Screen, string[]][] = [
  ['checkout', ['checkout', 'malipo', 'lipia', 'kulipa']],
  ['scan', ['scan', 'skani', 'scanner', 'barcode', 'msimbo']],
  ['sales', ['mauzo', 'sales', 'risiti', 'receipts', 'receipt', 'stakabadhi']],
  ['sell', ['kuuza', 'sell', 'till', 'pos', 'kaunta', 'counter', 'kikapu', 'cart']],
  ['orders', ['oda', 'order', 'orders', 'maagizo', 'agizo']],
  ['products', ['bidhaa', 'products', 'product', 'stoku', 'stock', 'stok', 'ghala', 'inventory']],
  ['customers', ['wateja', 'mteja', 'customers', 'customer', 'clients']],
  ['notifications', ['arifa', 'taarifa', 'notifications', 'notification', 'alerts']],
  ['settings', ['mipangilio', 'settings', 'setting', 'mpangilio']],
  ['sync', ['sync', 'sawazisha', 'usawazishaji']],
  ['more', ['zaidi', 'more', 'menu', 'menyu']],
  ['home', ['nyumbani', 'mwanzo', 'home', 'dashboard', 'dashibodi', 'mwanzoni', 'kwanza']],
];
const OPEN_WORDS = ['fungua', 'nenda', 'onyesha', 'nionyeshe', 'peleka', 'nipeleke', 'rudi', 'open', 'go', 'show', 'take', 'back', 'view', 'ingia'];

function screenIn(tokens: string[]): Screen | null {
  for (const [screen, words] of SCREEN_WORDS) if (has(tokens, words)) return screen;
  return null;
}

function method(tokens: string[]): PayMethod | null {
  if (has(tokens, ['mkopo', 'deni', 'credit', 'baadaye', 'later', 'kukopa', 'atalipa'])) return 'credit';
  if (has(tokens, ['mpesa', 'm-pesa', 'tigo', 'tigopesa', 'airtel', 'halopesa', 'halo', 'simu', 'mobile', 'lipa namba', 'lipanamba', 'mixx', 'azam'])) return 'mobile_money';
  if (has(tokens, ['benki', 'bank', 'crdb', 'nmb', 'nbc', 'transfer'])) return 'bank';
  if (has(tokens, ['taslimu', 'cash', 'keshi', 'pesa taslimu', 'mkononi', 'cashi'])) return 'cash';
  return null;
}

/** Product lines: "mbao tatu na misumari kilo mbili", "3 bags of cement and 2 nails". */
export function parseItems(tokens: string[], withCost = false): SpokenItem[] {
  const numbers = findNumbers(tokens);
  const inNumber = new Set<number>();
  numbers.forEach((n) => {
    for (let k = n.start; k < n.start + n.length; k++) inNumber.add(k);
  });
  // Split on "na"/"and"/"," that are not inside a number ("kumi na tano").
  const parts: number[][] = [[]];
  tokens.forEach((t, k) => {
    if (SEPARATORS.has(t) && !inNumber.has(k)) {
      if (parts[parts.length - 1].length) parts.push([]);
    } else parts[parts.length - 1].push(k);
  });
  const items: SpokenItem[] = [];
  for (const part of parts) {
    if (!part.length) continue;
    const nums = numbers.filter((n) => part.includes(n.start));
    let quantity: number | undefined;
    let unitCost: number | undefined;
    const costWords = new Set(['kwa', 'at', 'bei', 'price', 'each', 'kila', 'moja', '@', 'shilingi', 'sh', 'tsh', 'cost', 'for']);
    for (const n of nums) {
      const before = tokens[n.start - 1];
      const before2 = tokens[n.start - 2];
      const isCost = withCost && (costWords.has(before ?? '') || before === 'bei' || before2 === 'bei' || (quantity !== undefined && n.value >= 50));
      if (isCost && unitCost === undefined) unitCost = n.value;
      else if (quantity === undefined) quantity = n.value;
      else if (withCost && unitCost === undefined) unitCost = n.value;
    }
    let unit: string | undefined;
    const words: string[] = [];
    for (const k of part) {
      if (inNumber.has(k)) continue;
      const t = tokens[k];
      if (UNIT_WORDS[t] && !unit) {
        unit = UNIT_WORDS[t];
        continue;
      }
      if (LINKS.has(t) || FILLER.has(t) || (withCost && ['kwa', 'at', 'bei', 'kila', 'each', 'shilingi', 'sh', 'tsh', 'price', 'cost'].includes(t))) continue;
      words.push(t);
    }
    const name = words.join(' ').trim();
    if (!name) continue;
    items.push({ name, quantity: quantity ?? 1, ...(unit ? { unit } : {}), ...(unitCost !== undefined ? { unit_cost: unitCost } : {}) });
  }
  return items;
}

const orderNumber = (tokens: string[]): string | undefined => {
  const code = tokens.find((t) => /^[a-z]{1,4}-?\d{2,}$/.test(t) || /^\d{2,}$/.test(t));
  if (code) return code.toUpperCase();
  const n = findNumbers(tokens)[0];
  return n ? String(n.value) : undefined;
};

/** The phone's own understanding. `confidence` < 0.6 means "ask the server". */
export function parseCommand(text: string, context: { awaiting?: 'confirm' | 'choice' | null } = {}): Parsed {
  const all = tokenize(text);
  const tokens = all.filter((t) => !FILLER.has(t) || t === 'ok');
  const t = tokens.filter((x) => x !== 'ok');
  const joined = t.join(' ');
  const done = (c: Command, confidence = 0.9): Parsed => ({ ...c, confidence }) as Parsed;

  if (!t.length && all.includes('ok') && context.awaiting === 'confirm') return done({ intent: 'yes', args: {} });
  if (!t.length) return done({ intent: 'unknown', args: {} }, 0);

  // Answers to the app's own question.
  if (context.awaiting === 'confirm' || context.awaiting === 'choice') {
    if (/^(ndiyo|ndio|ndyo|sawa|yes|yeah|yep|thibitisha|confirm|endelea|go ahead|sure|kweli|fanya|right|correct|haswa)\b/.test(joined)) return done({ intent: 'yes', args: {} });
    if (/^(hapana|no|nope|siyo|sio|acha|cancel|stop|ghairi|sitaki)\b/.test(joined)) return done({ intent: 'no', args: {} });
  }
  if (context.awaiting === 'choice') {
    const ordinals: [RegExp, number][] = [
      [/\b(kwanza|first|one|moja|1)\b/, 0], [/\b(pili|second|two|mbili|2)\b/, 1], [/\b(tatu|third|three|3)\b/, 2], [/\b(nne|fourth|four|4)\b/, 3],
    ];
    for (const [re, index] of ordinals) if (re.test(joined)) return done({ intent: 'choose', args: { index } });
  }
  if (/^(acha|sitisha|simama|stop|cancel|nyamaza|be quiet|enough)$/.test(joined)) return done({ intent: 'stop', args: {} });
  if (/^(msaada|help|unaweza nini|nisaidie|what can you do|commands|maelekezo)\b/.test(joined)) return done({ intent: 'help', args: {} });

  // Today's sales.
  if ((has(t, ['mauzo', 'sales', 'nimeuza', 'tumeuza', 'sold']) && has(t, ['leo', 'today', 'kiasi', 'ngapi', 'how much', 'jumla'])) && !has(t, ['uza', 'sell'])) {
    return done({ intent: 'sales_today', args: {} });
  }

  // Cart questions.
  if (/^(jumla|total|kiasi gani|ni shilingi ngapi|ni ngapi|how much|bei gani jumla|jumla ni ngapi|jumla ni)\b/.test(joined) || (has(t, ['jumla', 'total']) && t.length <= 5 && !findNumbers(t).length)) {
    return done({ intent: 'sell_total', args: {} });
  }
  if (/^((futa|ondoa|safisha|toa) (vyote|yote|kikapu|kila kitu|bidhaa zote)|clear( the)? (cart|all|everything)|empty( the)? cart|anza upya|start over|start again)/.test(joined)) {
    return done({ intent: 'sell_clear', args: {} });
  }

  // Orders.
  const orderTarget: [RegExp, OrderTarget][] = [
    [/\b(thibitisha|kubali|confirm|accept|pokea)\b/, 'confirmed'],
    [/\b(andaa|inaandaliwa|prepare|preparing|processing|pakia)\b/, 'processing'],
    [/\b(tayari|ready)\b/, 'ready'],
    [/\b(kamilisha|imekamilika|maliza|complete|completed|done|delivered|imefika|imekabidhiwa)\b/, 'completed'],
    [/\b(ghairi|cancel|sitisha|futa|reject|kataa)\b/, 'cancelled'],
  ];
  if (has(t, ['oda', 'order', 'orders', 'agizo', 'maagizo'])) {
    for (const [re, status] of orderTarget) if (re.test(joined)) return done({ intent: 'order_status', args: { number: orderNumber(t), status } });
    const number = orderNumber(t);
    if (number) return done({ intent: 'order_open', args: { number } });
    return done({ intent: 'orders_list', args: {} });
  }

  // Expenses: "matumizi elfu kumi usafiri", "nimetumia elfu tano kwa chai", "expense 5000 for transport".
  if (has(t, ['matumizi', 'gharama', 'nimetumia', 'tumetumia', 'expense', 'expenses', 'spent', 'nimelipa', 'tumelipa', 'paid for'])) {
    const nums = findNumbers(t);
    const amount = nums.length ? Math.max(...nums.map((n) => n.value)) : undefined;
    const skip = new Set<number>();
    nums.forEach((n) => {
      for (let k = n.start; k < n.start + n.length; k++) skip.add(k);
    });
    const m = method(t);
    const titleWords = t.filter((w, k) => !skip.has(k) && !['matumizi', 'gharama', 'nimetumia', 'tumetumia', 'expense', 'expenses', 'spent', 'nimelipa', 'tumelipa', 'paid', 'record', 'rekodi', 'andika', 'weka', 'ongeza', 'add', 'kwa', 'ya', 'for', 'on', 'of', 'shilingi', 'sh', 'tsh', 'tzs', 'taslimu', 'cash', 'mpesa', 'benki', 'bank', 'simu', 'mobile', 'money', 'keshi'].includes(w));
    return done({ intent: 'expense_add', args: { amount, title: titleWords.join(' ') || undefined, method: m === 'credit' ? 'cash' : m ?? 'cash' } }, amount ? 0.9 : 0.6);
  }

  // Purchases: "nimenunua mbao kumi kwa elfu tano kutoka kwa Juma", "received 20 bags of cement at 18000 from Bakhresa".
  if (startsWith(t, ['nimenunua', 'tumenunua', 'nunua', 'manunuzi', 'pokea', 'nimepokea', 'tumepokea', 'purchase', 'bought', 'received', 'receive', 'buy', 'stock in']) || has(t, ['manunuzi', 'purchase'])) {
    let body = t.slice(1);
    let supplier: string | undefined;
    const from = body.findIndex((w) => w === 'kutoka' || w === 'from' || w === 'supplier' || w === 'msambazaji');
    if (from >= 0) {
      supplier = strip(body.slice(from + 1), ['kwa', 'kwake', 'the', 'ya', 'kampuni']).join(' ') || undefined;
      body = body.slice(0, from);
    }
    const paid = has(t, ['nimelipa', 'imelipwa', 'paid', 'taslimu', 'cash']) ? true : has(t, ['deni', 'mkopo', 'credit', 'sijalipa', 'not paid', 'unpaid']) ? false : undefined;
    body = strip(body, ['nimelipa', 'imelipwa', 'paid', 'deni', 'mkopo', 'credit', 'sijalipa', 'unpaid', 'manunuzi', 'purchase', 'rekodi', 'record', 'andika']);
    const items = parseItems(body, true);
    return done({ intent: 'purchase_add', args: { items, supplier, paid } }, items.length ? 0.85 : 0.4);
  }

  // New customer: "ongeza mteja Juma Ali namba 0712 345 678".
  if (has(t, ['mteja', 'customer', 'mnunuzi']) && has(t, ['ongeza', 'sajili', 'mpya', 'add', 'new', 'register', 'create', 'unda', 'fungua'])) {
    const digits = (text.match(/(\+?\d[\d\s-]{7,}\d)/)?.[1] ?? '').replace(/\D/g, '');
    const nameWords = t.filter((w) => !/\d/.test(w) && !['ongeza', 'sajili', 'mpya', 'add', 'new', 'register', 'create', 'unda', 'fungua', 'mteja', 'customer', 'mnunuzi', 'namba', 'number', 'simu', 'phone', 'yake', 'his', 'her', 'jina', 'name', 'lake', 'ni', 'is', 'a', 'an', 'called', 'anaitwa'].includes(w));
    const name = nameWords.map((w) => w[0].toUpperCase() + w.slice(1)).join(' ');
    return done({ intent: 'customer_add', args: { name, phone: digits || undefined } }, name ? 0.9 : 0.4);
  }
  // Who owes: "deni la Juma", "Juma anadaiwa kiasi gani", "balance of Juma".
  if (has(t, ['anadaiwa', 'deni la', 'deni ya', 'balance', 'owes', 'owe', 'anadai'])) {
    const name = strip(t, ['deni', 'la', 'ya', 'anadaiwa', 'kiasi', 'gani', 'ngapi', 'balance', 'of', 'how', 'much', 'does', 'owe', 'owes', 'what', 'is', 'the', 'mteja', 'customer', 'anadai']).join(' ');
    return done({ intent: 'customer_balance', args: { name } }, name ? 0.85 : 0.4);
  }

  // Stock: "stoku ya mbao", "mbao zimebaki ngapi", "how many nails are left".
  if (has(t, ['zimebaki', 'imebaki', 'zipo ngapi', 'ipo ngapi', 'stoku ya', 'stock of', 'how many', 'left', 'in stock', 'zilizobaki', 'kiasi gani kimebaki'])) {
    const name = strip(t, ['stoku', 'stock', 'ya', 'za', 'of', 'zimebaki', 'imebaki', 'zipo', 'ipo', 'ngapi', 'how', 'many', 'much', 'are', 'is', 'left', 'in', 'zilizobaki', 'kiasi', 'gani', 'kimebaki', 'there', 'do', 'we', 'have', 'tuna', 'zina']).join(' ');
    return done({ intent: 'stock_check', args: { name } }, name ? 0.85 : 0.4);
  }

  // Paying / finishing the sale.
  const payStart = /^(lipa|lipwa|amelipa|kalipa|amelipia|malizia|maliza|kamilisha|funga mauzo|uza sasa|pay|paid|complete|finish|checkout|charge|tumalize)\b/;
  if (payStart.test(joined) || (has(t, ['amelipa', 'ametoa', 'paid', 'amepay']) && !has(t, ['oda', 'order']))) {
    const m = method(t) ?? 'cash';
    const nums = findNumbers(t);
    const amount = nums.length ? Math.max(...nums.map((n) => n.value)) : undefined;
    const refAt = t.findIndex((w) => ['kumbukumbu', 'reference', 'ref', 'muamala', 'transaction', 'code', 'namba'].includes(w));
    const reference = refAt >= 0 ? all.slice(all.indexOf(t[refAt]) + 1).join('').toUpperCase().slice(0, 30) || undefined : undefined;
    return done({ intent: 'sell_pay', args: { method: m, amount: amount && amount > 0 ? amount : undefined, reference } });
  }

  // Customer of this sale: "mteja ni Juma", "for customer Juma", "kwa Juma".
  if (/^(mteja ni|mteja|customer is|customer|for customer|sale to|kwa mteja|uza kwa)\b/.test(joined)) {
    const name = joined.replace(/^(mteja ni|mteja|customer is|customer|for customer|sale to|kwa mteja|uza kwa)\s*/, '').trim();
    if (name) return done({ intent: 'sell_customer', args: { name } });
  }

  // Removing from the cart.
  if (/^(ondoa|toa|futa|remove|delete|take out|punguza)\b/.test(joined)) {
    const name = strip(t.slice(1), ['kwenye', 'katika', 'kikapu', 'cart', 'from', 'the', 'out']).join(' ');
    if (name) return done({ intent: 'sell_remove', args: { name } });
  }

  // Selling: "uza mbao tatu na misumari kilo mbili", "sell 3 cement", "ongeza saruji mifuko miwili".
  if (/^(uza|niuzie|ongeza|weka|sell|add|put|nipe|mpe|give)\b/.test(joined) || /\b(nauza|anunua|ananunua|wants|anataka)\b/.test(joined)) {
    const body = t.slice(1).filter((w) => !['kwenye', 'katika', 'kikapu', 'cart', 'to', 'the', 'kwa', 'mteja', 'customer', 'anataka', 'wants', 'ananunua', 'anunua'].includes(w));
    const items = parseItems(body);
    if (items.length) return done({ intent: 'sell_add', args: { items } }, 0.8);
  }

  // Going somewhere: "fungua oda", "nenda mauzo", "open customers", or just "wateja".
  const screen = screenIn(t);
  if (screen && (startsWith(t, OPEN_WORDS) || t.length <= 2)) return done({ intent: 'navigate', args: { screen } }, startsWith(t, OPEN_WORDS) ? 0.95 : 0.75);

  // A bare product with a number: "mbao tatu" on the till.
  const items = parseItems(t);
  if (items.length && findNumbers(t).length) return done({ intent: 'sell_add', args: { items } }, 0.55);

  return done({ intent: 'unknown', args: {} }, 0);
}
