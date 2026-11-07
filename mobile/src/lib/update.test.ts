import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { isNewer, shouldShowCard } from './update';

describe('app update check', () => {
  it('offers only a higher build than the one installed', () => {
    assert.equal(isNewer({ build_number: 5 }, 4), true);
    assert.equal(isNewer({ build_number: 4 }, 4), false);
    assert.equal(isNewer({ build_number: 3 }, 4), false);
    assert.equal(isNewer(null, 4), false);
  });

  it('never nags a development build whose number is unknown', () => {
    assert.equal(isNewer({ build_number: 9 }, 0), false);
  });

  it('hides a dismissed optional update until a newer one comes, but never a required one', () => {
    assert.equal(shouldShowCard({ build_number: 5, is_required: false }, 5), false);
    assert.equal(shouldShowCard({ build_number: 6, is_required: false }, 5), true);
    assert.equal(shouldShowCard({ build_number: 5, is_required: true }, 5), true);
  });
});
