/*
 * Local mock of the plugin's account API, for trying the game and running the
 * JS tests without a WordPress site. Never point tests at the live site.
 *
 *   node tests/mock-server.js            then open
 *   http://localhost:8790/wp-content/plugins/brighter-days/game/
 *
 * Reset codes are printed to the console instead of emailed. Data is in memory
 * only. Mirrors the rules in wordpress-plugin/brighter-days/includes/accounts.php.
 */
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');
const GAME = '/wp-content/plugins/brighter-days/game/';
const SAVE_MAX = 300 * 1024;
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.png': 'image/png', '.gif': 'image/gif', '.txt': 'text/plain; charset=utf-8', '.json': 'application/json' };

function createState() {
  return { accounts: new Map(), nextId: 1, sessions: new Map(), resets: new Map(), tries: new Map(), board: new Map(), lastCode: null };
}

function err(code, message, status) { return { status: status || 400, body: { code: 'bd_' + code, message } }; }
const ok = (body) => ({ status: 200, body });
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
const hashPass = (p) => { const salt = crypto.randomBytes(8).toString('hex'); return salt + ':' + crypto.scryptSync(p, salt, 32).toString('hex'); };
const checkPass = (p, h) => { if (!h) return false; const [salt, x] = h.split(':'); return crypto.timingSafeEqual(Buffer.from(x, 'hex'), crypto.scryptSync(p, salt, 32)); };
const cleanEmail = (e) => { e = String(e || '').trim().toLowerCase(); return e.length <= 190 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) ? e : ''; };
const cleanPlayer = (p) => (/^[A-Za-z0-9_-]{8,64}$/.test(String(p || '')) ? String(p) : '');
const passOk = (p) => typeof p === 'string' && p.length >= 8 && p.length <= 200;
const now = () => Math.floor(Date.now() / 1000);
const boardId = (pid) => crypto.createHmac('sha256', 'mock-salt').update(pid).digest('hex').slice(0, 16);

function api(st, config) {
  const byEmail = (e) => [...st.accounts.values()].find((a) => a.email === e) || null;
  const byPlayer = (p) => [...st.accounts.values()].find((a) => a.player_id === p) || null;
  const out = (a) => ({ email: a.email, player: a.player_id, created: a.created });
  const signedIn = (a, token) => ok({ token, account: out(a), save: a.save ? JSON.parse(a.save) : null, saved: a.save_updated });
  const session = (id) => { const t = crypto.randomBytes(32).toString('hex'); st.sessions.set(sha(t), { account_id: id, expires: now() + 120 * 86400 }); st.accounts.get(id).last_login = now(); return t; };
  const auth = (p) => {
    const t = String(p.token || '');
    const gone = err('signed_out', "You've been signed out. Sign in again.", 401);
    if (!/^[0-9a-f]{64}$/.test(t)) return { e: gone };
    const s = st.sessions.get(sha(t));
    if (!s || s.expires < now() || !st.accounts.get(s.account_id)) return { e: gone };
    return { a: st.accounts.get(s.account_id), t, h: sha(t) };
  };
  const cleanSave = (s) => { if (s == null || s === '') return [true, null]; if (typeof s !== 'object') return [false, null]; const j = JSON.stringify(s); return j.length > SAVE_MAX ? [false, null] : [true, j]; };
  const limited = (k) => (st.tries.get(k) || 0) >= 10;
  const count = (k) => st.tries.set(k, (st.tries.get(k) || 0) + 1);
  const add = (row) => { const id = st.nextId++; st.accounts.set(id, Object.assign({ id, created: now(), last_login: now(), save: null, save_updated: 0 }, row)); return st.accounts.get(id); };

  return {
    config: () => ok({ enabled: !!config.enabled }),
    board: () => ok({ rows: [...st.board.entries()].map(([pid, r]) => Object.assign({}, r.data, { id: boardId(pid), updatedAt: r.updated })).sort((a, b) => b.worth - a.worth).slice(0, 300) }),
    'board/post': (p) => {
      const me = auth(p); if (me.e) return me.e;
      const e = p.entry;
      if (!e || typeof e !== 'object') return err('entry', "That listing isn't valid.");
      const name = String(e.name || '').trim().replace(/\s+/g, ' ');
      if (name.length < 2 || name.length > 16 || !/^[A-Za-z0-9 _'.-]+$/.test(name)) return err('name', 'Choose a shopkeeper name of 2 to 16 letters.');
      const n = (v, lo, hi) => Math.max(lo, Math.min(hi, Math.round(Number(v) || 0)));
      const B = 1e12;
      const data = { name, ticker: String(e.ticker || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5), worth: n(e.worth, -B, B), prev: n(e.prev, -B, B), purse: n(e.purse, -B, B), day: n(e.day, 1, 1e6), level: n(e.level, 1, 1e4), rep: n(e.rep, 0, 100), trades: n(e.trades, 0, B), thieves: n(e.thieves, 0, B), crooks: n(e.crooks, 0, B), best: n(e.best, -B, B), tro: n(e.tro, 0, 999), guild: !!e.guild };
      st.board.set(me.a.player_id, { data, updated: now() });
      return ok({ ok: true, id: boardId(me.a.player_id) });
    },
    'board/delist': (p) => { const me = auth(p); if (me.e) return me.e; st.board.delete(me.a.player_id); return ok({ ok: true }); },
    'account/register': (p) => {
      const email = cleanEmail(p.email);
      if (!email) return err('email', 'Enter a valid email address.');
      if (!passOk(p.password)) return err('password', 'Use at least 8 characters for your password.');
      const player = cleanPlayer(p.player);
      if (!player) return err('player', 'This device has no player ID yet. Reload the game and try again.');
      if (byEmail(email)) return err('email_taken', 'That email already has an account. Sign in instead.', 409);
      if (byPlayer(player)) return err('player_taken', 'This player already belongs to an account. Sign in to that account instead.', 409);
      const [good, save] = cleanSave(p.save);
      if (!good) return err('save_size', 'Your save is too large to back up.', 413);
      const a = add({ email, pass_hash: hashPass(p.password), player_id: player, save, save_updated: save ? now() : 0 });
      return signedIn(a, session(a.id));
    },
    'account/login': (p) => {
      const email = cleanEmail(p.email);
      if (!email || !p.password) return err('login', 'Enter your email and password.');
      if (limited('login|' + email)) return err('slow_down', 'Too many tries. Wait 15 minutes and try again.', 429);
      const a = byEmail(email);
      if (!a || !checkPass(String(p.password), a.pass_hash)) { count('login|' + email); return err('login', "That email and password don't match.", 401); }
      st.tries.delete('login|' + email);
      return signedIn(a, session(a.id));
    },
    'account/me': (p) => { const me = auth(p); return me.e || signedIn(me.a, me.t); },
    'account/logout': (p) => { const me = auth(p); if (!me.e) st.sessions.delete(me.h); return ok({ ok: true }); },
    'account/delete': (p) => {
      const me = auth(p); if (me.e) return me.e;
      if (p.confirm !== 'DELETE') return err('confirm', 'Type DELETE to confirm.');
      for (const [k, v] of st.sessions) if (v.account_id === me.a.id) st.sessions.delete(k);
      st.board.delete(me.a.player_id);
      st.accounts.delete(me.a.id);
      return ok({ ok: true });
    },
    'account/password': (p) => {
      const me = auth(p); if (me.e) return me.e;
      if (!checkPass(String(p.current || ''), me.a.pass_hash)) return err('current', "Your current password isn't right.", 401);
      const row = {};
      if (p.password) { if (!passOk(p.password)) return err('password', 'Use at least 8 characters for your password.'); row.pass_hash = hashPass(p.password); }
      if (p.email != null) {
        const e = cleanEmail(p.email);
        if (e !== me.a.email) {
          if (!e) return err('email', 'Enter a valid email address.');
          const o = byEmail(e); if (o && o.id !== me.a.id) return err('email_taken', 'That email already has an account.', 409);
          row.email = e;
        }
      }
      if (!Object.keys(row).length) return err('nothing', 'Nothing to change.');
      Object.assign(me.a, row);
      if (row.pass_hash) for (const [k, v] of st.sessions) if (v.account_id === me.a.id && k !== me.h) st.sessions.delete(k);
      return signedIn(me.a, me.t);
    },
    'account/reset-request': (p) => {
      const email = cleanEmail(p.email);
      if (!email) return err('email', 'Enter a valid email address.');
      if (byEmail(email)) {
        const code = String(crypto.randomInt(0, 1000000)).padStart(6, '0');
        st.resets.set(email, { code, tries: 0, exp: now() + 1800 });
        st.lastCode = code;
        if (config.log) console.log('[mock] reset code for ' + email + ': ' + code);
      }
      return ok({ ok: true });
    },
    'account/reset': (p) => {
      const email = cleanEmail(p.email), code = String(p.code || '').replace(/\D/g, '');
      if (!email || code.length !== 6) return err('code', 'Enter your email and the 6-digit code.');
      if (!passOk(p.password)) return err('password', 'Use at least 8 characters for your password.');
      const r = st.resets.get(email);
      if (!r || r.exp < now()) return err('code', 'That code has expired. Ask for a new one.', 401);
      if (r.code !== code) { r.tries++; if (r.tries >= 5) { st.resets.delete(email); return err('code', 'Too many wrong codes. Ask for a new one.', 429); } return err('code', "That code isn't right.", 401); }
      st.resets.delete(email);
      const a = byEmail(email); if (!a) return err('code', 'That code has expired. Ask for a new one.', 401);
      a.pass_hash = hashPass(p.password);
      for (const [k, v] of st.sessions) if (v.account_id === a.id) st.sessions.delete(k);
      return signedIn(a, session(a.id));
    },
    'account/save': (p) => {
      const me = auth(p); if (me.e) return me.e;
      const [good, save] = cleanSave(p.save);
      if (!good || !save) return err('save_size', 'Your save is too large to back up.', 413);
      const base = Number(p.base) || 0;
      if (me.a.save_updated > base && me.a.save) return ok({ conflict: true, save: JSON.parse(me.a.save), saved: me.a.save_updated });
      me.a.save = save; me.a.save_updated = Math.max(now(), base + 1);
      return ok({ saved: me.a.save_updated });
    }
  };
}

function start(opts) {
  opts = Object.assign({ port: 8790, enabled: true, log: false }, opts || {});
  const st = createState();
  const routes = api(st, opts);
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const rr = url.searchParams.get('rest_route');
    if (rr) {
      const m = /^\/brighter-days\/v1\/(.+)$/.exec(rr);
      const h = m && routes[m[1]];
      const send = (r) => { res.writeHead(r.status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(r.body)); };
      if (!h) return send(err('no_route', 'No route was found.', 404));
      const isGet = m[1] === 'config' || m[1] === 'board';
      if (isGet !== (req.method === 'GET')) return send(err('method', 'Wrong method.', 405));
      let raw = '';
      req.on('data', (c) => { raw += c; if (raw.length > 2e6) req.destroy(); });
      req.on('end', () => {
        let body = {};
        try { body = raw ? JSON.parse(raw) : {}; } catch (e) { body = {}; }
        if (!isGet && !opts.enabled) return send(err('off', 'Accounts are switched off on this site.', 403));
        send(h(body || {}));
      });
      return;
    }
    if (url.pathname === '/' || url.pathname === GAME.slice(0, -1)) { res.writeHead(302, { Location: GAME }); return res.end(); }
    if (!url.pathname.startsWith(GAME)) { res.writeHead(404); return res.end('Not found'); }
    let rel = decodeURIComponent(url.pathname.slice(GAME.length)) || 'index.html';
    const file = path.resolve(ROOT, rel);
    if (!file.startsWith(ROOT + path.sep) || /(^|[\\/])(tests|wordpress-plugin|\.git|\.github)([\\/]|$)/.test(path.relative(ROOT, file))) { res.writeHead(404); return res.end('Not found'); }
    fs.readFile(file, (e, data) => {
      if (e) { res.writeHead(404); return res.end('Not found'); }
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
      res.end(data);
    });
  });
  return new Promise((resolve) => server.listen(opts.port, '127.0.0.1', () => resolve({ server, state: st, port: server.address().port })));
}

module.exports = { start };

if (require.main === module) {
  const port = Number(process.env.PORT) || 8790;
  start({ port, log: true }).then(({ port: p }) => console.log('Mock account server: http://localhost:' + p + GAME));
}
