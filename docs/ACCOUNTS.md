# Player accounts

Brighter Days can be played without an account. Accounts are optional:

- Players make one in the game with an email and password. There is no sign-in through Google, Apple, Microsoft or any other outside service.
- Accounts run entirely inside the WordPress plugin, which stores them and keeps a backup of each player's shop.
- They only work when the game is played from the plugin (the `[brighter_days]` shortcode or its full-screen link). The GitHub Pages copy stays guest-only.

## Turning accounts on

1. In WordPress, go to **Settings > Brighter Days > Player accounts** and tick **Let players create accounts and sign in**. Save.
2. Make sure the site can send email, because password reset codes go out with `wp_mail`. An SMTP plugin is a good idea.

The settings page also shows how many accounts exist.

## What players see

- **First launch:** the "Sign in, shopkeeper" box offers **Sign in or create an account**, or a shopkeeper name and **Play as guest**.
- **Signing in:** an email and password, with links to **Create an account** and **Forgot password?**.
- **Forgot password:** a 6-digit code is emailed and works for 30 minutes. The player types it in with a new password.
- **Account screen (Menu > Account > Manage):**
  - change email or password (needs the current password);
  - sign out;
  - delete account.

## How it works

- Every device has an anonymous random player ID and a local save. An account **owns exactly one player ID** and keeps a server copy of the save.
- **Signing in on a new device** merges that device's shop with the account's save, then the device takes on the account's player ID.
  - Merge rule: start from the newer save. A brand-new game nobody has played yet never counts as newer than a real shop.
  - Then keep the best of both: every trophy at the earliest day it was earned, every regular met, and the highest lifetime stats.
  - Coin, stock and the shop itself come from the newer save so the game stays consistent.
- **Signing out or deleting the account** keeps the shop on the device, but the device becomes a new, separate player ID.
- A player ID that already belongs to an account can't be claimed by a second account ("Sign in to that account instead").
- **Backups:** while a player is signed in, the game backs up the shop about every 20 seconds of play and when the tab is hidden. If another device saved since, the server sends its newer copy back instead of overwriting. The game merges the two and tries again.

## Server

REST namespace `brighter-days/v1`. Every account route is POST, open to everyone, and limited to 30 requests a minute per address (the limit key is a short-lived hash, so addresses are never stored). The session token goes in the request body, never in cookies, because the game runs in an iframe.

- `GET /config`: tells the game whether accounts are on.
- `POST /account/`: `register`, `login`, `me`, `logout`, `delete`, `password`, `reset-request`, `reset`, `save`.

After sign-in, every response returns `{ token, account: { email, player, created }, save, saved }`.

**Tables** are created with `dbDelta` and versioned with the `brighter_days_accounts_db` option:

- `bd_accounts`: id, email (unique), pass_hash, player_id (unique), save, save_updated, created, last_login.
- `bd_sessions`: token_hash (primary key), account_id, created, expires.

### Security rules
- **Passwords:** hashed with `wp_hash_password`, at least 8 characters. At most 10 tries per 15 minutes per email, counted in a transient keyed on a salted hash.
- **Changing email or password** needs the current password. A new password signs out every other device.
- **Password reset:**
  - A 6-digit code by email, valid for 30 minutes, with 5 tries.
  - The same answer is given whether or not the email has an account.
  - A successful reset signs out every other device.
- **Sessions:** a 64-hex random token (`random_bytes(32)`). Only its SHA-256 is stored. Sessions expire after 120 days, and expired ones are cleaned up daily. The game keeps the token in its own storage key (`bd_session`), never inside the save, so save codes and backups can't leak it.
- **Saves** are capped at 300 KB.

## Account deletion

In the game, go to **Menu > Account > Manage > Delete account** and type `DELETE`. This removes:

- the account;
- its sessions;
- the saved copy of the shop.

The plugin also fires `do_action( 'brighter_days_account_deleted', $player_id, $account_id )`. Any future scores, friends or backups tables must hook into it and delete the player's rows. The shop stays on the device as a guest.

## Privacy policy

The plugin adds suggested text under **Settings > Privacy > Policy guide**. Your policy must list what accounts store:

- the email address;
- a hashed password;
- the game save;
- the shopkeeper name.

There is no online leaderboard or score table in the plugin yet. When the Exchange moves to the plugin, add the leaderboard name and scores to the policy and to the deletion hook.

## Testing
- `php tests/php/run-tests.php`: register, login, lockout, sessions, save conflicts, email and password changes, reset codes and deletion. No WordPress needed.
- `node --test tests/js/*.test.js`: merge, adopt and detach, plus the account flows against the local mock server.
- `node tests/mock-server.js`: runs the game at `http://localhost:8790/wp-content/plugins/brighter-days/game/` with a mock account server, for trying the screens. Reset codes print to the console.

Never test against the live site. Both test suites run in GitHub Actions on every push, and a release only goes out if they pass.
