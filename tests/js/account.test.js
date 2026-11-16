'use strict';
// Run with: node --test tests/js/*.test.js
const test = require('node:test');
const assert = require('node:assert/strict');

// A fresh in-memory localStorage for account.js.
const mem = new Map();
Object.defineProperty(globalThis, 'localStorage', {
  configurable: true,
  value: { getItem: (k) => (mem.has(k) ? mem.get(k) : null), setItem: (k, v) => mem.set(k, String(v)), removeItem: (k) => mem.delete(k) }
});

const { start } = require('../mock-server.js');
let srv, A;

test.before(async () => {
  srv = await start({ port: 0 });
  globalThis.BD_API = 'http://127.0.0.1:' + srv.port + '/?rest_route=/brighter-days/v1';
  A = require('../../js/account.js');
});
test.after(() => srv.server.close());
test.beforeEach(() => mem.clear());

const save = (t, day, extra) => Object.assign({ v: 1, t, day, purse: day * 10, ach: {}, met: {}, stats: { trades: 0, best: 0 } }, extra || {});

/* ---------- Merge, adopt, detach ---------- */

test('merge starts from the newer save', () => {
  const older = save(100, 5, { purse: 999 });
  const newer = save(200, 3, { purse: 30 });
  assert.equal(A.mergeSaves(older, newer).purse, 30);
  assert.equal(A.mergeSaves(newer, older).purse, 30);
});

test('a brand-new unplayed game never beats a real shop', () => {
  const real = save(100, 12, { username: 'Tess', ticker: 'TESS', stats: { trades: 40, best: 9 } });
  const fresh = Object.assign(save(999999, 1), { username: '', ticker: '' });
  const m = A.mergeSaves(fresh, real);
  assert.equal(m.day, 12);
  assert.equal(m.username, 'Tess');
  assert.equal(A.mergeSaves(real, fresh).day, 12);
});

test('the newer shop keeps the name from the other save if it has none', () => {
  const named = save(100, 1, { username: 'Tess', ticker: 'TESS' });
  const playedUnnamed = save(200, 5, { username: '', stats: { trades: 3 } });
  const m = A.mergeSaves(named, playedUnnamed);
  assert.equal(m.day, 5);
  assert.equal(m.username, 'Tess');
});

test('merge breaks a timestamp tie by the later day', () => {
  assert.equal(A.mergeSaves(save(100, 2), save(100, 9)).day, 9);
});

test('merge keeps every trophy at its earliest day', () => {
  const a = save(100, 10, { ach: { first: 2, rich: 9 } });
  const b = save(200, 12, { ach: { first: 4, thief: 11 } });
  assert.deepEqual(A.mergeSaves(a, b).ach, { first: 2, rich: 9, thief: 11 });
});

test('merge keeps the best lifetime stats and all regulars met', () => {
  const a = save(300, 10, { stats: { trades: 50, best: 120, thieves: 3 }, met: { anna: 1 } });
  const b = save(100, 20, { stats: { trades: 80, best: 90 }, met: { bram: 2 } });
  const m = A.mergeSaves(a, b);
  assert.deepEqual(m.stats, { trades: 80, best: 120, thieves: 3 });
  assert.deepEqual(m.met, { anna: 1, bram: 2 });
  assert.equal(m.day, 10, 'shop state comes from the newer save');
});

test('merge handles a missing side and does not mutate inputs', () => {
  const a = save(1, 1, { ach: { x: 1 } });
  const copy = JSON.parse(JSON.stringify(a));
  assert.deepEqual(A.mergeSaves(a, null), a);
  assert.deepEqual(A.mergeSaves(null, a), a);
  assert.equal(A.mergeSaves(null, null), null);
  A.mergeSaves(a, save(2, 2, { ach: { y: 2 } }));
  assert.deepEqual(a, copy);
});

test('adopt takes the account player ID and merges', () => {
  const p = A.adopt({ player: 'p_device0001', save: save(500, 4, { ach: { a: 1 } }) }, { account: { player: 'p_account0001' }, save: save(100, 9, { ach: { b: 3 } }) });
  assert.equal(p.player, 'p_account0001');
  assert.equal(p.save.day, 4);
  assert.deepEqual(p.save.ach, { a: 1, b: 3 });
});

test('adopt keeps the device save when the account has none', () => {
  const s = save(5, 5);
  assert.deepEqual(A.adopt({ player: 'p_device0001', save: s }, { account: { player: 'p_account0001' }, save: null }).save, s);
});

test('detach keeps progress under a new player ID', () => {
  const s = save(5, 5);
  const d = A.detach({ player: 'p_account0001', save: s });
  assert.notEqual(d.player, 'p_account0001');
  assert.match(d.player, /^p_[0-9a-f]{32}$/);
  assert.deepEqual(d.save, s);
  assert.equal(A.detach({ player: 'x', save: s }, 'p_given000001').player, 'p_given000001');
});

test('player ID is created once and kept', () => {
  const p = A.playerId();
  assert.match(p, /^p_[0-9a-f]{32}$/);
  assert.equal(A.playerId(), p);
});

/* ---------- Flows against the mock server ---------- */

test('config says accounts are on, with no other sign-in methods', async () => {
  assert.deepEqual(await A.siteConfig(), { enabled: true });
  assert.equal(A.signInWith, undefined);
  assert.equal(A.unlink, undefined);
});

test('accounts are off when the game is not served by the plugin', () => {
  const saved = globalThis.BD_API;
  delete globalThis.BD_API;
  assert.equal(A.apiBase({ origin: 'https://theanarchyram.github.io', pathname: '/BrighterDays/' }), null);
  assert.equal(A.apiBase({ origin: 'https://alfredwill.com', pathname: '/wp-content/plugins/brighter-days/game/' }), 'https://alfredwill.com/?rest_route=/brighter-days/v1');
  globalThis.BD_API = saved;
});

test('register, upload, sign in elsewhere, conflict, sign out', async () => {
  const s1 = save(1000, 3);
  const r = await A.register('Shop@Example.com', 'longenough', A.playerId(), s1);
  assert.match(r.token, /^[0-9a-f]{64}$/);
  assert.equal(r.account.email, 'shop@example.com');
  assert.deepEqual(Object.keys(r.account).sort(), ['created', 'email', 'player']);
  const stored = JSON.parse(mem.get('bd_session'));
  assert.equal(stored.token, r.token);
  assert.ok(!JSON.stringify(s1).includes(r.token), 'token never goes into the save');

  // Same player can't be claimed twice.
  await assert.rejects(A.register('other@example.com', 'longenough', r.account.player, null), { code: 'player_taken' });
  await assert.rejects(A.register('shop@example.com', 'longenough', 'p_somethingnew1', null), { code: 'email_taken' });

  // Second device signs in and uploads.
  const firstSession = mem.get('bd_session');
  mem.clear();
  const r2 = await A.login('shop@example.com', 'longenough');
  assert.equal(r2.account.player, r.account.player);
  assert.equal(r2.save.day, 3);
  const up = await A.upload(save(2000, 6));
  assert.ok(up.saved > 0);

  // First device is now behind: its upload gets the newer copy back.
  mem.clear();
  mem.set('bd_session', firstSession);
  const c = await A.upload(save(1500, 4));
  assert.equal(c.conflict, true);
  assert.equal(c.save.day, 6);
  const retry = await A.upload(A.mergeSaves(save(1500, 4), c.save));
  assert.ok(retry.saved > 0 && !retry.conflict);

  await A.logout();
  assert.equal(A.loadSession(), null);
  await assert.rejects(A.me(), { code: 'signed_out' });
});

test('wrong password, then lockout after 10 tries', async () => {
  await A.register('lock@example.com', 'rightpass1', 'p_lockplayer01', null);
  await assert.rejects(A.login('lock@example.com', 'nope-nope'), { code: 'login' });
  for (let i = 0; i < 9; i++) await A.login('lock@example.com', 'nope-nope').catch(() => {});
  await assert.rejects(A.login('lock@example.com', 'rightpass1'), { code: 'slow_down' });
});

test('password reset signs out every other device', async () => {
  await A.register('reset@example.com', 'firstpass1', 'p_resetplayer1', null);
  const old = A.loadSession();
  await A.requestReset('reset@example.com');
  const code = srv.state.lastCode;
  await assert.rejects(A.reset('reset@example.com', code === '000000' ? '111111' : '000000', 'secondpass1'), { code: 'code' });
  const r = await A.reset('reset@example.com', code, 'secondpass1');
  assert.ok(r.token && r.token !== old.token);
  mem.set('bd_session', JSON.stringify(old));
  await assert.rejects(A.me(), { code: 'signed_out' });
  mem.clear();
  await assert.rejects(A.login('reset@example.com', 'firstpass1'), { code: 'login' });
  assert.ok((await A.login('reset@example.com', 'secondpass1')).token);
});

test('changing email and password needs the current password', async () => {
  await A.register('change@example.com', 'firstpass1', 'p_changeplay1', null);
  const here = A.loadSession();
  mem.clear();
  await A.login('change@example.com', 'firstpass1');
  const other = A.loadSession();
  mem.set('bd_session', JSON.stringify(here));
  await assert.rejects(A.setPassword({ password: 'secondpass1' }), { code: 'current' });
  await assert.rejects(A.setPassword({ password: 'secondpass1', current: 'wrong' }), { code: 'current' });
  const r = await A.setPassword({ current: 'firstpass1', email: 'moved@example.com', password: 'secondpass1' });
  assert.equal(r.account.email, 'moved@example.com');
  assert.equal(A.loadSession().account.email, 'moved@example.com');
  mem.set('bd_session', JSON.stringify(other));
  await assert.rejects(A.me(), { code: 'signed_out' });
  mem.clear();
  assert.ok((await A.login('moved@example.com', 'secondpass1')).token);
});

test('delete needs DELETE and removes the account', async () => {
  await A.register('gone@example.com', 'deleteme1', 'p_goneplayer01', null);
  await assert.rejects(A.deleteAccount('delete'), { code: 'confirm' });
  await A.deleteAccount('DELETE');
  assert.equal(A.loadSession(), null);
  await assert.rejects(A.login('gone@example.com', 'deleteme1'), { code: 'login' });
});
