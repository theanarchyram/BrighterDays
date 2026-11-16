<?php
/**
 * Plain-PHP checks for the account code. No WordPress needed: the few WP
 * functions used are stubbed below, and accounts live in an in-memory store.
 *
 *   php tests/php/run-tests.php
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

/* ---------- WordPress stubs ---------- */

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
$GLOBALS['t_options']    = array();
$GLOBALS['t_transients'] = array();
$GLOBALS['t_mail']       = array();
$GLOBALS['t_actions']    = array();
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['t_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['t_options'][ $k ] );
	return true;
}
function get_transient( $k ) {
	$t = $GLOBALS['t_transients'][ $k ] ?? null;
	if ( ! $t || $t[1] < time() ) {
		return false;
	}
	return $t[0];
}
function set_transient( $k, $v, $ttl ) {
	$GLOBALS['t_transients'][ $k ] = array( $v, time() + (int) $ttl );
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['t_transients'][ $k ] );
	return true;
}
function wp_salt( $s = 'auth' ) {
	return 'test-salt-' . $s;
}
function wp_hash_password( $p ) {
	return password_hash( $p, PASSWORD_DEFAULT );
}
function wp_check_password( $p, $h, $id = '' ) {
	return password_verify( $p, (string) $h );
}
function is_email( $e ) {
	return false !== filter_var( $e, FILTER_VALIDATE_EMAIL );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function do_action( $tag, ...$args ) {
	$GLOBALS['t_actions'][] = array( $tag, $args );
}
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['t_mail'][] = array( $to, $subject, $body );
	return true;
}
function add_action() {}
function add_filter() {}

/* ---------- In-memory store (same methods as BD_WP_Account_Store) ---------- */

class BD_Memory_Store {
	public $accounts = array();
	public $sessions = array();
	private $next = 1;
	public function account( $id ) {
		return $this->accounts[ $id ] ?? null;
	}
	public function account_by( $field, $value ) {
		foreach ( $this->accounts as $a ) {
			if ( (string) $a[ $field ] === (string) $value ) {
				return $a;
			}
		}
		return null;
	}
	public function add_account( $row ) {
		foreach ( array( 'email', 'player_id' ) as $u ) {
			if ( $this->account_by( $u, $row[ $u ] ) ) {
				return 0; // Unique key.
			}
		}
		$id                    = $this->next++;
		$this->accounts[ $id ] = array_merge( array( 'id' => $id ), $row );
		return $id;
	}
	public function update_account( $id, $row ) {
		$this->accounts[ $id ] = array_merge( $this->accounts[ $id ], $row );
		return true;
	}
	public function delete_account( $id ) {
		unset( $this->accounts[ $id ] );
		$this->delete_sessions( $id );
	}
	public function count_accounts() {
		return count( $this->accounts );
	}
	public function add_session( $h, $aid, $c, $e ) {
		$this->sessions[ $h ] = array(
			'token_hash' => $h,
			'account_id' => $aid,
			'created'    => $c,
			'expires'    => $e,
		);
		return true;
	}
	public function session( $h ) {
		return $this->sessions[ $h ] ?? null;
	}
	public function delete_session( $h ) {
		unset( $this->sessions[ $h ] );
	}
	public function delete_sessions( $aid, $except = '' ) {
		foreach ( $this->sessions as $h => $s ) {
			if ( (int) $s['account_id'] === (int) $aid && $h !== $except ) {
				unset( $this->sessions[ $h ] );
			}
		}
	}
	public function purge_sessions( $now ) {
		foreach ( $this->sessions as $h => $s ) {
			if ( $s['expires'] < $now ) {
				unset( $this->sessions[ $h ] );
			}
		}
	}
	public $board = array();
	public function board_put( $pid, $worth, $data, $updated ) {
		$this->board[ $pid ] = array(
			'player_id' => $pid,
			'worth'     => $worth,
			'data'      => $data,
			'updated'   => $updated,
		);
		return true;
	}
	public function board_list( $limit ) {
		$rows = array_values( $this->board );
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['worth'] <=> $a['worth'];
			}
		);
		return array_slice( $rows, 0, $limit );
	}
	public function board_delete( $pid ) {
		unset( $this->board[ $pid ] );
	}
}

require dirname( __DIR__, 2 ) . '/wordpress-plugin/brighter-days/includes/accounts.php';

/* ---------- Tiny test runner ---------- */

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();
function check( $cond, $name ) {
	if ( $cond ) {
		$GLOBALS['t_pass']++;
	} else {
		$GLOBALS['t_fail'][] = $name;
		echo "FAIL: $name\n";
	}
}
function code_of( $r ) {
	return is_wp_error( $r ) ? $r->code : 'ok';
}
function fresh() {
	$GLOBALS['bd_account_store'] = new BD_Memory_Store();
	$GLOBALS['t_transients']     = array();
	$GLOBALS['t_mail']           = array();
	$GLOBALS['t_actions']        = array();
}
function mail_code() {
	preg_match( '/(\d{6})/', end( $GLOBALS['t_mail'] )[2], $m );
	return $m[1];
}
function wrong_code( $code ) {
	return '000000' === $code ? '111111' : '000000';
}

/* ---------- Config ---------- */

fresh();
check( false === bd_h_config( array() )['enabled'], 'config says off by default' );
update_option( 'brighter_days_accounts', '1' );
$cfg = bd_h_config( array() );
check( true === $cfg['enabled'] && 1 === count( $cfg ), 'config says on, with no sign-in providers' );

/* ---------- Register, sessions, login ---------- */

check( 'bd_email' === code_of( bd_h_register( array( 'email' => 'not-an-email', 'password' => 'longenough', 'player' => 'p_device00001' ) ) ), 'bad email refused' );
check( 'bd_password' === code_of( bd_h_register( array( 'email' => 'a@example.com', 'password' => 'short', 'player' => 'p_device00001' ) ) ), 'password under 8 characters is refused' );
check( 'bd_player' === code_of( bd_h_register( array( 'email' => 'a@example.com', 'password' => 'longenough', 'player' => 'bad id' ) ) ), 'bad player ID refused' );
$reg = bd_h_register(
	array(
		'email'    => 'A@Example.com',
		'password' => 'longenough',
		'player'   => 'p_device00001',
		'save'     => array(
			'v'   => 1,
			'day' => 3,
		),
	)
);
check( ! is_wp_error( $reg ) && preg_match( '/^[0-9a-f]{64}$/', $reg['token'] ), 'register returns a 64-hex token' );
check( ! is_wp_error( $reg ) && array( 'email', 'player', 'created' ) === array_keys( $reg['account'] ), 'account has email, player and created only' );
check( 'a@example.com' === $reg['account']['email'] && 'p_device00001' === $reg['account']['player'] && 3 === $reg['save']['day'], 'register returns the account and save' );
$store = $GLOBALS['bd_account_store'];
$sess  = array_values( $store->sessions )[0];
check( hash( 'sha256', $reg['token'] ) === $sess['token_hash'] && ! isset( $store->sessions[ $reg['token'] ] ), 'only the SHA-256 of the token is stored' );
check( abs( $sess['expires'] - ( time() + 120 * DAY_IN_SECONDS ) ) < 5, 'session lasts 120 days' );
check( false === strpos( $store->accounts[1]['pass_hash'], 'longenough' ) && password_verify( 'longenough', $store->accounts[1]['pass_hash'] ), 'password is hashed' );
check( 'bd_player_taken' === code_of( bd_h_register( array( 'email' => 'b@example.com', 'password' => 'longenough', 'player' => 'p_device00001' ) ) ), 'a player owned by an account cannot be claimed again' );
check( 'bd_email_taken' === code_of( bd_h_register( array( 'email' => 'a@example.com', 'password' => 'longenough', 'player' => 'p_device00002' ) ) ), 'duplicate email refused' );
$big = array( 'blob' => str_repeat( 'x', 310 * 1024 ) );
check( 'bd_save_size' === code_of( bd_h_register( array( 'email' => 'c@example.com', 'password' => 'longenough', 'player' => 'p_device00003', 'save' => $big ) ) ), 'save over the size cap refused' );

check( 'ok' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ), 'me works with the token' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => str_repeat( 'a', 64 ) ) ) ), 'unknown token is signed out' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => 'nothex' ) ) ), 'malformed token is signed out' );

check( 'bd_login' === code_of( bd_h_login( array( 'email' => 'a@example.com', 'password' => 'wrong-password' ) ) ), 'wrong password refused' );
check( 'bd_login' === code_of( bd_h_login( array( 'email' => 'nobody@example.com', 'password' => 'longenough' ) ) ), 'unknown email gets the same answer' );
$login = bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) );
check( ! is_wp_error( $login ) && $login['token'] !== $reg['token'], 'login gives a new session' );
for ( $i = 0; $i < 10; $i++ ) {
	bd_h_login( array( 'email' => 'a@example.com', 'password' => 'wrong-password' ) );
}
check( 'bd_slow_down' === code_of( bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) ) ), '10 failed tries locks the email out' );
$keys = implode( ' ', array_keys( $GLOBALS['t_transients'] ) );
check( false === strpos( $keys, 'a@example.com' ), 'rate-limit keys do not contain the email' );

/* ---------- Save sync ---------- */

$s1 = bd_h_save(
	array(
		'token' => $login['token'],
		'save'  => array( 'day' => 5 ),
		'base'  => $reg['saved'],
	)
);
check( ! is_wp_error( $s1 ) && empty( $s1['conflict'] ) && $s1['saved'] > 0, 'save stores when the base is current' );
$s2 = bd_h_save(
	array(
		'token' => $reg['token'],
		'save'  => array( 'day' => 4 ),
		'base'  => $reg['saved'],
	)
);
check( ! empty( $s2['conflict'] ) && 5 === $s2['save']['day'], 'stale save gets the newer copy back instead of overwriting' );
check( 'bd_save_size' === code_of( bd_h_save( array( 'token' => $reg['token'], 'save' => $big, 'base' => $s2['saved'] ) ) ), 'oversized save refused' );

/* ---------- Logout and expiry ---------- */

bd_h_logout( array( 'token' => $login['token'] ) );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $login['token'] ) ) ), 'logout ends the session' );
check( 'ok' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ), 'logout leaves other devices signed in' );
$tok = bd_new_session( 1 );
$store->sessions[ hash( 'sha256', $tok ) ]['expires'] = time() - 1;
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $tok ) ) ), 'expired session is refused' );

/* ---------- Change email or password ---------- */

$GLOBALS['t_transients'] = array();
$dev2                    = bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) );
check( 'bd_current' === code_of( bd_h_password( array( 'token' => $reg['token'], 'password' => 'newpassword1' ) ) ), 'changing needs the current password' );
check( 'bd_current' === code_of( bd_h_password( array( 'token' => $reg['token'], 'password' => 'newpassword1', 'current' => 'nope' ) ) ), 'wrong current password refused' );
check( 'bd_nothing' === code_of( bd_h_password( array( 'token' => $reg['token'], 'current' => 'longenough' ) ) ), 'nothing to change' );
bd_h_register( array( 'email' => 'taken@example.com', 'password' => 'longenough', 'player' => 'p_device00004' ) );
check( 'bd_email_taken' === code_of( bd_h_password( array( 'token' => $reg['token'], 'current' => 'longenough', 'email' => 'taken@example.com' ) ) ), 'changing to a used email refused' );
$em = bd_h_password( array( 'token' => $reg['token'], 'current' => 'longenough', 'email' => 'New@Example.com' ) );
check( ! is_wp_error( $em ) && 'new@example.com' === $em['account']['email'], 'email change works' );
check( 'ok' === code_of( bd_h_me( array( 'token' => $dev2['token'] ) ) ), 'email change keeps other devices signed in' );
$pw = bd_h_password( array( 'token' => $reg['token'], 'current' => 'longenough', 'password' => 'newpassword1' ) );
check( ! is_wp_error( $pw ), 'password change works' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $dev2['token'] ) ) ), 'password change signs out other devices' );
check( 'ok' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ), 'password change keeps this device signed in' );
check( ! is_wp_error( bd_h_login( array( 'email' => 'new@example.com', 'password' => 'newpassword1' ) ) ), 'new email and password sign in' );

/* ---------- Password reset ---------- */

$GLOBALS['t_transients'] = array();
$GLOBALS['t_mail']       = array();
$other                   = bd_h_login( array( 'email' => 'new@example.com', 'password' => 'newpassword1' ) );
check( array( 'ok' => true ) === bd_h_reset_request( array( 'email' => 'nobody@example.com' ) ) && 0 === count( $GLOBALS['t_mail'] ), 'reset request for unknown email says ok and sends nothing' );
bd_h_reset_request( array( 'email' => 'new@example.com' ) );
check( 1 === count( $GLOBALS['t_mail'] ), 'reset code is emailed' );
$code = mail_code();
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'new@example.com', 'code' => wrong_code( $code ), 'password' => 'brandnewpass' ) ) ), 'wrong code refused' );
check( 'bd_password' === code_of( bd_h_reset( array( 'email' => 'new@example.com', 'code' => $code, 'password' => 'short' ) ) ), 'short new password refused' );
$done = bd_h_reset( array( 'email' => 'new@example.com', 'code' => $code, 'password' => 'brandnewpass' ) );
check( ! is_wp_error( $done ) && preg_match( '/^[0-9a-f]{64}$/', $done['token'] ), 'right code resets and signs in' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ) && 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $other['token'] ) ) ), 'reset signs out every other device' );
check( 'bd_login' === code_of( bd_h_login( array( 'email' => 'new@example.com', 'password' => 'newpassword1' ) ) ), 'old password no longer works' );
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'new@example.com', 'code' => $code, 'password' => 'anotherpass1' ) ) ), 'a code works only once' );

$GLOBALS['t_transients'] = array();
bd_h_reset_request( array( 'email' => 'new@example.com' ) );
$code2 = mail_code();
for ( $i = 0; $i < 5; $i++ ) {
	bd_h_reset( array( 'email' => 'new@example.com', 'code' => wrong_code( $code2 ), 'password' => 'anotherpass1' ) );
}
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'new@example.com', 'code' => $code2, 'password' => 'anotherpass1' ) ) ), 'code is dead after 5 wrong tries' );

$key = bd_reset_key( 'new@example.com' );
bd_h_reset_request( array( 'email' => 'new@example.com' ) );
$GLOBALS['t_transients'][ $key ][0]['exp'] = time() - 1;
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'new@example.com', 'code' => mail_code(), 'password' => 'anotherpass1' ) ) ), 'expired code refused' );

/* ---------- The Exchange ---------- */

fresh();
update_option( 'brighter_days_accounts', '1' );
check( array( 'rows' => array() ) === bd_h_board( array() ), 'empty board lists nothing' );
check( 'bd_signed_out' === code_of( bd_h_board_post( array( 'entry' => array( 'name' => 'Ghost' ) ) ) ), 'listing needs a signed-in account' );
$b1 = bd_h_register( array( 'email' => 'b1@example.com', 'password' => 'longenough', 'player' => 'p_board00001' ) );
$b2 = bd_h_register( array( 'email' => 'b2@example.com', 'password' => 'longenough', 'player' => 'p_board00002' ) );
check( 'bd_name' === code_of( bd_h_board_post( array( 'token' => $b1['token'], 'entry' => array( 'name' => '<b>x</b>' ) ) ) ), 'bad shop name refused' );
check( 'bd_entry' === code_of( bd_h_board_post( array( 'token' => $b1['token'], 'entry' => 'nope' ) ) ), 'non-object listing refused' );
$p1 = bd_h_board_post(
	array(
		'token' => $b1['token'],
		'entry' => array(
			'name'   => 'Tess',
			'ticker' => 'te$s!',
			'worth'  => 900,
			'rep'    => 250,
			'day'    => -5,
			'tro'    => 5000,
			'extra'  => 'dropped',
		),
	)
);
check( ! is_wp_error( $p1 ) && preg_match( '/^[0-9a-f]{16}$/', $p1['id'] ), 'listing returns a public id' );
bd_h_board_post( array( 'token' => $b2['token'], 'entry' => array( 'name' => 'Bram', 'worth' => 1500 ) ) );
bd_h_board_post( array( 'token' => $b2['token'], 'entry' => array( 'name' => 'Bram', 'worth' => 1600 ) ) );
$rows = bd_h_board( array() )['rows'];
check( 2 === count( $rows ) && 'Bram' === $rows[0]['name'] && 1600 === $rows[0]['worth'], 'one row per player, richest first, updates replace' );
check( 'TES' === $rows[1]['ticker'] && 100 === $rows[1]['rep'] && 1 === $rows[1]['day'] && 999 === $rows[1]['tro'], 'values are cleaned and clamped' );
check( ! isset( $rows[1]['extra'] ) && $p1['id'] === $rows[1]['id'], 'unknown fields dropped, id matches' );
check( false === strpos( wp_json_encode( $rows ), 'p_board' ), 'player IDs are never shown' );
bd_h_board_delist( array( 'token' => $b1['token'] ) );
$rows = bd_h_board( array() )['rows'];
check( 1 === count( $rows ) && 'Bram' === $rows[0]['name'], 'delist removes only your own shop' );

/* ---------- Deletion ---------- */

fresh();
update_option( 'brighter_days_accounts', '1' );
$d1 = bd_h_register( array( 'email' => 'gone@example.com', 'password' => 'longenough', 'player' => 'p_device00010' ) );
bd_h_board_post( array( 'token' => $d1['token'], 'entry' => array( 'name' => 'Goner', 'worth' => 5 ) ) );
$d2 = bd_h_login( array( 'email' => 'gone@example.com', 'password' => 'longenough' ) );
check( 'bd_confirm' === code_of( bd_h_delete( array( 'token' => $d1['token'], 'confirm' => 'yes' ) ) ), 'delete needs DELETE' );
check( ! is_wp_error( bd_h_delete( array( 'token' => $d1['token'], 'confirm' => 'DELETE' ) ) ), 'delete succeeds' );
$st = $GLOBALS['bd_account_store'];
check( null === $st->account_by( 'player_id', 'p_device00010' ) && 0 === $st->count_accounts(), 'account row removed' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $d2['token'] ) ) ) && 0 === count( $st->sessions ), 'all sessions removed' );
check( array() === bd_h_board( array() )['rows'], 'the Exchange listing is removed' );
check( 'brighter_days_account_deleted' === $GLOBALS['t_actions'][0][0] && 'p_device00010' === $GLOBALS['t_actions'][0][1][0], 'deletion hook fires with the player ID for scores, friends and backups' );
check( ! is_wp_error( bd_h_register( array( 'email' => 'gone@example.com', 'password' => 'longenough', 'player' => 'p_device00011' ) ) ), 'the email can be used again after deletion' );

/* ---------- Old provider routes are gone ---------- */

$routes = array_keys( bd_account_routes() );
check( ! in_array( 'account/provider', $routes, true ) && ! in_array( 'account/unlink', $routes, true ), 'no provider or unlink routes' );
check( ! function_exists( 'bd_verify_id_token' ), 'no ID token code is loaded' );

/* ---------- Result ---------- */

$total = $GLOBALS['t_pass'] + count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} / $total checks passed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
