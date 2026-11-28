import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { parseCommand } from './parser';

const p = (text: string, awaiting?: 'confirm' | 'choice') => {
  const r = parseCommand(text, { awaiting });
  return { intent: r.intent, args: r.args };
};

describe('voice commands (Kiswahili)', () => {
  it('opens screens', () => {
    assert.deepEqual(p('fungua oda'), { intent: 'orders_list', args: {} });
    assert.deepEqual(p('nenda kwa wateja'), { intent: 'navigate', args: { screen: 'customers' } });
    assert.deepEqual(p('fungua mauzo'), { intent: 'navigate', args: { screen: 'sales' } });
    assert.deepEqual(p('nyumbani'), { intent: 'navigate', args: { screen: 'home' } });
    assert.deepEqual(p('onyesha bidhaa'), { intent: 'navigate', args: { screen: 'products' } });
  });
  it('adds products with quantities and units', () => {
    assert.deepEqual(p('uza mbao tatu na misumari kilo mbili'), {
      intent: 'sell_add',
      args: { items: [{ name: 'mbao', quantity: 3 }, { name: 'misumari', quantity: 2, unit: 'kg' }] },
    });
    assert.deepEqual(p('ongeza saruji mifuko kumi na tano'), { intent: 'sell_add', args: { items: [{ name: 'saruji', quantity: 15, unit: 'bag' }] } });
    assert.deepEqual(p('uza bati la geji 28'), { intent: 'sell_add', args: { items: [{ name: 'bati geji', quantity: 28 }] } });
  });
  it('pays and finishes the sale', () => {
    assert.deepEqual(p('lipa taslimu elfu kumi'), { intent: 'sell_pay', args: { method: 'cash', amount: 10000, reference: undefined } });
    assert.deepEqual(p('amelipa kwa mpesa'), { intent: 'sell_pay', args: { method: 'mobile_money', amount: undefined, reference: undefined } });
    assert.deepEqual(p('malizia kwa mkopo'), { intent: 'sell_pay', args: { method: 'credit', amount: undefined, reference: undefined } });
  });
  it('knows cart questions', () => {
    assert.equal(p('jumla ni ngapi').intent, 'sell_total');
    assert.equal(p('futa vyote').intent, 'sell_clear');
    assert.deepEqual(p('ondoa misumari'), { intent: 'sell_remove', args: { name: 'misumari' } });
    assert.deepEqual(p('mteja ni juma ali'), { intent: 'sell_customer', args: { name: 'juma ali' } });
  });
  it('records expenses, purchases and customers', () => {
    assert.deepEqual(p('matumizi elfu kumi usafiri'), { intent: 'expense_add', args: { amount: 10000, title: 'usafiri', method: 'cash' } });
    assert.deepEqual(p('nimetumia elfu tano kwa chai kwa mpesa'), { intent: 'expense_add', args: { amount: 5000, title: 'chai', method: 'mobile_money' } });
    assert.deepEqual(p('nimenunua mbao kumi kwa elfu tano kila moja kutoka kwa juma'), {
      intent: 'purchase_add',
      args: { items: [{ name: 'mbao', quantity: 10, unit_cost: 5000 }], supplier: 'juma', paid: undefined },
    });
    assert.deepEqual(p('ongeza mteja juma ali namba 0712 345 678'), { intent: 'customer_add', args: { name: 'Juma Ali', phone: '0712345678' } });
    assert.deepEqual(p('deni la juma'), { intent: 'customer_balance', args: { name: 'juma' } });
  });
  it('orders and stock', () => {
    assert.deepEqual(p('thibitisha oda 123'), { intent: 'order_status', args: { number: '123', status: 'confirmed' } });
    assert.deepEqual(p('oda 45 iko tayari'), { intent: 'order_status', args: { number: '45', status: 'ready' } });
    assert.deepEqual(p('fungua oda ORD-0012'), { intent: 'order_open', args: { number: 'ORD-0012' } });
    assert.deepEqual(p('mbao zimebaki ngapi'), { intent: 'stock_check', args: { name: 'mbao' } });
    assert.equal(p('mauzo ya leo ni kiasi gani').intent, 'sales_today');
  });
  it('answers the app', () => {
    assert.equal(p('ndiyo', 'confirm').intent, 'yes');
    assert.equal(p('hapana', 'confirm').intent, 'no');
    assert.deepEqual(p('ya pili', 'choice'), { intent: 'choose', args: { index: 1 } });
  });
});

describe('voice commands (English)', () => {
  it('works the same way', () => {
    assert.deepEqual(p('open orders'), { intent: 'orders_list', args: {} });
    assert.deepEqual(p('go to customers'), { intent: 'navigate', args: { screen: 'customers' } });
    assert.deepEqual(p('sell three bags of cement and two nails'), {
      intent: 'sell_add',
      args: { items: [{ name: 'cement', quantity: 3, unit: 'bag' }, { name: 'nails', quantity: 2 }] },
    });
    assert.deepEqual(p('pay cash 20000'), { intent: 'sell_pay', args: { method: 'cash', amount: 20000, reference: undefined } });
    assert.deepEqual(p('expense 5000 for transport'), { intent: 'expense_add', args: { amount: 5000, title: 'transport', method: 'cash' } });
    assert.deepEqual(p('received 20 bags of cement at 18000 from bakhresa'), {
      intent: 'purchase_add',
      args: { items: [{ name: 'cement', quantity: 20, unit: 'bag', unit_cost: 18000 }], supplier: 'bakhresa', paid: undefined },
    });
    assert.deepEqual(p('add customer mary john phone 0755 111 222'), { intent: 'customer_add', args: { name: 'Mary John', phone: '0755111222' } });
    assert.deepEqual(p('how many nails are left'), { intent: 'stock_check', args: { name: 'nails' } });
    assert.equal(p('what is the total').intent, 'sell_total');
    assert.equal(p('total').intent, 'sell_total');
    assert.equal(p('yes', 'confirm').intent, 'yes');
  });
});

it('a list said without "na" or commas, as the phone hears it', () => {
  const c = parseCommand('uza korosho mbili karanga nne whitedent moja castle light tatu maji ya uhai madogo mbili strawberry pc tatu');
  assert.equal(c.intent, 'sell_add');
  if (c.intent !== 'sell_add') return;
  assert.deepEqual(c.args.items.map((i) => [i.name, i.quantity]), [['korosho', 2], ['karanga', 4], ['whitedent', 1], ['castle light', 3], ['maji uhai madogo', 2], ['strawberry', 3]]);
  const n = parseCommand('uza saruji mifuko miwili');
  if (n.intent === 'sell_add') assert.deepEqual([n.args.items[0].name, n.args.items[0].quantity, n.args.items[0].unit], ['saruji', 2, 'bag']);
});
