import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { extractData, routeFor } from './links';

describe('notification links', () => {
  it('opens the exact screen for the record the notification is about', () => {
    assert.equal(routeFor({ url: '/admin/orders/12', notification_id: 5 }), '/orders/12');
    assert.equal(routeFor({ url: '/admin/products/7' }), '/products/7');
    assert.equal(routeFor({ url: '/admin/sales/90' }), '/sales/s:90');
    assert.equal(routeFor({ url: '/admin/customers/3?tab=debts' }), '/customers/s:3');
  });

  it('falls back to the notification itself', () => {
    assert.equal(routeFor({ url: '/admin/hr/leave', notification_id: 44 }), '/notifications/44');
    assert.equal(routeFor({ url: null }), '/notifications');
  });

  it('reads the payload wherever Expo put it', () => {
    assert.equal(extractData({ dataString: '{"url":"/admin/orders/1"}' }).url, '/admin/orders/1');
    assert.equal(extractData({ body: '{"notification_id":3}' }).notification_id, 3);
    assert.equal(extractData({ data: { url: '/x' } }).url, '/x');
    assert.deepEqual(extractData(null), {});
  });
});
