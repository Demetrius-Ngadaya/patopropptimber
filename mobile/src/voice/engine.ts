import { create } from 'zustand';
import { router } from 'expo-router';
import NetInfo from '@react-native-community/netinfo';
import { api, ApiError } from '../lib/api';
import { money, qty, round2 } from '../lib/format';
import { useSession } from '../lib/session';
import { usePrefs } from '../lib/prefs';
import { useLocale } from '../i18n';
import { listCustomers, listOrders, listProducts, todaySummary, unitsFor } from '../db/repo';
import { createCustomer, recordSale, saleTotals, setOrderStatus } from '../db/commands';
import { syncSoon } from '../sync/manager';
import { priceLines, toSaleLines, useCart } from '../features/pos/cart';
import type { Customer, Order, PaymentMethod, Product, ProductUnit } from '../lib/types';
import { parseCommand, type Command, type PayMethod, type Screen, type SpokenItem } from './parser';
import { decide, rank, similarity } from './match';
import { parseNumber } from './numbers';
import { listenOnce, microphoneAllowed, recognitionAvailable, say, stopSpeaking } from './speech';

/**
 * Voice mode: listen → understand → do it → say how it went.
 *
 * Risky actions (finishing a sale, recording money, changing an order) are read
 * back first and only done after "ndiyo / yes", so a misheard word never saves
 * anything wrong. When a product name fits several products the app asks which.
 */
type Lang = 'sw' | 'en';
type Reply = { ok: boolean; text: string };
type Pending =
  | { kind: 'confirm'; run: () => Promise<Reply> }
  | { kind: 'choice'; options: string[]; pick: (index: number) => Promise<Reply> }
  | { kind: 'text'; take: (text: string) => Promise<Reply> };

type Phase = 'idle' | 'listening' | 'thinking' | 'speaking';
export type VoiceLog = { id: number; heard: string; reply: Reply };

type VoiceState = {
  open: boolean;
  phase: Phase;
  partial: string;
  heard: string;
  reply: Reply | null;
  choices: string[];
  log: VoiceLog[];
  lang: Lang;
  setLang: (lang: Lang) => void;
  show: () => void;
  hide: () => void;
  listen: () => Promise<void>;
  handle: (text: string) => Promise<void>;
  choose: (index: number) => Promise<void>;
  stop: () => void;
};

let pending: Pending | null = null;
let current: { stop: () => void; cancel: () => void } | null = null;
let seq = 0;

const ROUTES: Record<Screen, string> = {
  home: '/', sell: '/sell', orders: '/orders', products: '/products', more: '/more', customers: '/customers',
  sales: '/sales', settings: '/settings', sync: '/sync', notifications: '/notifications', checkout: '/checkout', scan: '/scan',
};
const SCREEN_NAME: Record<Screen, [string, string]> = {
  home: ['Home', 'Nyumbani'], sell: ['Sell', 'Kuuza'], orders: ['Orders', 'Oda'], products: ['Stock', 'Bidhaa'], more: ['More', 'Zaidi'],
  customers: ['Customers', 'Wateja'], sales: ['Sales', 'Mauzo'], settings: ['Settings', 'Mipangilio'], sync: ['Sync', 'Usawazishaji'],
  notifications: ['Notifications', 'Taarifa'], checkout: ['Checkout', 'Malipo'], scan: ['Scan', 'Skani'],
};
const METHOD_NAME: Record<PayMethod, [string, string]> = { cash: ['cash', 'taslimu'], mobile_money: ['mobile money', 'pesa ya simu'], bank: ['bank', 'benki'], credit: ['credit', 'mkopo'] };

/** One sentence in the voice-mode language: m(lang, 'English', 'Kiswahili', {n: 3}). */
function m(lang: Lang, en: string, sw: string, vars: Record<string, string | number> = {}): string {
  return (lang === 'sw' ? sw : en).replace(/\{(\w+)\}/g, (_, k) => String(vars[k] ?? ''));
}
const ok = (text: string): Reply => ({ ok: true, text });
const fail = (text: string): Reply => ({ ok: false, text });
const can = (permission: string) => useSession.getState().can(permission);
const today = () => new Date().toISOString().slice(0, 10);

async function online(): Promise<boolean> {
  const net = await NetInfo.fetch().catch(() => null);
  return !!net?.isConnected && net.isInternetReachable !== false && !!useSession.getState().apiUrl && !useSession.getState().offline;
}

// ------------------------------------------------------------------ products

let productCache: { at: number; list: Product[] } | null = null;
async function allProducts(): Promise<Product[]> {
  if (productCache && Date.now() - productCache.at < 60_000) return productCache.list;
  const list = await listProducts({ limit: 20000 });
  productCache = { at: Date.now(), list };
  return list;
}

/** One product for what was said, or the few to choose from. */
async function findProduct(said: string): Promise<{ product: Product | null; choices: Product[] }> {
  const list = await allProducts();
  const exact = list.find((p) => (p.sku && p.sku.toLowerCase() === said.toLowerCase()) || (p.barcode && p.barcode === said.replace(/\s/g, '')));
  if (exact) return { product: exact, choices: [] };
  const ranked = rank(said, list, (p) => p.name, (p) => p.sku);
  // Among equally good names, the one sold most often wins only if it is clearly more popular.
  const { match, choices } = decide(ranked);
  return { product: match, choices };
}

async function unitFor(product: Product, spoken?: string): Promise<ProductUnit | null> {
  if (!spoken) return null;
  const units = (await unitsFor(product.id)).filter((u) => u.is_active);
  if (product.unit && similarity(spoken, product.unit) > 0.75) return null; // the base unit
  const best = units.map((u) => ({ u, s: Math.max(similarity(spoken, u.name), u.name.toLowerCase().includes(spoken) ? 0.9 : 0) })).sort((a, b) => b.s - a.s)[0];
  return best && best.s >= 0.7 ? best.u : null;
}

async function findCustomer(said: string): Promise<{ customer: Customer | null; choices: Customer[] }> {
  const first = said.split(' ')[0] ?? said;
  const pool = await listCustomers(first, 200);
  const more = pool.length < 5 ? await listCustomers('', 2000) : [];
  const ranked = rank(said, [...pool, ...more.filter((c) => !pool.some((p) => p.key === c.key))], (c) => c.name, (c) => c.phone);
  const { match, choices } = decide(ranked);
  return { customer: match, choices };
}

// ------------------------------------------------------------------ cart

function cartTotals() {
  const cart = useCart.getState();
  const settings = useSession.getState().settings;
  const priced = priceLines(cart);
  const tax = settings.prices_include_tax === false ? round2(priced.reduce((a, l) => a + round2((l.lineTotal * (l.product.tax_rate || 0)) / 100), 0)) : 0;
  const lines = toSaleLines(priced);
  return { priced, lines, tax, total: saleTotals(lines, cart.discount, [], tax).total };
}

function goTo(path: string) {
  try {
    router.navigate(path as never);
  } catch {
    /* the screen is not available for this account */
  }
}

// ------------------------------------------------------------------ actions

async function addItems(items: SpokenItem[], lang: Lang, done: string[] = []): Promise<Reply> {
  if (!can('pos.access')) return fail(m(lang, 'You are not allowed to sell.', 'Huna ruhusa ya kuuza.'));
  for (let i = 0; i < items.length; i++) {
    const item = items[i];
    const { product, choices } = await findProduct(item.name);
    const put = async (p: Product): Promise<Reply> => {
      const unit = await unitFor(p, item.unit);
      const quantity = p.allow_decimal ? item.quantity : Math.max(1, Math.round(item.quantity));
      useCart.getState().add(p, unit, quantity);
      const unitName = unit?.name ?? p.unit ?? '';
      const line = `${qty(quantity)}${unitName ? ` ${unitName}` : ''} ${p.name}`;
      const stockNote = !p.is_service && p.stock < quantity * (unit?.factor ?? 1) ? m(lang, ' Only {n} in stock.', ' Zipo {n} tu.', { n: qty(p.stock) }) : '';
      return addItems(items.slice(i + 1), lang, [...done, line + stockNote]);
    };
    if (product) return put(product);
    if (choices.length) {
      pending = { kind: 'choice', options: choices.map((c) => `${c.name} · ${money(c.selling_price)}`), pick: (index) => (choices[index] ? put(choices[index]) : Promise.resolve(fail(m(lang, 'That number is not on the list.', 'Namba hiyo haipo kwenye orodha.')))) };
      const list = choices.map((c, k) => `${k + 1}. ${c.name}`).join(', ');
      return ok(m(lang, 'Which {name}? {list}. Say the number.', '{name} ipi? {list}. Sema namba.', { name: item.name, list }));
    }
    const rest = await addItems(items.slice(i + 1), lang, done);
    return fail(`${m(lang, 'I could not find "{name}".', 'Sikupata bidhaa "{name}".', { name: item.name })} ${rest.text}`.trim());
  }
  if (!done.length) return fail(m(lang, 'Nothing was added.', 'Hakuna kilichoongezwa.'));
  goTo('/sell');
  const { total } = cartTotals();
  return ok(m(lang, 'Added {items}. Total now {total}.', 'Nimeongeza {items}. Jumla sasa ni {total}.', { items: done.join(', '), total: money(total) }));
}

async function pay(args: { method: PayMethod; amount?: number; reference?: string }, lang: Lang): Promise<Reply> {
  const cart = useCart.getState();
  if (!can('pos.access')) return fail(m(lang, 'You are not allowed to sell.', 'Huna ruhusa ya kuuza.'));
  if (!cart.lines.length) return fail(m(lang, 'The cart is empty. Say for example: sell three cement.', 'Kikapu kiko tupu. Sema kwa mfano: uza saruji tatu.'));
  const settings = useSession.getState().settings;
  const { priced, lines, tax, total } = cartTotals();
  const over = priced.filter((l) => l.overStock);
  if (over.length && !settings.allow_negative_stock) return fail(m(lang, 'Not enough stock for {name}.', 'Stoku haitoshi kwa {name}.', { name: over[0].product.name }));

  const credit = args.method === 'credit';
  if ((credit || (args.amount !== undefined && args.amount < total - 0.004)) && !can('pos.credit_sale')) {
    return fail(m(lang, 'You cannot sell on credit. Take the full amount of {total}.', 'Huwezi kuuza kwa mkopo. Pokea kiasi kamili cha {total}.', { total: money(total) }));
  }
  if ((args.method === 'mobile_money' || args.method === 'bank') && !args.reference) {
    pending = { kind: 'text', take: (text) => pay({ ...args, reference: text.replace(/\s+/g, '').toUpperCase().slice(0, 30) }, lang) };
    return ok(m(lang, 'Say the transaction ID.', 'Sema namba ya muamala.'));
  }
  const amount = credit ? 0 : args.amount ?? total;
  const payments = credit ? [] : [{ method: args.method as PaymentMethod, amount, reference: args.reference ?? null }];
  const totals = saleTotals(lines, cart.discount, payments, tax);
  if (totals.balance > 0.004 && !cart.customer) {
    pending = {
      kind: 'text',
      take: async (text) => {
        const r = await setCustomer(text, lang);
        return r.ok ? pay(args, lang) : r;
      },
    };
    return ok(m(lang, '{balance} will remain unpaid. Which customer will pay it?', 'Zitabaki {balance} hazijalipwa. Mteja gani atalipa?', { balance: money(totals.balance) }));
  }
  const change = totals.change > 0.004 ? m(lang, ' Change {c}.', ' Chenji {c}.', { c: money(totals.change) }) : '';
  const balance = totals.balance > 0.004 ? m(lang, ' {b} on credit to {name}.', ' {b} ni deni kwa {name}.', { b: money(totals.balance), name: cart.customer?.name ?? '' }) : '';
  pending = {
    kind: 'confirm',
    run: async () => {
      const due = new Date();
      due.setDate(due.getDate() + Number(settings.credit_default_days ?? 7));
      const key = await recordSale({ lines, customer: cart.customer, payments, discount: cart.discount, tax, dueDate: totals.balance > 0.004 ? due.toISOString().slice(0, 10) : null, notes: null });
      useCart.getState().clear();
      productCache = null;
      syncSoon();
      router.dismissTo?.('/sell' as never);
      router.push({ pathname: '/sales/[key]', params: { key, done: '1' } } as never);
      return ok(m(lang, 'Sale completed: {total}.{change}', 'Mauzo yamekamilika: {total}.{change}', { total: money(total), change }));
    },
  };
  return ok(m(lang, 'Total {total}, {method}{amount}.{change}{balance} Complete the sale? Say yes or no.', 'Jumla {total}, {method}{amount}.{change}{balance} Nikamilishe mauzo? Sema ndiyo au hapana.', {
    total: money(total),
    method: METHOD_NAME[args.method][lang === 'sw' ? 1 : 0],
    amount: !credit && args.amount !== undefined ? m(lang, ' received {a}', ', umepokea {a}', { a: money(args.amount) }) : '',
    change,
    balance,
  }));
}

async function setCustomer(said: string, lang: Lang): Promise<Reply> {
  const { customer, choices } = await findCustomer(said);
  const choose = (c: Customer): Reply => {
    useCart.getState().setCustomer(c);
    return ok(m(lang, 'Customer: {name}.', 'Mteja: {name}.', { name: c.name }));
  };
  if (customer) return choose(customer);
  if (choices.length) {
    pending = { kind: 'choice', options: choices.map((c) => [c.name, c.phone].filter(Boolean).join(' · ')), pick: async (i) => (choices[i] ? choose(choices[i]) : fail(m(lang, 'That number is not on the list.', 'Namba hiyo haipo kwenye orodha.'))) };
    return ok(m(lang, 'Which customer? {list}. Say the number.', 'Mteja yupi? {list}. Sema namba.', { list: choices.map((c, k) => `${k + 1}. ${c.name}`).join(', ') }));
  }
  return fail(m(lang, 'No customer called "{name}". To add one, say: add customer {name}, phone ...', 'Hakuna mteja anayeitwa "{name}". Kumuongeza sema: ongeza mteja {name}, namba ...', { name: said }));
}

async function findOrder(number?: string): Promise<Order | null> {
  if (!number) return null;
  const digits = number.replace(/\D/g, '');
  const list = await listOrders('all', '');
  return list.find((o) => o.number.toUpperCase() === number.toUpperCase()) ?? list.find((o) => digits && o.number.replace(/\D/g, '').replace(/^0+/, '') === digits.replace(/^0+/, '')) ?? null;
}

async function run(c: Command, lang: Lang): Promise<Reply> {
  switch (c.intent) {
    case 'navigate': {
      goTo(ROUTES[c.args.screen]);
      return ok(m(lang, 'Opened {s}.', 'Nimefungua {s}.', { s: SCREEN_NAME[c.args.screen][lang === 'sw' ? 1 : 0] }));
    }
    case 'sell_add':
      return addItems(c.args.items, lang);
    case 'sell_remove': {
      const lines = useCart.getState().lines;
      const best = lines.map((l) => ({ l, s: similarity(c.args.name, l.product.name) })).sort((a, b) => b.s - a.s)[0];
      if (!best || best.s < 0.6) return fail(m(lang, '"{name}" is not in the cart.', '"{name}" haipo kwenye kikapu.', { name: c.args.name }));
      useCart.getState().remove(best.l.key);
      return ok(m(lang, 'Removed {name}. Total now {total}.', 'Nimeondoa {name}. Jumla sasa ni {total}.', { name: best.l.product.name, total: money(cartTotals().total) }));
    }
    case 'sell_clear':
      useCart.getState().clear();
      return ok(m(lang, 'The cart is empty.', 'Kikapu kimeondolewa vyote.'));
    case 'sell_total': {
      const { priced, total } = cartTotals();
      if (!priced.length) return ok(m(lang, 'The cart is empty.', 'Kikapu kiko tupu.'));
      return ok(m(lang, '{n} products, total {total}.', 'Bidhaa {n}, jumla {total}.', { n: priced.length, total: money(total) }));
    }
    case 'sell_customer':
      return setCustomer(c.args.name, lang);
    case 'sell_pay':
      return pay(c.args, lang);

    case 'customer_add': {
      if (!can('customers.create')) return fail(m(lang, 'You are not allowed to add customers.', 'Huna ruhusa ya kuongeza wateja.'));
      const { name, phone } = c.args;
      if (!name) return fail(m(lang, 'Say the customer\'s name, e.g. add customer Juma Ali, phone 0712...', 'Sema jina la mteja, mfano: ongeza mteja Juma Ali, namba 0712...'));
      pending = {
        kind: 'confirm',
        run: async () => {
          await createCustomer({ name, phone: phone ?? null, email: null, address: null, city: null, customer_type: 'retail', allow_credit: false, credit_limit: 0 });
          syncSoon();
          return ok(m(lang, 'Customer {name} added.', 'Mteja {name} amesajiliwa.', { name }));
        },
      };
      return ok(m(lang, 'Add customer {name}{phone}? Say yes or no.', 'Nimsajili mteja {name}{phone}? Sema ndiyo au hapana.', { name, phone: phone ? m(lang, ', phone {p}', ', namba {p}', { p: phone.split('').join(' ') }) : '' }));
    }
    case 'customer_balance': {
      const { customer, choices } = await findCustomer(c.args.name);
      const tell = (x: Customer) => ok(x.balance > 0 ? m(lang, '{name} owes {b}.', '{name} anadaiwa {b}.', { name: x.name, b: money(x.balance) }) : m(lang, '{name} owes nothing.', '{name} hadaiwi chochote.', { name: x.name }));
      if (customer) return tell(customer);
      if (choices.length) {
        pending = { kind: 'choice', options: choices.map((x) => x.name), pick: async (i) => (choices[i] ? tell(choices[i]) : fail('')) };
        return ok(m(lang, 'Which customer? {list}.', 'Mteja yupi? {list}.', { list: choices.map((x, k) => `${k + 1}. ${x.name}`).join(', ') }));
      }
      return fail(m(lang, 'No customer called "{name}".', 'Hakuna mteja anayeitwa "{name}".', { name: c.args.name }));
    }

    case 'orders_list':
      goTo('/orders');
      return ok(m(lang, 'Opened orders.', 'Nimefungua oda.'));
    case 'order_open': {
      const order = await findOrder(c.args.number);
      if (!order) {
        goTo('/orders');
        return c.args.number ? fail(m(lang, 'Order {n} was not found.', 'Oda {n} haikupatikana.', { n: c.args.number })) : ok(m(lang, 'Opened orders.', 'Nimefungua oda.'));
      }
      goTo(`/orders/${order.id}`);
      return ok(m(lang, 'Order {n}: {total}.', 'Oda {n}: {total}.', { n: order.number, total: money(order.total) }));
    }
    case 'order_status': {
      const order = await findOrder(c.args.number);
      if (!order) return fail(c.args.number ? m(lang, 'Order {n} was not found.', 'Oda {n} haikupatikana.', { n: c.args.number }) : m(lang, 'Say the order number.', 'Sema namba ya oda.'));
      const status = c.args.status;
      if (status === 'completed') {
        goTo(`/orders/${order.id}`);
        return ok(m(lang, 'Order {n} is open: take the payment on the screen to complete it.', 'Oda {n} imefunguliwa: pokea malipo kwenye skrini ili kuikamilisha.', { n: order.number }));
      }
      if (status === 'cancelled' ? !can('orders.cancel') : !can('orders.update_status')) return fail(m(lang, 'You are not allowed to change orders.', 'Huna ruhusa ya kubadilisha oda.'));
      const label = { confirmed: ['confirmed', 'imethibitishwa'], processing: ['being prepared', 'inaandaliwa'], ready: ['ready', 'iko tayari'], cancelled: ['cancelled', 'imeghairiwa'] }[status];
      pending = {
        kind: 'confirm',
        run: async () => {
          await setOrderStatus(order, status);
          syncSoon();
          return ok(m(lang, 'Order {n} is now {s}.', 'Oda {n} sasa {s}.', { n: order.number, s: label[lang === 'sw' ? 1 : 0] }));
        },
      };
      return ok(m(lang, 'Mark order {n} as {s}? Say yes or no.', 'Oda {n} iwe {s}? Sema ndiyo au hapana.', { n: order.number, s: label[lang === 'sw' ? 1 : 0] }));
    }

    case 'expense_add': {
      if (!can('expenses.create')) return fail(m(lang, 'You are not allowed to record expenses.', 'Huna ruhusa ya kurekodi matumizi.'));
      if (!c.args.amount) {
        pending = { kind: 'text', take: (text) => run({ intent: 'expense_add', args: { ...c.args, amount: parseNumber(text) ?? undefined } }, lang) };
        return ok(m(lang, 'How much was the expense?', 'Matumizi ni kiasi gani?'));
      }
      if (!(await online())) return fail(m(lang, 'Recording an expense needs internet.', 'Kurekodi matumizi kunahitaji intaneti.'));
      const title = c.args.title ? c.args.title[0].toUpperCase() + c.args.title.slice(1) : m(lang, 'Expense', 'Matumizi');
      const method = c.args.method ?? 'cash';
      const amount = c.args.amount;
      pending = {
        kind: 'confirm',
        run: async () => {
          const res = await api.post('admin/expenses', { title, amount, payment_method: method, expense_date: today() });
          return ok(res.message ?? m(lang, 'Expense recorded.', 'Matumizi yamerekodiwa.'));
        },
      };
      return ok(m(lang, 'Record an expense of {a} for {t}, paid {m}? Say yes or no.', 'Nirekodi matumizi ya {a} kwa {t}, yamelipwa {m}? Sema ndiyo au hapana.', { a: money(amount), t: title, m: METHOD_NAME[method][lang === 'sw' ? 1 : 0] }));
    }

    case 'purchase_add': {
      if (!can('purchases.create')) return fail(m(lang, 'You are not allowed to record purchases.', 'Huna ruhusa ya kurekodi manunuzi.'));
      if (!c.args.items.length) return fail(m(lang, 'Say what was bought, e.g. received 20 bags of cement at 18000 from Juma.', 'Sema ulichonunua, mfano: nimenunua saruji mifuko ishirini kwa elfu kumi na nane kutoka kwa Juma.'));
      if (!(await online())) return fail(m(lang, 'Recording a purchase needs internet.', 'Kurekodi manunuzi kunahitaji intaneti.'));
      return purchase(c.args, lang, []);
    }

    case 'stock_check': {
      const { product, choices } = await findProduct(c.args.name);
      const tell = (p: Product) => ok(p.is_service ? m(lang, '{name} is a service.', '{name} ni huduma.', { name: p.name }) : m(lang, '{name}: {n} {u} in stock.', '{name}: zimebaki {n} {u}.', { name: p.name, n: qty(p.stock), u: p.unit ?? '' }));
      if (product) return tell(product);
      if (choices.length) {
        pending = { kind: 'choice', options: choices.map((p) => p.name), pick: async (i) => (choices[i] ? tell(choices[i]) : fail('')) };
        return ok(m(lang, 'Which one? {list}.', 'Ipi? {list}.', { list: choices.map((p, k) => `${k + 1}. ${p.name}`).join(', ') }));
      }
      return fail(m(lang, 'I could not find "{name}".', 'Sikupata bidhaa "{name}".', { name: c.args.name }));
    }
    case 'sales_today': {
      const s = await todaySummary(can('sales.view_all_users') ? null : useSession.getState().user?.id ?? null);
      return ok(m(lang, 'Today: {n} sales, total {total}. Cash {cash}, credit {credit}.', 'Leo: mauzo {n}, jumla {total}. Taslimu {cash}, mkopo {credit}.', { n: s.count, total: money(s.total), cash: money(s.cash), credit: money(s.credit) }));
    }
    case 'help':
      return ok(m(lang, 'Try: "sell three cement and two nails", "total", "pay cash", "open orders", "confirm order 12", "add customer Juma, phone 0712...", "expense 5000 for transport", "received 20 cement at 18000", "how many nails are left", "today\'s sales".', 'Jaribu: "uza saruji tatu na misumari mbili", "jumla", "lipa taslimu", "fungua oda", "thibitisha oda 12", "ongeza mteja Juma namba 0712...", "matumizi elfu tano usafiri", "nimenunua saruji ishirini kwa elfu kumi na nane", "misumari zimebaki ngapi", "mauzo ya leo".'));
    case 'stop':
      return ok(m(lang, 'OK.', 'Sawa.'));
    case 'yes':
    case 'no':
    case 'choose':
      return fail(m(lang, 'There is nothing to answer right now.', 'Hakuna swali la kujibu sasa hivi.'));
    default:
      return fail(m(lang, 'Sorry, I did not understand. Say "help" to hear examples.', 'Samahani, sijaelewa. Sema "msaada" kusikia mifano.'));
  }
}

/** Purchases: match each product (asking when unsure), its cost, then the supplier, then confirm. */
async function purchase(args: { items: SpokenItem[]; supplier?: string; paid?: boolean }, lang: Lang, ready: { product: Product; quantity: number; unit_cost: number }[]): Promise<Reply> {
  const [item, ...rest] = args.items;
  if (item) {
    const { product, choices } = await findProduct(item.name);
    const take = async (p: Product): Promise<Reply> => {
      if (item.unit_cost === undefined) {
        pending = {
          kind: 'text',
          take: async (text) => {
            const cost = parseNumber(text);
            if (cost === null) return fail(m(lang, 'Say the buying price as a number.', 'Sema bei ya kununulia kwa namba.'));
            return purchase({ ...args, items: rest }, lang, [...ready, { product: p, quantity: item.quantity, unit_cost: cost }]);
          },
        };
        return ok(m(lang, 'Buying price of one {name}?', 'Bei ya kununulia ya {name} moja ni kiasi gani?', { name: p.name }));
      }
      return purchase({ ...args, items: rest }, lang, [...ready, { product: p, quantity: item.quantity, unit_cost: item.unit_cost }]);
    };
    if (product) return take(product);
    if (choices.length) {
      pending = { kind: 'choice', options: choices.map((p) => p.name), pick: (i) => (choices[i] ? take(choices[i]) : Promise.resolve(fail(''))) };
      return ok(m(lang, 'Which {name}? {list}.', '{name} ipi? {list}.', { name: item.name, list: choices.map((p, k) => `${k + 1}. ${p.name}`).join(', ') }));
    }
    return fail(m(lang, 'I could not find "{name}". Add it as a product first.', 'Sikupata bidhaa "{name}". Iongeze kwanza kama bidhaa.', { name: item.name }));
  }

  let supplier: { id: number; name: string } | null = null;
  if (args.supplier) {
    try {
      const res = await api.get<{ id: number; name: string }[]>('admin/suppliers', { search: args.supplier.split(' ')[0], per_page: 20 });
      const best = rank(args.supplier, res.data, (s) => s.name)[0];
      if (best && best.score >= 0.7) supplier = best.item;
    } catch {
      /* without a supplier */
    }
  }
  const total = ready.reduce((a, l) => a + l.quantity * l.unit_cost, 0);
  const paid = args.paid !== false;
  const lines = ready.map((l) => `${qty(l.quantity)} ${l.product.name} @ ${money(l.unit_cost, false)}`).join(', ');
  pending = {
    kind: 'confirm',
    run: async () => {
      const res = await api.post('admin/purchases', {
        supplier_id: supplier?.id ?? null,
        purchase_date: today(),
        items: ready.map((l) => ({ product_id: l.product.id, quantity: l.quantity, unit_cost: l.unit_cost })),
        amount_paid: paid ? total : 0,
        payment_method: paid ? 'cash' : null,
        notes: m(lang, 'Recorded by voice in the app', 'Imerekodiwa kwa sauti kwenye programu'),
      });
      productCache = null;
      syncSoon();
      return ok(res.message ?? m(lang, 'Purchase recorded and stock updated.', 'Manunuzi yamerekodiwa na stoku imeongezwa.'));
    },
  };
  const from = supplier ? m(lang, ' from {s}', ' kutoka kwa {s}', { s: supplier.name }) : args.supplier ? m(lang, ' (supplier "{s}" not found, saved without one)', ' (msambazaji "{s}" hakupatikana, itahifadhiwa bila yeye)', { s: args.supplier }) : '';
  return ok(m(lang, 'Purchase: {lines}{from}. Total {total}, {paid}. Save it? Say yes or no.', 'Manunuzi: {lines}{from}. Jumla {total}, {paid}. Nihifadhi? Sema ndiyo au hapana.', {
    lines, from, total: money(total), paid: paid ? m(lang, 'paid in cash', 'imelipwa taslimu') : m(lang, 'not paid yet', 'bado haijalipwa'),
  }));
}

/** Ask the server's AI when the phone is not sure what was meant. */
async function askServer(text: string, lang: Lang): Promise<Command | null> {
  if (!(await online())) return null;
  try {
    const words = text.toLowerCase().split(/\s+/).filter((w) => w.length > 2);
    const products = (await allProducts())
      .map((p) => ({ p, s: Math.max(...words.map((w) => similarity(w, p.name)), 0) }))
      .filter((x) => x.s > 0.5)
      .sort((a, b) => b.s - a.s)
      .slice(0, 40)
      .map((x) => x.p.name);
    const res = await api.post<{ intent: Command['intent']; args: Record<string, unknown> }>('admin/mobile/voice/interpret', { text, locale: lang, products }, { timeoutMs: 12000 });
    return res.data.intent === 'unknown' ? null : (res.data as unknown as Command);
  } catch {
    return null;
  }
}

async function interpret(text: string, alternatives: string[], lang: Lang): Promise<Reply> {
  const awaiting = pending?.kind === 'confirm' ? 'confirm' : pending?.kind === 'choice' ? 'choice' : null;

  // Answering the app's own question.
  if (pending) {
    const p = pending;
    const parsed = parseCommand(text, { awaiting });
    if (p.kind === 'confirm') {
      if (parsed.intent === 'yes') {
        pending = null;
        return p.run();
      }
      if (parsed.intent === 'no' || parsed.intent === 'stop') {
        pending = null;
        return ok(m(lang, 'Cancelled. Nothing was saved.', 'Nimeghairi. Hakuna kilichohifadhiwa.'));
      }
    } else if (p.kind === 'choice') {
      if (parsed.intent === 'choose') {
        pending = null;
        return p.pick(parsed.args.index);
      }
      if (parsed.intent === 'no' || parsed.intent === 'stop') {
        pending = null;
        return ok(m(lang, 'Cancelled.', 'Nimeghairi.'));
      }
      // They said the name itself: pick the option that sounds closest.
      const best = p.options.map((o, i) => ({ i, s: similarity(text, o) })).sort((a, b) => b.s - a.s)[0];
      if (best && best.s >= 0.75) {
        pending = null;
        return p.pick(best.i);
      }
    } else if (p.kind === 'text') {
      if (parsed.intent === 'stop' || parsed.intent === 'no') {
        pending = null;
        return ok(m(lang, 'Cancelled.', 'Nimeghairi.'));
      }
      pending = null;
      return p.take(text);
    }
    // Something else entirely: drop the question and treat it as a new command.
    pending = null;
  }

  // The phone's own understanding first, also trying the recogniser's other guesses.
  let best = parseCommand(text);
  for (const alt of alternatives) {
    if (best.confidence >= 0.8) break;
    const other = parseCommand(alt);
    if (other.confidence > best.confidence) best = other;
  }
  if (best.confidence < 0.6) {
    const fromServer = await askServer(text, lang);
    if (fromServer) return run(fromServer, lang);
  }
  return run(best, lang);
}

export const useVoice = create<VoiceState>((set, get) => ({
  open: false,
  phase: 'idle',
  partial: '',
  heard: '',
  reply: null,
  choices: [],
  log: [],
  lang: 'sw',
  setLang: (lang) => {
    set({ lang });
    usePrefs.getState().update({ voice: { language: lang } }).catch(() => undefined);
  },
  show: () => {
    const pref = usePrefs.getState().voice.language;
    set({ open: true, lang: pref === 'auto' ? useLocale.getState().locale : pref });
    get().listen();
  },
  hide: () => {
    current?.cancel();
    current = null;
    stopSpeaking();
    pending = null;
    set({ open: false, phase: 'idle', partial: '', choices: [] });
  },
  stop: () => {
    current?.cancel();
    current = null;
    stopSpeaking();
    set({ phase: 'idle', partial: '' });
  },
  async listen() {
    const lang = get().lang;
    if (!recognitionAvailable()) {
      const reply = fail(m(lang, 'Speech recognition is not available on this phone. Install or update the Google app.', 'Utambuzi wa sauti haupatikani kwenye simu hii. Sakinisha au sasisha programu ya Google.'));
      set({ reply, phase: 'idle' });
      return;
    }
    if (!(await microphoneAllowed())) {
      set({ reply: fail(m(lang, 'Allow the microphone for PatoPro in the phone settings.', 'Ruhusu kipaza sauti kwa PatoPro kwenye mipangilio ya simu.')), phase: 'idle' });
      return;
    }
    await stopSpeaking();
    current?.cancel();
    const hints = (await allProducts().catch(() => [] as Product[])).slice(0, 100).map((p) => p.name);
    set({ phase: 'listening', partial: '', heard: '' });
    const session = listenOnce(lang, hints, (partial) => set({ partial }));
    current = session;
    try {
      const heard = await session.done;
      current = null;
      await get().handle(heard.text);
    } catch (e) {
      current = null;
      const code = e instanceof Error ? e.message : '';
      if (code === 'aborted') {
        set({ phase: 'idle' });
        return;
      }
      const text =
        code === 'no-speech'
          ? m(lang, 'I did not hear anything. Tap the microphone and speak.', 'Sijasikia chochote. Gusa kipaza sauti kisha ongea.')
          : code === 'network'
            ? m(lang, 'Listening needs internet on this phone right now.', 'Kusikiliza kunahitaji intaneti kwenye simu hii kwa sasa.')
            : code === 'language-not-supported'
              ? m(lang, 'This language is not available for listening on this phone. Switch to the other language.', 'Lugha hii haipatikani kwa kusikiliza kwenye simu hii. Badilisha lugha.')
              : m(lang, 'Could not listen ({code}).', 'Imeshindikana kusikiliza ({code}).', { code });
      set({ phase: 'idle', reply: fail(text) });
    }
  },
  async handle(text) {
    const lang = get().lang;
    set({ phase: 'thinking', heard: text, partial: '' });
    let reply: Reply;
    try {
      reply = await interpret(text, [], lang);
    } catch (e) {
      pending = null;
      reply = fail(e instanceof ApiError ? (e.offline ? m(lang, 'No internet. Try again when connected.', 'Hakuna intaneti. Jaribu tena ukiunganishwa.') : e.firstError) : m(lang, 'Something went wrong: {e}', 'Kuna hitilafu: {e}', { e: e instanceof Error ? e.message : String(e) }));
    }
    const choices = pending?.kind === 'choice' ? pending.options : [];
    const id = ++seq;
    set((s) => ({ reply, choices, phase: 'speaking', log: [{ id, heard: text, reply }, ...s.log].slice(0, 20) }));
    if (usePrefs.getState().voice.speak) await say(reply.text, lang);
    if (seq !== id) return;
    set({ phase: 'idle' });
    // A question was asked: listen for the answer straight away (hands-free).
    if (pending && get().open && usePrefs.getState().voice.handsFree) get().listen();
  },
  async choose(index) {
    const p = pending;
    if (!p || p.kind !== 'choice') return;
    pending = null;
    stopSpeaking();
    const lang = get().lang;
    set({ phase: 'thinking', choices: [] });
    let reply: Reply;
    try {
      reply = await p.pick(index);
    } catch (e) {
      reply = fail(e instanceof Error ? e.message : String(e));
    }
    const next = pending as Pending | null;
    set({ reply, choices: next && next.kind === 'choice' ? next.options : [], phase: 'speaking' });
    if (usePrefs.getState().voice.speak) await say(reply.text, lang);
    set({ phase: 'idle' });
    if (pending && get().open && usePrefs.getState().voice.handsFree) get().listen();
  },
}));

/** True while a question waits for a yes / no. */
export const waitingForYes = () => pending?.kind === 'confirm';
