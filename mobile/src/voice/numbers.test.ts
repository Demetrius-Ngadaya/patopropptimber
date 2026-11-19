import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { parseNumber, tokenize } from './numbers';

describe('spoken numbers', () => {
  const cases: [string, number][] = [
    ['tano', 5],
    ['kumi na tano', 15],
    ['ishirini na moja', 21],
    ['mia tano', 500],
    ['mia moja na hamsini', 150],
    ['elfu kumi', 10000],
    ['elfu kumi na tano', 15000],
    ['elfu mbili mia tano', 2500],
    ['elfu mbili na mia tano', 2500],
    ['elfu mia tano', 500000],
    ['laki moja', 100000],
    ['laki moja na elfu ishirini', 120000],
    ['milioni moja', 1000000],
    ['nusu', 0.5],
    ['moja na nusu', 1.5],
    ['10 elfu', 10000],
    ['10,000', 10000],
    ['Tsh 5000', 5000],
    ['2.5', 2.5],
    ['10k', 10000],
    ['three', 3],
    ['twenty five', 25],
    ['two thousand five hundred', 2500],
    ['one hundred and fifty', 150],
    ['a dozen', 12],
    ['one and a half', 1.5],
  ];
  for (const [text, value] of cases) {
    it(`${text} → ${value}`, () => assert.equal(parseNumber(text), value));
  }

  it('keeps digits whole and drops punctuation', () => {
    assert.deepEqual(tokenize('Uza mbao 3, na misumari 10,000!'), ['uza', 'mbao', '3', 'na', 'misumari', '10000']);
  });
});
