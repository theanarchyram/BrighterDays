# Player accounts

Brighter Days can be played without an account. Accounts are optional and run entirely inside the WordPress plugin: the plugin stores them, verifies every sign-in itself, and keeps a backup of each player's shop. Accounts only work when the game is played from the plugin (the `[brighter_days]` shortcode or its full-screen link). The GitHub Pages copy stays guest-only.

## How it works

- Every device has an anonymous random player ID and a local save. An account **owns exactly one player ID** and keeps a server copy of the save.
- **Signing in on a new device** merges that device's shop with the account's save, then the device takes on the account's player ID.
  - Merge rule: start from the newer save (by when it was last saved).
  - Then keep the best of both: every trophy at the earliest day it was earned, every regular met, and the highest lifetime stats.
  - Coin, stock and the shop itself come from the newer save so the game stays consistent.
- **Signing out or deleting the account** keeps the shop on the device, but the device becomes a new, separate player ID.
- A player ID that already belongs to an account can't be claimed by a second account ("Sign in to that account instead").
- **Backups:** while a player is signed in, the game backs up the shop about every 20 seconds of play and when the tab is hidden. If another device saved since, the server sends its newer copy back instead of overwriting. The game merges the two and tries again.

## Sign-in methods

A button only shows when its setting is filled in **and** the device can use it.

| Method | Where | Plugin setting (Settings > Brighter Days) |
|---|---|---|
| Email and password | everywhere | always on while accounts are on |
| Google | web | Google client IDs: web client ID first, then any iOS or Android client IDs |
| Sign in with Apple | web (HTTPS) | Sign in with Apple: Services ID first, then any iOS bundle IDs |
| Microsoft | web (HTTPS) | Microsoft client IDs: Application (client) ID |

Game Center and Play Games are for the iOS and Android apps. Brighter Days has no apps yet, so they aren't built. The extra IDs in the Google and Apple fields are already accepted as valid audiences, ready for when an app exists.

While signed in, players can link more methods and remove them (Menu > Account > Manage). The plugin refuses:

- linking a method that is already linked to another account;
- removing the last way to sign in.

## Setting up each provider

### Turn accounts on
1. In WordPress, go to **Settings > Brighter Days > Player accounts** and tick **Let players sign in**.
2. Save. Email and password sign-in works right away.
3. Make sure the site can send email (password reset codes go out with `wp_mail`). An SMTP plugin is a good idea.

### Google (Google Cloud Console)
1. Go to [console.cloud.google.com](https://console.cloud.google.com) and create a project (or pick one).
2. Open **APIs & Services > OAuth consent screen**:
   - User type: External.
   - App name: Brighter Days. Add your support email and the privacy policy URL.
   - Scopes: `openid`, `email`, `profile` only.
   - Publish the app.
3. Open **APIs & Services > Credentials > Create credentials > OAuth client ID**:
   - Type: **Web application**.
   - Authorized JavaScript origins: `https://alfredwill.com`, plus any other domain the plugin runs on.
   - No redirect URI is needed.
4. Copy the client ID (ends in `.apps.googleusercontent.com`) into **Google client IDs**.

### Sign in with Apple (Apple Developer)
1. In [developer.apple.com](https://developer.apple.com) > **Certificates, Identifiers & Profiles**, make sure an **App ID** exists with Sign in with Apple enabled.
2. Create a **Services ID**, for example `com.leverone.brighterdays.web`. Enable **Sign in with Apple**, then click **Configure**:
   - Primary App ID: the App ID above.
   - Domains: `alfredwill.com`.
   - Return URLs: the game's address, for example `https://alfredwill.com/wp-content/plugins/brighter-days/game/`.
3. Put the Services ID into **Sign in with Apple**.
4. Apple only shares the email the first time a player signs in, and it may be a private relay address. That's fine: the account works without it.

### Microsoft (Azure portal)
1. Go to [portal.azure.com](https://portal.azure.com) > **Microsoft Entra ID > App registrations > New registration**:
   - Name: Brighter Days.
   - Supported account types: **Accounts in any organizational directory and personal Microsoft accounts**.
   - Redirect URI: platform **Web**, address `https://alfredwill.com/wp-content/plugins/brighter-days/game/auth-callback.html`.
2. Under **Authentication**, tick **ID tokens (used for implicit and hybrid flows)**. Save.
3. Copy the **Application (client) ID** into **Microsoft client IDs**. No client secret is needed: the plugin only checks the ID token's signature.
4. Microsoft doesn't guarantee that the email in its token is verified, so the plugin doesn't store it. Players can add an email and password later.

### For the future apps (not built yet)
- **App Store Connect:** add Sign in with Apple to the app's capabilities, and add the bundle ID after the Services ID in the Apple field. Apple requires Sign in with Apple in any iOS app that offers Google.
- **Play Console:** create Android OAuth client IDs in Google Cloud and add them after the web ID in the Google field.

## Server

REST namespace `brighter-days/v1`. Every account route is POST, open to everyone, and limited to 30 requests a minute per address (the limit key is a short-lived hash, so addresses are never stored). The session token goes in the request body, never in cookies, because the game runs in an iframe.

- `GET /config`: tells the game which methods are set up.
- `POST /account/`: `register`, `login`, `provider`, `me`, `logout`, `delete`, `unlink`, `password`, `reset-request`, `reset`, `save`.

After sign-in, every response returns `{ token, account: { email, player, providers, hasPassword, created }, save, saved }`.

**Tables** are created with `dbDelta` and versioned with the `brighter_days_accounts_db` option:

- `bd_accounts`: id, email, pass_hash, player_id (unique), save, save_updated, created, last_login.
- `bd_identities`: provider, subject (primary key together), account_id, created.
- `bd_sessions`: token_hash (primary key), account_id, created, expires.

### Security rules
- **Passwords:** hashed with `wp_hash_password`, at least 8 characters. At most 10 tries per 15 minutes per email or provider subject, counted in a transient keyed on a salted hash.
- **Password reset:**
  - A 6-digit code by email, valid for 30 minutes, with 5 tries.
  - The same answer is given whether or not the email has an account.
  - A successful reset signs out every other device.
- **Sessions:** a 64-hex random token (`random_bytes(32)`). Only its SHA-256 is stored. Sessions expire after 120 days, and expired ones are cleaned up daily. The game keeps the token in its own storage key (`bd_session`), never inside the save.
- **Google, Apple and Microsoft ID tokens** are verified in the plugin with no SDK:
  - The RS256 signature is checked against the provider's key set, cached for 6 hours and refreshed once on an unknown key ID.
  - The issuer must match. For Microsoft, it must match the token's tenant.
  - The audience must be one of this site's configured client IDs.
  - The token must not be expired, allowing 5 minutes of leeway.
- **Provider emails** are stored only when the provider says they're verified and no other account already uses them.
- **Saves** are capped at 300 KB.

## Account deletion

In the game, go to **Menu > Account > Manage > Delete account** and type `DELETE`. This removes:

- the account;
- its linked sign-in methods;
- its sessions;
- the saved copy of the shop.

The plugin also fires `do_action( 'brighter_days_account_deleted', $player_id, $account_id )`. Any future scores, friends or backups tables must hook into it and delete the player's rows. The shop stays on the device as a guest. The App Store and Google Play both require in-app deletion like this.

## Privacy policy

The plugin adds suggested text under **Settings > Privacy > Policy guide**. Your policy must list what accounts store:

- the email address (if given);
- a hashed password (if set);
- the IDs of linked Google, Apple or Microsoft sign-ins;
- the game save;
- the shopkeeper name.

There is no online leaderboard or score table in the plugin yet. When the Exchange moves to the plugin, add the leaderboard name and scores to the policy and to the deletion hook.

## Store review checklist (for the future apps)
- [ ] The game is fully playable without an account.
- [ ] Sign in with Apple is offered wherever Google is offered on iOS.
- [ ] Delete account is reachable in the app and removes the server data.
- [ ] The privacy policy URL is in App Store Connect and Play Console, and lists the data above.
- [ ] Play Console Data safety and the App Store privacy labels list email, user IDs and game progress, "linked to the user", "not used for tracking".
- [ ] A demo account (email and password) is in the review notes.

## Testing
- `php tests/php/run-tests.php`: real RSA keys, so valid tokens pass and forged, edited, expired and wrong-app tokens fail. Also covers the session, lockout, reset, linking and deletion flows. No WordPress needed.
- `node --test tests/js/`: merge, adopt and detach, plus the account flows against the local mock server.
- `node tests/mock-server.js`: runs the game at `http://localhost:8790/wp-content/plugins/brighter-days/game/` with a mock account server, for trying the screens. Reset codes print to the console.

Never test against the live site. Both test suites run in GitHub Actions on every push, and a release only goes out if they pass.
