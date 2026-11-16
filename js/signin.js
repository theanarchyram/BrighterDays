/*
 * Brighter Days: getting an ID token from Google, Apple or Microsoft in the
 * browser. The plugin verifies every token itself; nothing here is trusted.
 *  - Google: the official Google Identity Services button.
 *  - Apple: Sign in with Apple JS in popup mode.
 *  - Microsoft: a popup to the Microsoft identity platform that returns an ID
 *    token to auth-callback.html, which hands it back to the game.
 */
(function (root) {
  'use strict';
  var SDK = {
    google: 'https://accounts.google.com/gsi/client',
    apple: 'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js'
  };
  var loading = {};
  function load(src) {
    if (loading[src]) return loading[src];
    loading[src] = new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = src; s.async = true;
      s.onload = function () { res(); };
      s.onerror = function () { delete loading[src]; rej(fail('load', 'Couldn’t load the sign-in button. Check your connection.')); };
      document.head.appendChild(s);
    });
    return loading[src];
  }
  function fail(code, message) { var e = new Error(message); e.code = code; return e; }
  function rand() {
    var a = new Uint8Array(16); root.crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function claims(jwt) {
    try {
      var p = String(jwt).split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      return JSON.parse(decodeURIComponent(escape(atob(p))));
    } catch (e) { return {}; }
  }
  function pageUrl() { return location.origin + location.pathname; }

  /** Apple and Microsoft need HTTPS and a popup; Google needs nothing special. */
  function can(method) {
    if (method === 'google') return true;
    if (method === 'apple' || method === 'microsoft') return location.protocol === 'https:' || location.hostname === 'localhost';
    return false;
  }

  /** Loads SDKs early so the popup opens straight from the player's click. */
  function preload(methods) {
    methods = methods || {};
    if (methods.google) load(SDK.google).catch(function () {});
    if (methods.apple && can('apple')) load(SDK.apple).catch(function () {});
  }

  /** Renders Google's own button into el. onToken(idToken) runs after sign-in. */
  function googleButton(el, clientId, onToken, onError) {
    load(SDK.google).then(function () {
      var g = root.google && root.google.accounts && root.google.accounts.id;
      if (!g) throw fail('load', 'Couldn’t load the sign-in button.');
      g.initialize({
        client_id: clientId,
        ux_mode: 'popup',
        callback: function (r) { if (r && r.credential) onToken(r.credential); else if (onError) onError(fail('cancelled', 'Sign-in was cancelled.')); }
      });
      el.innerHTML = '';
      g.renderButton(el, { type: 'standard', theme: 'filled_black', size: 'large', text: 'continue_with', shape: 'rectangular', logo_alignment: 'center', width: Math.max(200, Math.min(400, el.clientWidth || 300)) });
    }).catch(function (e) { if (onError) onError(e); });
  }

  function apple(servicesId) {
    var A = root.AppleID;
    if (!A || !A.auth) return load(SDK.apple).then(function () { return apple(servicesId); });
    var nonce = rand();
    A.auth.init({ clientId: servicesId, scope: 'email', redirectURI: pageUrl(), state: rand(), nonce: nonce, usePopup: true });
    return A.auth.signIn().then(function (r) {
      var t = r && r.authorization && r.authorization.id_token;
      if (!t) throw fail('failed', 'Sign-in didn’t work. Try again.');
      if (claims(t).nonce && claims(t).nonce !== nonce) throw fail('failed', 'Sign-in didn’t work. Try again.');
      return t;
    }, function (e) {
      var c = e && e.error;
      throw fail(c === 'popup_closed_by_user' || c === 'user_cancelled_authorize' ? 'cancelled' : 'failed', c === 'popup_closed_by_user' || c === 'user_cancelled_authorize' ? 'Sign-in was cancelled.' : 'Sign-in didn’t work. Try again.');
    });
  }

  function microsoft(clientId) {
    var nonce = rand(), state = rand();
    var redirect = new URL('auth-callback.html', location.href).href.split('#')[0].split('?')[0];
    var q = new URLSearchParams({
      client_id: clientId, response_type: 'id_token', redirect_uri: redirect,
      scope: 'openid email profile', response_mode: 'fragment', nonce: nonce, state: state, prompt: 'select_account'
    });
    var w = root.open('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' + q.toString(), 'bd_ms_signin', 'width=480,height=640');
    if (!w) return Promise.reject(fail('popup', 'Your browser blocked the sign-in window. Allow popups and try again.'));
    return new Promise(function (resolve, reject) {
      var done = false, bc = null, timer;
      function finish() {
        done = true; clearInterval(timer);
        root.removeEventListener('message', onMsg);
        if (bc) try { bc.close(); } catch (e) {}
        try { w.close(); } catch (e) {}
      }
      function handle(data) {
        if (done || !data || data.bdAuth !== 1) return;
        finish();
        var p = new URLSearchParams(String(data.hash || '').replace(/^#/, ''));
        if (p.get('state') !== state) return reject(fail('failed', 'Sign-in didn’t work. Try again.'));
        if (p.get('error')) return reject(fail(p.get('error') === 'access_denied' ? 'cancelled' : 'failed', p.get('error') === 'access_denied' ? 'Sign-in was cancelled.' : 'Sign-in didn’t work. Try again.'));
        var t = p.get('id_token');
        if (!t || claims(t).nonce !== nonce) return reject(fail('failed', 'Sign-in didn’t work. Try again.'));
        resolve(t);
      }
      function onMsg(e) { if (e.origin === location.origin) handle(e.data); }
      root.addEventListener('message', onMsg);
      if (root.BroadcastChannel) { bc = new BroadcastChannel('bd_auth'); bc.onmessage = function (e) { handle(e.data); }; }
      timer = setInterval(function () {
        var closed = false;
        try { closed = w.closed; } catch (e) {}
        if (closed && !done) setTimeout(function () { if (!done) { finish(); reject(fail('cancelled', 'Sign-in was cancelled.')); } }, 800);
      }, 500);
    });
  }

  /** Popup sign-in for Apple or Microsoft; resolves with the ID token. */
  function signIn(method, clientId) {
    if (method === 'apple') return apple(clientId);
    if (method === 'microsoft') return microsoft(clientId);
    return Promise.reject(fail('method', 'That sign-in method isn’t available.'));
  }

  root.BDSignin = { can: can, preload: preload, googleButton: googleButton, signIn: signIn };
})(typeof self !== 'undefined' ? self : this);
