import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { levelFor, quote } from './pricing';

const sugar = { selling_price: 3000, wholesale_price: 2700, wholesale_min_qty: 10 };

describe('offline pricing (same rules as the server)', () => {
  it('uses the retail price below the wholesale minimum', () => {
    assert.deepEqual(quote(sugar, null, 3, 'retail', true), { price: 3000, level: 'retail', factor: 1, baseQuantity: 3 });
  });

  it('switches to wholesale at the minimum when the business allows it', () => {
    assert.equal(quote(sugar, null, 10, 'retail', true).price, 2700);
    assert.equal(quote(sugar, null, 10, 'retail', false).price, 3000);
  });

  it('prices pack units from their own price, or base price × pack size for wholesale', () => {
    const carton = { factor: 12, retail_price: 34000, wholesale_price: null };
    const q = quote(sugar, carton, 1, 'retail', true);
    assert.equal(q.baseQuantity, 12);
    assert.equal(q.level, 'wholesale');
    assert.equal(q.price, 2700 * 12);
    assert.equal(quote(sugar, carton, 1, 'retail', false).price, 34000);
  });

  it('falls back to retail when there is no wholesale price', () => {
    assert.deepEqual(quote({ selling_price: 500, wholesale_price: null, wholesale_min_qty: null }, null, 50, 'wholesale', true).level, 'retail');
  });

  it('gives wholesale customers wholesale prices', () => {
    assert.equal(levelFor('wholesale', null), 'wholesale');
    assert.equal(levelFor('retail', null), 'retail');
    assert.equal(levelFor('wholesale', 'retail'), 'retail');
  });
});
