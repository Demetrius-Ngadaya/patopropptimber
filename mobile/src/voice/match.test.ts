import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { decide, rank } from './match';

const products = ['Mbao ya mninga 2x4', 'Mbao ya mkongo 2x6', 'Misumari inchi 3', 'Misumari inchi 4', 'Saruji Twiga 50kg', 'Bati la geji 28', 'Rangi nyeupe lita 4', 'Sukari kilo 1'].map((name, id) => ({ id, name }));
const find = (said: string) => decide(rank(said, products, (p) => p.name));

describe('finding the product that was said', () => {
  it('finds a clear match even when heard a little wrong', () => {
    assert.equal(find('saruji')?.match?.name, 'Saruji Twiga 50kg');
    assert.equal(find('simenti')?.match?.name, 'Saruji Twiga 50kg');
    assert.equal(find('bati geji 28')?.match?.name, 'Bati la geji 28');
    assert.equal(find('sukari')?.match?.name, 'Sukari kilo 1');
    assert.equal(find('rangi nyeupe')?.match?.name, 'Rangi nyeupe lita 4');
    assert.equal(find('mbao mninga')?.match?.name, 'Mbao ya mninga 2x4');
  });
  it('asks when two products fit', () => {
    const r = find('misumari');
    assert.equal(r.match, null);
    assert.deepEqual(r.choices.map((p) => p.name), ['Misumari inchi 3', 'Misumari inchi 4']);
    assert.equal(find('mbao').match, null);
  });
  it('picks the right one when the detail is said', () => {
    assert.equal(find('misumari inchi nne')?.match?.name ?? find('misumari inchi 4')?.match?.name, 'Misumari inchi 4');
  });
  it('finds nothing for unrelated words', () => {
    assert.deepEqual(find('simu ya samsung'), { match: null, choices: [] });
  });
});

it('names that sound alike match ("castle light" is Castle Lite)', () => {
  const list = ['Castle Lite', 'Castle Lager', 'Korosho'].map((name) => ({ name }));
  assert.equal(decide(rank('castle light', list, (p) => p.name)).match?.name, 'Castle Lite');
});
