<?php
/**
 * Optional player accounts for Brighter Days (email and password only).
 *
 * Every device has an anonymous player ID and a local save. An account owns
 * exactly one player ID and keeps a server copy of the save. All account
 * routes are POST with an open permission callback and are rate limited.
 * Session tokens travel in the request body (the game runs in an iframe), and
 * only their SHA-256 is stored. IP addresses are never stored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BD_ACC_DB_VERSION', '3' );
define( 'BD_BOARD_MAX', 300 );
define( 'BD_ACC_NS', 'brighter-days/v1' );
define( 'BD_SESSION_TTL', 120 * DAY_IN_SECONDS );
define( 'BD_SAVE_MAX', 300 * 1024 );
define( 'BD_LOGIN_TRIES', 10 );
define( 'BD_LOGIN_WINDOW', 15 * MINUTE_IN_SECONDS );
define( 'BD_RESET_TTL', 30 * MINUTE_IN_SECONDS );
define( 'BD_RESET_TRIES', 5 );
define( 'BD_ROUTE_PER_MIN', 30 );

/* ---------- Storage ---------- */

class BD_WP_Account_Store {
	private $db;
	private $a;
	private $s;
	private $b;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->a  = $wpdb->prefix . 'bd_accounts';
		$this->s  = $wpdb->prefix . 'bd_sessions';
		$this->b  = $wpdb->prefix . 'bd_board';
	}

	public function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $this->db->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$this->a} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  pass_hash varchar(255) NOT NULL,
  player_id varchar(64) NOT NULL,
  save longtext,
  save_updated bigint(20) unsigned NOT NULL DEFAULT 0,
  created bigint(20) unsigned NOT NULL DEFAULT 0,
  last_login bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY player_id (player_id),
  UNIQUE KEY email (email)
) $c;"
		);
		dbDelta(
			"CREATE TABLE {$this->s} (
  token_hash char(64) NOT NULL,
  account_id bigint(20) unsigned NOT NULL,
  created bigint(20) unsigned NOT NULL DEFAULT 0,
  expires bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (token_hash),
  KEY account_id (account_id)
) $c;"
		);
		dbDelta(
			"CREATE TABLE {$this->b} (
  player_id varchar(64) NOT NULL,
  worth bigint(20) NOT NULL DEFAULT 0,
  data text NOT NULL,
  updated bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (player_id),
  KEY worth (worth)
) $c;"
		);
		// Version 1 had a table for Google, Apple and Microsoft sign-ins, which are gone.
		$this->db->query( "DROP TABLE IF EXISTS {$this->db->prefix}bd_identities" );
	}

	public function account( $id ) {
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->a} WHERE id = %d", $id ), ARRAY_A );
	}

	public function account_by( $field, $value ) {
		if ( ! in_array( $field, array( 'email', 'player_id' ), true ) || '' === (string) $value ) {
			return null;
		}
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->a} WHERE {$field} = %s", $value ), ARRAY_A );
	}

	public function add_account( $row ) {
		return $this->db->insert( $this->a, $row ) ? (int) $this->db->insert_id : 0;
	}

	public function update_account( $id, $row ) {
		return false !== $this->db->update( $this->a, $row, array( 'id' => $id ) );
	}

	public function delete_account( $id ) {
		$this->db->delete( $this->s, array( 'account_id' => $id ) );
		$this->db->delete( $this->a, array( 'id' => $id ) );
	}

	public function count_accounts() {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->a}" );
	}

	public function add_session( $hash, $account_id, $created, $expires ) {
		return (bool) $this->db->insert(
			$this->s,
			array(
				'token_hash' => $hash,
				'account_id' => $account_id,
				'created'    => $created,
				'expires'    => $expires,
			)
		);
	}

	public function session( $hash ) {
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->s} WHERE token_hash = %s", $hash ), ARRAY_A );
	}

	public function delete_session( $hash ) {
		$this->db->delete( $this->s, array( 'token_hash' => $hash ) );
	}

	public function delete_sessions( $account_id, $except_hash = '' ) {
		if ( '' === $except_hash ) {
			$this->db->delete( $this->s, array( 'account_id' => $account_id ) );
			return;
		}
		$this->db->query( $this->db->prepare( "DELETE FROM {$this->s} WHERE account_id = %d AND token_hash <> %s", $account_id, $except_hash ) );
	}

	public function purge_sessions( $now ) {
		$this->db->query( $this->db->prepare( "DELETE FROM {$this->s} WHERE expires < %d", $now ) );
	}

	public function board_put( $player_id, $worth, $data, $updated ) {
		return false !== $this->db->replace(
			$this->b,
			array(
				'player_id' => $player_id,
				'worth'     => $worth,
				'data'      => $data,
				'updated'   => $updated,
			),
			array( '%s', '%d', '%s', '%d' )
		);
	}

	public function board_list( $limit ) {
		return (array) $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->b} ORDER BY worth DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public function board_delete( $player_id ) {
		$this->db->delete( $this->b, array( 'player_id' => $player_id ) );
	}
}

function bd_store() {
	if ( empty( $GLOBALS['bd_account_store'] ) ) {
		$GLOBALS['bd_account_store'] = new BD_WP_Account_Store();
	}
	return $GLOBALS['bd_account_store'];
}

/* ---------- Helpers ---------- */

function bd_err( $code, $message, $status = 400 ) {
	return new WP_Error( 'bd_' . $code, $message, array( 'status' => $status ) );
}

function bd_accounts_on() {
	return '1' === (string) get_option( 'brighter_days_accounts', '0' );
}

/** Short-lived hashed key, so no email or IP is stored in the clear. */
function bd_limit_key( $bucket, $value ) {
	return 'bd_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . strtolower( (string) $value ), wp_salt( 'nonce' ) ), 0, 32 );
}

function bd_limited( $bucket, $value, $max ) {
	return (int) get_transient( bd_limit_key( $bucket, $value ) ) >= $max;
}

function bd_count( $bucket, $value, $window ) {
	$k = bd_limit_key( $bucket, $value );
	set_transient( $k, (int) get_transient( $k ) + 1, $window );
}

function bd_clear( $bucket, $value ) {
	delete_transient( bd_limit_key( $bucket, $value ) );
}

/** Per-route request limit keyed on a hash of the address and the current minute. */
function bd_route_limit( $route ) {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$key = $ip . '|' . gmdate( 'YmdHi' );
	if ( bd_limited( 'route' . $route, $key, BD_ROUTE_PER_MIN ) ) {
		return bd_err( 'slow_down', 'Too many requests. Wait a minute and try again.', 429 );
	}
	bd_count( 'route' . $route, $key, 2 * MINUTE_IN_SECONDS );
	return true;
}

function bd_clean_email( $e ) {
	$e = strtolower( trim( (string) $e ) );
	return ( strlen( $e ) <= 190 && is_email( $e ) ) ? $e : '';
}

function bd_clean_player( $p ) {
	$p = (string) $p;
	return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $p ) ? $p : '';
}

/** Returns array( ok, json-or-null ). */
function bd_clean_save( $save ) {
	if ( null === $save || '' === $save ) {
		return array( true, null );
	}
	if ( ! is_array( $save ) ) {
		return array( false, null );
	}
	$json = wp_json_encode( $save );
	if ( false === $json || strlen( $json ) > BD_SAVE_MAX ) {
		return array( false, null );
	}
	return array( true, $json );
}

function bd_password_ok( $pass ) {
	$len = strlen( (string) $pass );
	return $len >= 8 && $len <= 200;
}

function bd_account_out( $acc ) {
	return array(
		'email'   => (string) $acc['email'],
		'player'  => (string) $acc['player_id'],
		'created' => (int) $acc['created'],
	);
}

function bd_signed_in( $acc, $token ) {
	$save = null;
	if ( ! empty( $acc['save'] ) ) {
		$save = json_decode( $acc['save'], true );
	}
	return array(
		'token'   => $token,
		'account' => bd_account_out( $acc ),
		'save'    => $save,
		'saved'   => (int) $acc['save_updated'],
	);
}

function bd_new_session( $account_id ) {
	$token = bin2hex( random_bytes( 32 ) );
	$now   = time();
	bd_store()->add_session( hash( 'sha256', $token ), $account_id, $now, $now + BD_SESSION_TTL );
	bd_store()->update_account( $account_id, array( 'last_login' => $now ) );
	return $token;
}

/** Account for the session token in the request body, or WP_Error. */
function bd_auth( $p ) {
	$token = (string) ( $p['token'] ?? '' );
	$gone  = bd_err( 'signed_out', 'You\'ve been signed out. Sign in again.', 401 );
	if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
		return $gone;
	}
	$hash = hash( 'sha256', $token );
	$sess = bd_store()->session( $hash );
	if ( ! $sess ) {
		return $gone;
	}
	if ( (int) $sess['expires'] < time() ) {
		bd_store()->delete_session( $hash );
		return $gone;
	}
	$acc = bd_store()->account( (int) $sess['account_id'] );
	if ( ! $acc ) {
		bd_store()->delete_session( $hash );
		return $gone;
	}
	$acc['_token'] = $token;
	$acc['_hash']  = $hash;
	return $acc;
}

/* ---------- Handlers (take the request body as an array) ---------- */

function bd_h_config( $p ) {
	return array( 'enabled' => bd_accounts_on() );
}

function bd_h_register( $p ) {
	$email = bd_clean_email( $p['email'] ?? '' );
	if ( ! $email ) {
		return bd_err( 'email', 'Enter a valid email address.' );
	}
	$pass = (string) ( $p['password'] ?? '' );
	if ( ! bd_password_ok( $pass ) ) {
		return bd_err( 'password', 'Use at least 8 characters for your password.' );
	}
	$player = bd_clean_player( $p['player'] ?? '' );
	if ( ! $player ) {
		return bd_err( 'player', 'This device has no player ID yet. Reload the game and try again.' );
	}
	if ( bd_store()->account_by( 'email', $email ) ) {
		return bd_err( 'email_taken', 'That email already has an account. Sign in instead.', 409 );
	}
	if ( bd_store()->account_by( 'player_id', $player ) ) {
		return bd_err( 'player_taken', 'This player already belongs to an account. Sign in to that account instead.', 409 );
	}
	list( $ok, $save ) = bd_clean_save( $p['save'] ?? null );
	if ( ! $ok ) {
		return bd_err( 'save_size', 'Your save is too large to back up.', 413 );
	}
	$now = time();
	$id  = bd_store()->add_account(
		array(
			'email'        => $email,
			'pass_hash'    => wp_hash_password( $pass ),
			'player_id'    => $player,
			'save'         => $save,
			'save_updated' => $save ? $now : 0,
			'created'      => $now,
			'last_login'   => $now,
		)
	);
	if ( ! $id ) {
		return bd_err( 'server', 'Couldn\'t create the account. Try again.', 500 );
	}
	$token = bd_new_session( $id );
	return bd_signed_in( bd_store()->account( $id ), $token );
}

function bd_h_login( $p ) {
	$email = bd_clean_email( $p['email'] ?? '' );
	$pass  = (string) ( $p['password'] ?? '' );
	if ( ! $email || '' === $pass ) {
		return bd_err( 'login', 'Enter your email and password.' );
	}
	if ( bd_limited( 'login', $email, BD_LOGIN_TRIES ) ) {
		return bd_err( 'slow_down', 'Too many tries. Wait 15 minutes and try again.', 429 );
	}
	$acc = bd_store()->account_by( 'email', $email );
	if ( ! $acc || ! wp_check_password( $pass, $acc['pass_hash'] ) ) {
		bd_count( 'login', $email, BD_LOGIN_WINDOW );
		return bd_err( 'login', 'That email and password don\'t match.', 401 );
	}
	bd_clear( 'login', $email );
	return bd_signed_in( $acc, bd_new_session( (int) $acc['id'] ) );
}

function bd_h_me( $p ) {
	$me = bd_auth( $p );
	return is_wp_error( $me ) ? $me : bd_signed_in( $me, $me['_token'] );
}

function bd_h_logout( $p ) {
	$me = bd_auth( $p );
	if ( ! is_wp_error( $me ) ) {
		bd_store()->delete_session( $me['_hash'] );
	}
	return array( 'ok' => true );
}

function bd_h_delete( $p ) {
	$me = bd_auth( $p );
	if ( is_wp_error( $me ) ) {
		return $me;
	}
	if ( 'DELETE' !== (string) ( $p['confirm'] ?? '' ) ) {
		return bd_err( 'confirm', 'Type DELETE to confirm.' );
	}
	/** Lets other parts of the site remove this player's scores, friends and backups. */
	do_action( 'brighter_days_account_deleted', (string) $me['player_id'], (int) $me['id'] );
	bd_store()->board_delete( (string) $me['player_id'] );
	bd_store()->delete_account( (int) $me['id'] );
	return array( 'ok' => true );
}

/** Changes the password and/or email. Needs the current password. */
function bd_h_password( $p ) {
	$me = bd_auth( $p );
	if ( is_wp_error( $me ) ) {
		return $me;
	}
	$new      = (string) ( $p['password'] ?? '' );
	$email    = array_key_exists( 'email', $p ) ? bd_clean_email( $p['email'] ) : null;
	$limit_id = 'acct' . $me['id'];

	if ( bd_limited( 'login', $limit_id, BD_LOGIN_TRIES ) ) {
		return bd_err( 'slow_down', 'Too many tries. Wait 15 minutes and try again.', 429 );
	}
	if ( ! wp_check_password( (string) ( $p['current'] ?? '' ), $me['pass_hash'] ) ) {
		bd_count( 'login', $limit_id, BD_LOGIN_WINDOW );
		return bd_err( 'current', 'Your current password isn\'t right.', 401 );
	}
	bd_clear( 'login', $limit_id );

	$row = array();
	if ( '' !== $new ) {
		if ( ! bd_password_ok( $new ) ) {
			return bd_err( 'password', 'Use at least 8 characters for your password.' );
		}
		$row['pass_hash'] = wp_hash_password( $new );
	}
	if ( null !== $email && $email !== (string) $me['email'] ) {
		if ( '' === $email ) {
			return bd_err( 'email', 'Enter a valid email address.' );
		}
		$other = bd_store()->account_by( 'email', $email );
		if ( $other && (int) $other['id'] !== (int) $me['id'] ) {
			return bd_err( 'email_taken', 'That email already has an account.', 409 );
		}
		$row['email'] = $email;
	}
	if ( ! $row ) {
		return bd_err( 'nothing', 'Nothing to change.' );
	}
	bd_store()->update_account( (int) $me['id'], $row );
	if ( isset( $row['pass_hash'] ) ) {
		bd_store()->delete_sessions( (int) $me['id'], $me['_hash'] ); // Other devices sign in again.
	}
	return bd_signed_in( bd_store()->account( (int) $me['id'] ), $me['_token'] );
}

function bd_reset_key( $email ) {
	return bd_limit_key( 'reset', $email );
}

function bd_code_hash( $code ) {
	return hash_hmac( 'sha256', (string) $code, wp_salt( 'auth' ) );
}

function bd_h_reset_request( $p ) {
	$email = bd_clean_email( $p['email'] ?? '' );
	if ( ! $email ) {
		return bd_err( 'email', 'Enter a valid email address.' );
	}
	// Same answer whether or not the email has an account.
	if ( bd_limited( 'reset_req', $email, 3 ) ) {
		return array( 'ok' => true );
	}
	bd_count( 'reset_req', $email, BD_RESET_TTL );
	$acc = bd_store()->account_by( 'email', $email );
	if ( $acc ) {
		$code = sprintf( '%06d', random_int( 0, 999999 ) );
		set_transient(
			bd_reset_key( $email ),
			array(
				'h'     => bd_code_hash( $code ),
				'tries' => 0,
				'exp'   => time() + BD_RESET_TTL,
			),
			BD_RESET_TTL
		);
		wp_mail(
			$email,
			'Your Brighter Days reset code',
			"Your code is {$code}. It works for 30 minutes.\n\nIf you didn't ask to reset your Brighter Days password, you can ignore this email."
		);
	}
	return array( 'ok' => true );
}

function bd_h_reset( $p ) {
	$email = bd_clean_email( $p['email'] ?? '' );
	$code  = preg_replace( '/\D/', '', (string) ( $p['code'] ?? '' ) );
	$new   = (string) ( $p['password'] ?? '' );
	if ( ! $email || 6 !== strlen( $code ) ) {
		return bd_err( 'code', 'Enter your email and the 6-digit code.' );
	}
	if ( ! bd_password_ok( $new ) ) {
		return bd_err( 'password', 'Use at least 8 characters for your password.' );
	}
	$key = bd_reset_key( $email );
	$r   = get_transient( $key );
	if ( ! is_array( $r ) || (int) $r['exp'] < time() ) {
		return bd_err( 'code', 'That code has expired. Ask for a new one.', 401 );
	}
	if ( (int) $r['tries'] >= BD_RESET_TRIES ) {
		delete_transient( $key );
		return bd_err( 'code', 'Too many wrong codes. Ask for a new one.', 429 );
	}
	if ( ! hash_equals( (string) $r['h'], bd_code_hash( $code ) ) ) {
		$r['tries'] = (int) $r['tries'] + 1;
		if ( $r['tries'] >= BD_RESET_TRIES ) {
			delete_transient( $key );
			return bd_err( 'code', 'Too many wrong codes. Ask for a new one.', 429 );
		}
		set_transient( $key, $r, max( 1, (int) $r['exp'] - time() ) );
		return bd_err( 'code', 'That code isn\'t right.', 401 );
	}
	$acc = bd_store()->account_by( 'email', $email );
	delete_transient( $key );
	if ( ! $acc ) {
		return bd_err( 'code', 'That code has expired. Ask for a new one.', 401 );
	}
	bd_store()->update_account( (int) $acc['id'], array( 'pass_hash' => wp_hash_password( $new ) ) );
	bd_store()->delete_sessions( (int) $acc['id'] ); // Signs out every other device.
	bd_clear( 'login', $email );
	return bd_signed_in( bd_store()->account( (int) $acc['id'] ), bd_new_session( (int) $acc['id'] ) );
}

/**
 * Stores the save. If another device saved since this device last synced
 * (base), nothing is overwritten: the newer server copy comes back so the
 * game can merge and try again.
 */
function bd_h_save( $p ) {
	$me = bd_auth( $p );
	if ( is_wp_error( $me ) ) {
		return $me;
	}
	list( $ok, $save ) = bd_clean_save( $p['save'] ?? null );
	if ( ! $ok || null === $save ) {
		return bd_err( 'save_size', 'Your save is too large to back up.', 413 );
	}
	$base = (int) ( $p['base'] ?? 0 );
	if ( (int) $me['save_updated'] > $base && ! empty( $me['save'] ) ) {
		return array(
			'conflict' => true,
			'save'     => json_decode( $me['save'], true ),
			'saved'    => (int) $me['save_updated'],
		);
	}
	$now = max( time(), $base + 1 );
	bd_store()->update_account(
		(int) $me['id'],
		array(
			'save'         => $save,
			'save_updated' => $now,
		)
	);
	return array( 'saved' => $now );
}

/* ---------- The Exchange (shop leaderboard) ---------- */

/** Public row id: a salted hash, so the board never reveals player IDs. */
function bd_board_id( $player_id ) {
	return substr( hash_hmac( 'sha256', (string) $player_id, wp_salt( 'nonce' ) ), 0, 16 );
}

function bd_int( $v, $min, $max ) {
	$n = is_numeric( $v ) ? (int) round( (float) $v ) : 0;
	return max( $min, min( $max, $n ) );
}

/** Cleans a listing from the game. Returns the clean array or WP_Error. */
function bd_clean_entry( $e ) {
	if ( ! is_array( $e ) ) {
		return bd_err( 'entry', 'That listing isn\'t valid.' );
	}
	$name = trim( preg_replace( '/\s+/', ' ', (string) ( $e['name'] ?? '' ) ) );
	if ( strlen( $name ) < 2 || strlen( $name ) > 16 || ! preg_match( '/^[A-Za-z0-9 _\'.-]+$/', $name ) ) {
		return bd_err( 'name', 'Choose a shopkeeper name of 2 to 16 letters, numbers, spaces or _ \' . -' );
	}
	$ticker = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $e['ticker'] ?? '' ) ) );
	$ticker = substr( '' === $ticker ? strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', $name ), 0, 4 ) ) : $ticker, 0, 5 );
	$big    = 1000000000000;
	return array(
		'name'    => $name,
		'ticker'  => $ticker,
		'worth'   => bd_int( $e['worth'] ?? 0, -$big, $big ),
		'prev'    => bd_int( $e['prev'] ?? 0, -$big, $big ),
		'purse'   => bd_int( $e['purse'] ?? 0, -$big, $big ),
		'day'     => bd_int( $e['day'] ?? 1, 1, 1000000 ),
		'level'   => bd_int( $e['level'] ?? 1, 1, 10000 ),
		'rep'     => bd_int( $e['rep'] ?? 0, 0, 100 ),
		'trades'  => bd_int( $e['trades'] ?? 0, 0, $big ),
		'thieves' => bd_int( $e['thieves'] ?? 0, 0, $big ),
		'crooks'  => bd_int( $e['crooks'] ?? 0, 0, $big ),
		'best'    => bd_int( $e['best'] ?? 0, -$big, $big ),
		'tro'     => bd_int( $e['tro'] ?? 0, 0, 999 ),
		'guild'   => ! empty( $e['guild'] ),
	);
}

/** Lists the shops on the Exchange, richest first. Anyone can read it. */
function bd_h_board( $p ) {
	$rows = array();
	foreach ( bd_store()->board_list( BD_BOARD_MAX ) as $r ) {
		$d = json_decode( (string) $r['data'], true );
		if ( ! is_array( $d ) ) {
			continue;
		}
		$d['id']        = bd_board_id( $r['player_id'] );
		$d['updatedAt'] = (int) $r['updated'];
		$rows[]         = $d;
	}
	return array( 'rows' => $rows );
}

/** Lists or updates the signed-in player's shop. */
function bd_h_board_post( $p ) {
	$me = bd_auth( $p );
	if ( is_wp_error( $me ) ) {
		return $me;
	}
	$e = bd_clean_entry( $p['entry'] ?? null );
	if ( is_wp_error( $e ) ) {
		return $e;
	}
	bd_store()->board_put( (string) $me['player_id'], $e['worth'], wp_json_encode( $e ), time() );
	return array(
		'ok' => true,
		'id' => bd_board_id( $me['player_id'] ),
	);
}

/** Takes the signed-in player's shop off the Exchange. */
function bd_h_board_delist( $p ) {
	$me = bd_auth( $p );
	if ( is_wp_error( $me ) ) {
		return $me;
	}
	bd_store()->board_delete( (string) $me['player_id'] );
	return array( 'ok' => true );
}

/* ---------- WordPress wiring ---------- */

function bd_account_routes() {
	return array(
		'account/register'      => 'bd_h_register',
		'account/login'         => 'bd_h_login',
		'account/me'            => 'bd_h_me',
		'account/logout'        => 'bd_h_logout',
		'account/delete'        => 'bd_h_delete',
		'account/password'      => 'bd_h_password',
		'account/reset-request' => 'bd_h_reset_request',
		'account/reset'         => 'bd_h_reset',
		'account/save'          => 'bd_h_save',
		'board/post'            => 'bd_h_board_post',
		'board/delist'          => 'bd_h_board_delist',
	);
}

function bd_rest_dispatch( $handler, $route, $request ) {
	$limit = bd_route_limit( $route );
	if ( is_wp_error( $limit ) ) {
		return $limit;
	}
	if ( 'config' !== $route && 'board' !== $route && ! bd_accounts_on() ) {
		return bd_err( 'off', 'Accounts are switched off on this site.', 403 );
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = (array) $request->get_body_params();
	}
	$out = call_user_func( $handler, $body );
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	$res = rest_ensure_response( $out );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

function bd_register_account_routes() {
	register_rest_route(
		BD_ACC_NS,
		'/config',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				return bd_rest_dispatch( 'bd_h_config', 'config', $req );
			},
		)
	);
	register_rest_route(
		BD_ACC_NS,
		'/board',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				return bd_rest_dispatch( 'bd_h_board', 'board', $req );
			},
		)
	);
	foreach ( bd_account_routes() as $route => $handler ) {
		register_rest_route(
			BD_ACC_NS,
			'/' . $route,
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( $req ) use ( $handler, $route ) {
					return bd_rest_dispatch( $handler, $route, $req );
				},
			)
		);
	}
}
add_action( 'rest_api_init', 'bd_register_account_routes' );

/** Creates or upgrades the tables when the plugin is installed or updated. */
function bd_accounts_maybe_install() {
	if ( BD_ACC_DB_VERSION !== get_option( 'brighter_days_accounts_db' ) ) {
		bd_store()->install();
		update_option( 'brighter_days_accounts_db', BD_ACC_DB_VERSION );
		delete_option( 'brighter_days_google_ids' );
		delete_option( 'brighter_days_apple_ids' );
		delete_option( 'brighter_days_microsoft_ids' );
	}
}
add_action( 'plugins_loaded', 'bd_accounts_maybe_install' );

/** Daily cleanup of expired sessions. */
function bd_accounts_schedule() {
	if ( ! wp_next_scheduled( 'brighter_days_purge_sessions' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'brighter_days_purge_sessions' );
	}
}
add_action( 'init', 'bd_accounts_schedule' );
add_action(
	'brighter_days_purge_sessions',
	function () {
		bd_store()->purge_sessions( time() );
	}
);

/** Suggested text for the site's privacy policy (Settings > Privacy). */
function bd_accounts_privacy_text() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	wp_add_privacy_policy_content(
		'Brighter Days',
		'<p>Brighter Days can be played without an account. If you create one, we store: your email address, a hashed password, your game save, your shopkeeper name, and your shop\'s listing on the Exchange leaderboard (name, ticker and shop scores, which other players can see). Sign-in sessions are stored as one-way hashes. We do not store your IP address; rate limits use short-lived hashed keys. You can take your shop off the Exchange at any time, and you can delete your account in the game (Menu, Account, Delete account), which removes the account, its sessions, the saved game and the Exchange listing from our server.</p>'
	);
}
add_action( 'admin_init', 'bd_accounts_privacy_text' );
