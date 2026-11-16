<?php
/**
 * Plain-PHP checks for the account code. No WordPress needed: the few WP
 * functions used are stubbed below, and accounts live in an in-memory store.
 * Real RSA keys are generated so valid tokens pass and bad ones fail.
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
	public function get_error_code() {
		return $this->code;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
$GLOBALS['t_options']    = array();
$GLOBALS['t_transients'] = array();
$GLOBALS['t_filters']    = array();
$GLOBALS['t_mail']       = array();
$GLOBALS['t_actions']    = array();
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['t_options'][ $k ] = $v;
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
	return password_verify( $p, $h );
}
function is_email( $e ) {
	return false !== filter_var( $e, FILTER_VALIDATE_EMAIL );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function apply_filters( $tag, $value, ...$args ) {
	return isset( $GLOBALS['t_filters'][ $tag ] ) ? call_user_func( $GLOBALS['t_filters'][ $tag ], $value, ...$args ) : $value;
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
function wp_remote_get() {
	throw new Exception( 'Network access in tests' );
}

/* ---------- In-memory store (same methods as BD_WP_Account_Store) ---------- */

class BD_Memory_Store {
	public $accounts = array();
	public $identities = array();
	public $sessions = array();
	private $next = 1;
	public function account( $id ) {
		return $this->accounts[ $id ] ?? null;
	}
	public function account_by( $field, $value ) {
		foreach ( $this->accounts as $a ) {
			if ( null !== $a[ $field ] && (string) $a[ $field ] === (string) $value ) {
				return $a;
			}
		}
		return null;
	}
	public function add_account( $row ) {
		foreach ( array( 'email', 'player_id' ) as $u ) {
			if ( null !== ( $row[ $u ] ?? null ) && $this->account_by( $u, $row[ $u ] ) ) {
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
		foreach ( $this->identities as $k => $r ) {
			if ( (int) $r['account_id'] === (int) $id ) {
				unset( $this->identities[ $k ] );
			}
		}
		$this->delete_sessions( $id );
	}
	public function count_accounts() {
		return count( $this->accounts );
	}
	public function identity( $p, $s ) {
		return $this->identities[ $p . '|' . $s ] ?? null;
	}
	public function identities( $aid ) {
		return array_values(
			array_filter(
				$this->identities,
				function ( $r ) use ( $aid ) {
					return (int) $r['account_id'] === (int) $aid;
				}
			)
		);
	}
	public function add_identity( $p, $s, $aid, $created ) {
		$this->identities[ $p . '|' . $s ] = array(
			'provider'   => $p,
			'subject'    => $s,
			'account_id' => $aid,
			'created'    => $created,
		);
		return true;
	}
	public function delete_identity( $p, $s ) {
		unset( $this->identities[ $p . '|' . $s ] );
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
}

$plugin = dirname( __DIR__, 2 ) . '/wordpress-plugin/brighter-days/includes/';
require $plugin . 'id-tokens.php';
require $plugin . 'accounts.php';

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

/* ---------- Keys and tokens ---------- */

function b64u( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}
function new_key() {
	$k = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);
	$d = openssl_pkey_get_details( $k );
	return array( $k, $d['rsa']['n'], $d['rsa']['e'] );
}
list( $good_key, $good_n, $good_e ) = new_key();
list( $evil_key ) = new_key();
$GLOBALS['t_jwks'] = array(
	'keys' => array(
		array(
			'kty' => 'RSA',
			'kid' => 'k1',
			'alg' => 'RS256',
			'use' => 'sig',
			'n'   => b64u( $good_n ),
			'e'   => b64u( $good_e ),
		),
	),
);
$GLOBALS['t_jwks_calls'] = 0;
$GLOBALS['t_filters']['brighter_days_jwks_response'] = function ( $v, $url ) {
	$GLOBALS['t_jwks_calls']++;
	return $GLOBALS['t_jwks'];
};

function make_jwt( $claims, $key, $head = array() ) {
	$h   = b64u( json_encode( array_merge( array( 'alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT' ), $head ) ) );
	$c   = b64u( json_encode( $claims ) );
	$sig = '';
	openssl_sign( "$h.$c", $sig, $key, OPENSSL_ALGO_SHA256 );
	return "$h.$c." . b64u( $sig );
}
function google_claims( $over = array() ) {
	return array_merge(
		array(
			'iss'            => 'https://accounts.google.com',
			'aud'            => 'web-client.apps.googleusercontent.com',
			'sub'            => 'g-123',
			'email'          => 'Player@Example.com',
			'email_verified' => true,
			'iat'            => time() - 10,
			'exp'            => time() + 3600,
		),
		$over
	);
}
$aud_google = array( 'web-client.apps.googleusercontent.com', 'ios-client.apps.googleusercontent.com' );

/* ---------- ID token checks ---------- */

fresh();
$ok = bd_verify_id_token( 'google', make_jwt( google_claims(), $good_key ), $aud_google );
check( ! is_wp_error( $ok ) && 'g-123' === $ok['sub'], 'valid Google token passes' );
check( ! is_wp_error( $ok ) && 'player@example.com' === $ok['email'], 'verified email is returned, lowercased' );

$ok2 = bd_verify_id_token( 'google', make_jwt( google_claims( array( 'aud' => 'ios-client.apps.googleusercontent.com' ) ), $good_key ), $aud_google );
check( ! is_wp_error( $ok2 ), 'second configured client ID is accepted' );

check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims(), $evil_key ), $aud_google ) ), 'forged signature fails' );

$t     = make_jwt( google_claims(), $good_key );
$parts = explode( '.', $t );
$edit  = b64u( json_encode( google_claims( array( 'sub' => 'someone-else' ) ) ) );
check( is_wp_error( bd_verify_id_token( 'google', $parts[0] . '.' . $edit . '.' . $parts[2], $aud_google ) ), 'edited claims fail' );

check( 'bd_token_expired' === code_of( bd_verify_id_token( 'google', make_jwt( google_claims( array( 'exp' => time() - 400 ) ), $good_key ), $aud_google ) ), 'expired token fails' );
check( ! is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims( array( 'exp' => time() - 200 ) ), $good_key ), $aud_google ) ), 'expiry within 5 minutes of leeway passes' );
check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims( array( 'aud' => 'someone-elses-app' ) ), $good_key ), $aud_google ) ), 'wrong-app audience fails' );
check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims(), $good_key ), array() ) ), 'no configured client IDs fails' );
check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims( array( 'iss' => 'https://evil.example' ) ), $good_key ), $aud_google ) ), 'wrong issuer fails' );
check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims(), $good_key, array( 'alg' => 'HS256' ) ), $aud_google ) ), 'non-RS256 alg fails' );
check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims(), $good_key, array( 'kid' => 'unknown' ) ), $aud_google ) ), 'unknown key id fails' );
check( is_wp_error( bd_verify_id_token( 'google', 'not.a.token', $aud_google ) ), 'garbage fails' );
check( is_wp_error( bd_verify_id_token( 'google', make_jwt( google_claims( array( 'nbf' => time() + 1000 ) ), $good_key ), $aud_google ) ), 'not-yet-valid token fails' );
$unv = bd_verify_id_token( 'google', make_jwt( google_claims( array( 'email_verified' => false ) ), $good_key ), $aud_google );
check( ! is_wp_error( $unv ) && '' === $unv['email'], 'unverified email is not returned' );

$apple = bd_verify_id_token(
	'apple',
	make_jwt(
		array(
			'iss'            => 'https://appleid.apple.com',
			'aud'            => 'com.leverone.brighterdays.web',
			'sub'            => 'a-1',
			'email'          => 'x@privaterelay.appleid.com',
			'email_verified' => 'true',
			'exp'            => time() + 600,
		),
		$good_key
	),
	array( 'com.leverone.brighterdays.web' )
);
check( ! is_wp_error( $apple ) && 'x@privaterelay.appleid.com' === $apple['email'], 'valid Apple token passes' );

$tid = '9188040d-6c67-4c5b-b112-36a304b66dad';
$ms  = array(
	'iss'   => "https://login.microsoftonline.com/$tid/v2.0",
	'tid'   => $tid,
	'aud'   => 'ms-client-id',
	'sub'   => 'm-1',
	'email' => 'm@example.com',
	'exp'   => time() + 600,
);
$msv = bd_verify_id_token( 'microsoft', make_jwt( $ms, $good_key ), array( 'ms-client-id' ) );
check( ! is_wp_error( $msv ) && '' === $msv['email'], 'valid Microsoft token passes, email not trusted' );
check( is_wp_error( bd_verify_id_token( 'microsoft', make_jwt( array_merge( $ms, array( 'tid' => '00000000-0000-0000-0000-000000000000' ) ), $good_key ), array( 'ms-client-id' ) ) ), 'Microsoft issuer/tenant mismatch fails' );

$GLOBALS['t_jwks_calls'] = 0;
delete_transient( 'bd_jwks_google' );
delete_transient( 'bd_jwks_google_fresh' );
bd_verify_id_token( 'google', make_jwt( google_claims(), $good_key ), $aud_google );
bd_verify_id_token( 'google', make_jwt( google_claims(), $good_key ), $aud_google );
check( 1 === $GLOBALS['t_jwks_calls'], 'key set is cached' );

/* ---------- Accounts and sessions ---------- */

fresh();
update_option( 'brighter_days_accounts', '1' );
update_option( 'brighter_days_google_ids', implode( ',', $aud_google ) );

$cfg = bd_h_config( array() );
check( true === $cfg['enabled'] && true === $cfg['methods']['password'] && 'web-client.apps.googleusercontent.com' === $cfg['methods']['google'] && ! isset( $cfg['methods']['apple'] ), 'config lists only methods that are set up, web ID first' );

check( 'bd_password' === code_of( bd_h_register( array( 'email' => 'a@example.com', 'password' => 'short', 'player' => 'p_device00001' ) ) ), 'password under 8 characters is refused' );
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
check( 'a@example.com' === $reg['account']['email'] && true === $reg['account']['hasPassword'] && 3 === $reg['save']['day'], 'register returns the account and save' );
$store = $GLOBALS['bd_account_store'];
$sess  = array_values( $store->sessions )[0];
check( hash( 'sha256', $reg['token'] ) === $sess['token_hash'] && ! isset( $store->sessions[ $reg['token'] ] ), 'only the SHA-256 of the token is stored' );
check( abs( $sess['expires'] - ( time() + 120 * DAY_IN_SECONDS ) ) < 5, 'session lasts 120 days' );
check( 0 !== strpos( $store->accounts[1]['pass_hash'], 'longenough' ) && password_verify( 'longenough', $store->accounts[1]['pass_hash'] ), 'password is hashed' );
check( 'bd_player_taken' === code_of( bd_h_register( array( 'email' => 'b@example.com', 'password' => 'longenough', 'player' => 'p_device00001' ) ) ), 'a player owned by an account cannot be claimed again' );
check( 'bd_email_taken' === code_of( bd_h_register( array( 'email' => 'a@example.com', 'password' => 'longenough', 'player' => 'p_device00002' ) ) ), 'duplicate email refused' );
$big = array( 'blob' => str_repeat( 'x', 310 * 1024 ) );
check( 'bd_save_size' === code_of( bd_h_register( array( 'email' => 'c@example.com', 'password' => 'longenough', 'player' => 'p_device00003', 'save' => $big ) ) ), 'save over the size cap refused' );

check( 'ok' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ), 'me works with the token' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => str_repeat( 'a', 64 ) ) ) ), 'unknown token is signed out' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => 'nothex' ) ) ), 'malformed token is signed out' );

$login = bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) );
check( ! is_wp_error( $login ) && $login['token'] !== $reg['token'], 'login gives a new session' );
for ( $i = 0; $i < 10; $i++ ) {
	bd_h_login( array( 'email' => 'a@example.com', 'password' => 'wrong-password' ) );
}
check( 'bd_slow_down' === code_of( bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) ) ), '10 failed tries locks the email out' );
$keys = implode( ' ', array_keys( $GLOBALS['t_transients'] ) );
check( false === strpos( $keys, 'a@example.com' ), 'rate-limit keys do not contain the email' );

// Save sync and conflicts.
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

// Logout.
bd_h_logout( array( 'token' => $login['token'] ) );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $login['token'] ) ) ), 'logout ends the session' );
check( 'ok' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ), 'logout leaves other devices signed in' );

// Expired session.
$tok                                          = bd_new_session( 1 );
$store->sessions[ hash( 'sha256', $tok ) ]['expires'] = time() - 1;
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $tok ) ) ), 'expired session is refused' );

/* ---------- Password reset ---------- */

$GLOBALS['t_transients'] = array();
$other                   = bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) );
check( array( 'ok' => true ) === bd_h_reset_request( array( 'email' => 'nobody@example.com' ) ) && 0 === count( $GLOBALS['t_mail'] ), 'reset request for unknown email says ok and sends nothing' );
bd_h_reset_request( array( 'email' => 'a@example.com' ) );
check( 1 === count( $GLOBALS['t_mail'] ), 'reset code is emailed' );
preg_match( '/(\d{6})/', $GLOBALS['t_mail'][0][2], $m );
$code  = $m[1];
$wrong = '000000' === $code ? '111111' : '000000';
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'a@example.com', 'code' => $wrong, 'password' => 'brandnewpass' ) ) ), 'wrong code refused' );
$done = bd_h_reset( array( 'email' => 'a@example.com', 'code' => $code, 'password' => 'brandnewpass' ) );
check( ! is_wp_error( $done ) && preg_match( '/^[0-9a-f]{64}$/', $done['token'] ), 'right code resets and signs in' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $reg['token'] ) ) ) && 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $other['token'] ) ) ), 'reset signs out every other device' );
check( 'bd_login' === code_of( bd_h_login( array( 'email' => 'a@example.com', 'password' => 'longenough' ) ) ), 'old password no longer works' );
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'a@example.com', 'code' => $code, 'password' => 'anotherpass1' ) ) ), 'a code works only once' );

$GLOBALS['t_transients'] = array();
bd_h_reset_request( array( 'email' => 'a@example.com' ) );
preg_match( '/(\d{6})/', end( $GLOBALS['t_mail'] )[2], $m );
$code2  = $m[1];
$wrong2 = '000000' === $code2 ? '111111' : '000000';
for ( $i = 0; $i < 5; $i++ ) {
	bd_h_reset( array( 'email' => 'a@example.com', 'code' => $wrong2, 'password' => 'anotherpass1' ) );
}
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'a@example.com', 'code' => $code2, 'password' => 'anotherpass1' ) ) ), 'code is dead after 5 wrong tries' );

$key = bd_reset_key( 'a@example.com' );
bd_h_reset_request( array( 'email' => 'a@example.com' ) );
$GLOBALS['t_transients'][ $key ][0]['exp'] = time() - 1;
check( 'bd_code' === code_of( bd_h_reset( array( 'email' => 'a@example.com', 'code' => '123456', 'password' => 'anotherpass1' ) ) ), 'expired code refused' );

/* ---------- Providers, linking, unlinking, deletion ---------- */

fresh();
update_option( 'brighter_days_accounts', '1' );
update_option( 'brighter_days_google_ids', implode( ',', $aud_google ) );
update_option( 'brighter_days_apple_ids', '' );

check( 'bd_method' === code_of( bd_h_provider( array( 'provider' => 'apple', 'credential' => 'x.y.z', 'player' => 'p_device00009' ) ) ), 'method without a setting is refused' );

$g = bd_h_provider(
	array(
		'provider'   => 'google',
		'credential' => make_jwt( google_claims(), $good_key ),
		'player'     => 'p_device00010',
	)
);
check( ! is_wp_error( $g ) && array( 'google' ) === $g['account']['providers'] && false === $g['account']['hasPassword'], 'first Google sign-in creates an account' );
check( 'player@example.com' === $g['account']['email'], 'provider email stored when unused' );

$g2 = bd_h_provider(
	array(
		'provider'   => 'google',
		'credential' => make_jwt( google_claims(), $good_key ),
		'player'     => 'p_otherdevice1',
	)
);
check( ! is_wp_error( $g2 ) && 'p_device00010' === $g2['account']['player'], 'same Google account signs in to the same player' );

check( 'bd_last_method' === code_of( bd_h_unlink( array( 'token' => $g['token'], 'provider' => 'google' ) ) ), 'removing the last way to sign in is refused' );

$reg2 = bd_h_register( array( 'email' => 'second@example.com', 'password' => 'longenough', 'player' => 'p_device00011' ) );
$link = bd_h_provider(
	array(
		'provider'   => 'google',
		'credential' => make_jwt( google_claims(), $good_key ),
		'token'      => $reg2['token'],
	)
);
check( 'bd_linked_elsewhere' === code_of( $link ), 'a method linked to another account is refused' );

$link2 = bd_h_provider(
	array(
		'provider'   => 'google',
		'credential' => make_jwt(
			google_claims(
				array(
					'sub'   => 'g-999',
					'email' => 'player@example.com',
				)
			),
			$good_key
		),
		'token'      => $reg2['token'],
	)
);
check( ! is_wp_error( $link2 ) && array( 'google' ) === $link2['account']['providers'] && 'second@example.com' === $link2['account']['email'], 'linking adds the method and keeps the existing email' );

$third = bd_h_provider(
	array(
		'provider'   => 'google',
		'credential' => make_jwt(
			google_claims(
				array(
					'sub'   => 'g-777',
					'email' => 'second@example.com',
				)
			),
			$good_key
		),
		'player'     => 'p_device00012',
	)
);
check( ! is_wp_error( $third ) && '' === $third['account']['email'], 'provider email already used elsewhere is not stored' );

$pw = bd_h_password(
	array(
		'token'    => $g['token'],
		'password' => 'setapassword',
	)
);
check( ! is_wp_error( $pw ) && true === $pw['account']['hasPassword'], 'provider-only account can set a password' );
$un = bd_h_unlink( array( 'token' => $g['token'], 'provider' => 'google' ) );
check( ! is_wp_error( $un ) && array() === $un['account']['providers'], 'unlink works once a password exists' );
check( 'bd_current' === code_of( bd_h_password( array( 'token' => $g['token'], 'password' => 'yetanother1', 'current' => 'wrong' ) ) ), 'changing a password needs the current one' );

check( 'bd_confirm' === code_of( bd_h_delete( array( 'token' => $g['token'], 'confirm' => 'yes' ) ) ), 'delete needs DELETE' );
$del = bd_h_delete( array( 'token' => $g['token'], 'confirm' => 'DELETE' ) );
check( ! is_wp_error( $del ), 'delete succeeds' );
$st = $GLOBALS['bd_account_store'];
check( null === $st->account_by( 'player_id', 'p_device00010' ), 'account row removed' );
check( null === $st->identity( 'google', 'g-123' ), 'identities removed' );
check( 'bd_signed_out' === code_of( bd_h_me( array( 'token' => $g2['token'] ) ) ), 'all sessions removed' );
check( 'brighter_days_account_deleted' === $GLOBALS['t_actions'][0][0] && 'p_device00010' === $GLOBALS['t_actions'][0][1][0], 'deletion hook fires with the player ID for scores, friends and backups' );

update_option( 'brighter_days_accounts', '0' );
check( false === bd_h_config( array() )['enabled'], 'config says off when accounts are switched off' );

/* ---------- Result ---------- */

$total = $GLOBALS['t_pass'] + count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} / $total checks passed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
