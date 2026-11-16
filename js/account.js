/*
 * Brighter Days player accounts (client side).
 * Talks to the WordPress plugin's REST routes (brighter-days/v1). Accounts are
 * optional: when the game is not running inside the plugin, siteConfig()
 * reports accounts as off and the game stays guest-only.
 *
 * The session token lives in its own storage key, never inside the save, so
 * save codes and cloud backups can't leak it.
 */
(function (root, factory) {
  var api = factory(root);
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.BDAccount = api;
})(typeof globalThis !== 'undefined' ? globalThis : (typeof self !== 'undefined' ? self : this), function (root) {
  'use strict';
  var SESSION_KEY = 'bd_session', PLAYER_KEY = 'bd_player', NS = '/brighter-days/v1';
  var PLAYER_RE = /^[A-Za-z0-9_-]{8,64}$/, TOKEN_RE = /^[0-9a-f]{64}$/;

  var ls = null;
  try { ls = root.localStorage || null; } catch (e) { ls = null; }
  function get(k) { try { return ls ? ls.getItem(k) : null; } catch (e) { return null; } }
  function put(k, v) { try { if (!ls) return; if (v == null) ls.removeItem(k); else ls.setItem(k, v); } catch (e) {} }

  function fail(code, message, status) { var e = new Error(message); e.code = code; e.status = status || 0; return e; }

  /** REST base for this page, or null when the game isn't served by the plugin. */
  function apiBase(loc) {
    if (root.BD_API) return String(root.BD_API).replace(/\/$/, '');
    loc = loc || root.location;
    if (!loc || !loc.pathname) return null;
    var i = loc.pathname.indexOf('/wp-content/');
    if (i < 0) return null;
    return loc.origin + loc.pathname.slice(0, i) + '/?rest_route=' + NS;
  }
  var base = null;
  function ensureBase() { if (base === null) base = apiBase() || ''; return base; }

  function call(path, body, method) {
    var b = ensureBase();
    if (!b || typeof fetch !== 'function') return Promise.reject(fail('offline', 'Accounts aren’t available here.'));
    var opts = { method: method || 'POST', credentials: 'omit', cache: 'no-store', headers: {} };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(b + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw fail(String(j.code || 'server').replace(/^bd_/, ''), j.message || 'Something went wrong. Try again.', r.status);
        return j;
      });
    }, function () { throw fail('network', 'Couldn’t reach the server. Check your connection and try again.'); });
  }

  /* ---------- Session and player ID ---------- */

  function loadSession() {
    try {
      var s = JSON.parse(get(SESSION_KEY) || 'null');
      return s && TOKEN_RE.test(s.token) && s.account ? s : null;
    } catch (e) { return null; }
  }
  function saveSession(s) {
    put(SESSION_KEY, s ? JSON.stringify({ token: s.token, account: s.account, saved: s.saved || 0 }) : null);
  }

  function randomHex(bytes) {
    var a = new Uint8Array(bytes), c = root.crypto;
    if (c && c.getRandomValues) c.getRandomValues(a);
    else for (var i = 0; i < bytes; i++) a[i] = Math.floor(Math.random() * 256);
    var out = '';
    for (var j = 0; j < a.length; j++) out += ('0' + a[j].toString(16)).slice(-2);
    return out;
  }
  function newPlayerId() { return 'p_' + randomHex(16); }
  function playerId() {
    var p = get(PLAYER_KEY);
    if (!p || !PLAYER_RE.test(p)) { p = newPlayerId(); put(PLAYER_KEY, p); }
    return p;
  }
  function setPlayerId(p) { if (PLAYER_RE.test(p)) put(PLAYER_KEY, p); }

  function signedIn(j) {
    saveSession({ token: j.token, account: j.account, saved: j.saved || 0 });
    return j;
  }
  function token() { var s = loadSession(); return s ? s.token : ''; }

  /** Calls a route that needs the session; a 401 forgets the session. */
  function authed(path, body) {
    var t = token();
    if (!t) return Promise.reject(fail('signed_out', 'You’re not signed in.'));
    body = body || {};
    body.token = t;
    return call(path, body).catch(function (e) {
      if (e.status === 401 && e.code === 'signed_out') saveSession(null);
      throw e;
    });
  }

  /* ---------- API ---------- */

  function register(email, password, player, save) {
    return call('/account/register', { email: email, password: password, player: player, save: save || null }).then(signedIn);
  }
  function login(email, password) {
    return call('/account/login', { email: email, password: password }).then(signedIn);
  }
  function me() { return authed('/account/me').then(signedIn); }
  function logout() {
    var t = token();
    saveSession(null);
    return t ? call('/account/logout', { token: t }).catch(function () { return { ok: true }; }) : Promise.resolve({ ok: true });
  }
  /** opts: { current, password, email }. The current password is always needed. */
  function setPassword(opts) {
    var body = {};
    if (opts.password) body.password = opts.password;
    if (opts.current) body.current = opts.current;
    if (opts.email != null) body.email = opts.email;
    return authed('/account/password', body).then(signedIn);
  }
  function requestReset(email) { return call('/account/reset-request', { email: email }); }
  function reset(email, code, password) {
    return call('/account/reset', { email: email, code: code, password: password }).then(signedIn);
  }
  function deleteAccount(confirm) {
    return authed('/account/delete', { confirm: confirm }).then(function (j) { saveSession(null); return j; });
  }
  /** Uploads the save. On a conflict the newer server copy comes back for merging. */
  function upload(save) {
    var s = loadSession();
    if (!s) return Promise.reject(fail('signed_out', 'You’re not signed in.'));
    return authed('/account/save', { save: save, base: s.saved || 0 }).then(function (j) {
      var cur = loadSession();
      if (cur && j.saved) { cur.saved = j.saved; saveSession(cur); }
      return j;
    });
  }
  /* ---------- The Exchange ---------- */
  function boardList() { return call('/board', null, 'GET').then(function (j) { return Array.isArray(j.rows) ? j.rows : []; }); }
  /** Lists or updates this player's shop. Needs a signed-in account. */
  function boardPost(entry) { return authed('/board/post', { entry: entry }); }
  function boardDelist() { return authed('/board/delist'); }

  var cfg = null;
  function siteConfig() {
    if (!cfg) {
      cfg = call('/config', null, 'GET').then(function (j) {
        return { enabled: !!j.enabled };
      }, function () { return { enabled: false }; });
    }
    return cfg;
  }

  /* ---------- Saves ---------- */

  function clone(o) { return o == null ? o : JSON.parse(JSON.stringify(o)); }
  function stamp(s) { return (s && typeof s.t === 'number') ? s.t : 0; }
  /** A shop nobody has played yet: still day 1, nothing sold, nothing earned. */
  function blank(s) {
    return (s.day || 1) <= 1 && !(s.stats && s.stats.trades) && !s.served && !Object.keys(s.ach || {}).length;
  }

  /**
   * Merge rule: start from the newer save, then keep the best of both for
   * trophies (earliest day earned), regulars met, and lifetime stats (highest).
   * Coin, stock and the shop itself come from the newer save so the game stays
   * consistent. A new game nobody has played yet never counts as newer than a
   * real shop, so signing in on a new device can't wipe progress.
   */
  function mergeSaves(a, b) {
    var okA = a && typeof a === 'object', okB = b && typeof b === 'object';
    if (!okA && !okB) return null;
    if (!okA) return clone(b);
    if (!okB) return clone(a);
    var bNewer;
    if (blank(a) !== blank(b)) bNewer = blank(a);
    else bNewer = stamp(b) > stamp(a) || (stamp(b) === stamp(a) && (b.day || 0) > (a.day || 0));
    var n = bNewer ? b : a, o = bNewer ? a : b, out = clone(n);
    if (!out.username && o.username) { out.username = o.username; out.ticker = o.ticker || out.ticker; }
    out.ach = {};
    [o.ach || {}, n.ach || {}].forEach(function (src) {
      Object.keys(src).forEach(function (k) {
        var d = src[k];
        if (out.ach[k] == null) out.ach[k] = d;
        else if (typeof d === 'number' && typeof out.ach[k] === 'number') out.ach[k] = Math.min(out.ach[k], d);
      });
    });
    out.met = Object.assign({}, o.met || {}, n.met || {});
    out.stats = Object.assign({}, n.stats || {});
    Object.keys(o.stats || {}).forEach(function (k) {
      var x = o.stats[k], y = out.stats[k];
      if (typeof x === 'number' && (typeof y !== 'number' || x > y)) out.stats[k] = x;
    });
    return out;
  }

  /**
   * After signing in: merge this device's progress with the account's save and
   * take on the account's player ID. profile = { player, save }.
   */
  function adopt(profile, payload) {
    var acc = (payload && payload.account) || {};
    return {
      player: PLAYER_RE.test(acc.player || '') ? acc.player : profile.player,
      save: mergeSaves(profile.save, payload ? payload.save : null)
    };
  }

  /** After signing out or deleting: keep progress, but as a new separate player. */
  function detach(profile, newId) {
    return { player: newId && PLAYER_RE.test(newId) ? newId : newPlayerId(), save: clone(profile.save) };
  }

  return {
    apiBase: apiBase, loadSession: loadSession, saveSession: saveSession,
    playerId: playerId, setPlayerId: setPlayerId, newPlayerId: newPlayerId,
    register: register, login: login, me: me, logout: logout, setPassword: setPassword, requestReset: requestReset, reset: reset,
    deleteAccount: deleteAccount, upload: upload, siteConfig: siteConfig,
    boardList: boardList, boardPost: boardPost, boardDelist: boardDelist,
    mergeSaves: mergeSaves, adopt: adopt, detach: detach
  };
});
