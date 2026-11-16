<?php
/**
 * Verifies Google, Apple and Microsoft ID tokens without any SDK.
 *
 * Checks: RS256 signature against the provider's published keys (cached for
 * 6 hours), issuer, audience (one of this site's configured client IDs) and
 * expiry with 5 minutes of leeway.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BD_TOKEN_LEEWAY', 300 );
define( 'BD_JWKS_CACHE', 6 * HOUR_IN_SECONDS );

function bd_id_providers() {
	return array(
		'google'    => array(
			'label' => 'Google',
			'jwks'  => 'https://www.googleapis.com/oauth2/v3/certs',
			'iss'   => array( 'https://accounts.google.com', 'accounts.google.com' ),
		),
		'apple'     => array(
			'label' => 'Apple',
			'jwks'  => 'https://appleid.apple.com/auth/keys',
			'iss'   => array( 'https://appleid.apple.com' ),
		),
		'microsoft' => array(
			'label' => 'Microsoft',
			'jwks'  => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
			'iss'   => 'microsoft', // Tenant-specific, checked against the tid claim.
		),
	);
}

function bd_b64url_decode( $s ) {
	$s   = strtr( (string) $s, '-_', '+/' );
	$pad = strlen( $s ) % 4;
	if ( $pad ) {
		$s .= str_repeat( '=', 4 - $pad );
	}
	$out = base64_decode( $s, true );
	return false === $out ? '' : $out;
}

function bd_der_len( $len ) {
	if ( $len < 128 ) {
		return chr( $len );
	}
	$b = ltrim( pack( 'N', $len ), "\0" );
	return chr( 0x80 | strlen( $b ) ) . $b;
}

function bd_der_int( $bytes ) {
	$bytes = ltrim( $bytes, "\0" );
	if ( '' === $bytes || ord( $bytes[0] ) > 0x7f ) {
		$bytes = "\0" . $bytes;
	}
	return "\x02" . bd_der_len( strlen( $bytes ) ) . $bytes;
}

/** Builds a PEM public key from a JWK's modulus (n) and exponent (e). */
function bd_jwk_to_pem( $n, $e ) {
	$n = bd_b64url_decode( $n );
	$e = bd_b64url_decode( $e );
	if ( '' === $n || '' === $e ) {
		return '';
	}
	$seq  = bd_der_int( $n ) . bd_der_int( $e );
	$rsa  = "\x30" . bd_der_len( strlen( $seq ) ) . $seq;
	$bit  = "\x03" . bd_der_len( strlen( $rsa ) + 1 ) . "\0" . $rsa;
	$alg  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
	$spki = "\x30" . bd_der_len( strlen( $alg . $bit ) ) . $alg . $bit;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

/** Fetches a provider's key set. Tests replace this through the filter. */
function bd_fetch_jwks( $url ) {
	$pre = apply_filters( 'brighter_days_jwks_response', null, $url );
	if ( null !== $pre ) {
		return is_array( $pre ) ? $pre : array();
	}
	$res = wp_remote_get( $url, array( 'timeout' => 8 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return ( is_array( $data ) && isset( $data['keys'] ) && is_array( $data['keys'] ) ) ? $data : array();
}

/** Finds the key with this kid, refreshing the cache once if it is missing (key rotation). */
function bd_find_jwk( $provider, $url, $kid ) {
	if ( '' === $kid ) {
		return null;
	}
	$cache_key = 'bd_jwks_' . $provider;
	for ( $round = 0; $round < 2; $round++ ) {
		$set = get_transient( $cache_key );
		if ( ! is_array( $set ) || 1 === $round ) {
			if ( 1 === $round && get_transient( $cache_key . '_fresh' ) ) {
				return null; // Fetched moments ago; don't hammer the provider.
			}
			$set = bd_fetch_jwks( $url );
			if ( ! empty( $set['keys'] ) ) {
				set_transient( $cache_key, $set, BD_JWKS_CACHE );
				set_transient( $cache_key . '_fresh', 1, 5 * MINUTE_IN_SECONDS );
			}
		}
		foreach ( (array) ( $set['keys'] ?? array() ) as $k ) {
			if ( is_array( $k ) && ( $k['kid'] ?? '' ) === $kid && ( $k['kty'] ?? '' ) === 'RSA' && ! empty( $k['n'] ) && ! empty( $k['e'] ) ) {
				return $k;
			}
		}
	}
	return null;
}

/** Reads the unverified subject, used only as a rate-limit key. */
function bd_jwt_peek_sub( $jwt ) {
	$parts = explode( '.', (string) $jwt );
	if ( 3 !== count( $parts ) ) {
		return '';
	}
	$c = json_decode( bd_b64url_decode( $parts[1] ), true );
	return ( is_array( $c ) && isset( $c['sub'] ) && is_string( $c['sub'] ) ) ? substr( $c['sub'], 0, 190 ) : '';
}

/**
 * Verifies an ID token. Returns array( 'sub' => ..., 'email' => ... ) or WP_Error.
 * The email is only returned when the provider says it is verified.
 *
 * @param string   $provider  google, apple or microsoft.
 * @param string   $jwt       The ID token.
 * @param string[] $audiences This site's client IDs for that provider.
 */
function bd_verify_id_token( $provider, $jwt, $audiences ) {
	$fail      = new WP_Error( 'bd_token', 'Sign-in didn\'t work. Try again.', array( 'status' => 401 ) );
	$providers = bd_id_providers();
	if ( ! isset( $providers[ $provider ] ) ) {
		return $fail;
	}
	$p     = $providers[ $provider ];
	$parts = explode( '.', (string) $jwt );
	if ( 3 !== count( $parts ) ) {
		return $fail;
	}
	$head   = json_decode( bd_b64url_decode( $parts[0] ), true );
	$claims = json_decode( bd_b64url_decode( $parts[1] ), true );
	$sig    = bd_b64url_decode( $parts[2] );
	if ( ! is_array( $head ) || ! is_array( $claims ) || '' === $sig ) {
		return $fail;
	}
	if ( 'RS256' !== ( $head['alg'] ?? '' ) ) {
		return $fail;
	}
	$key = bd_find_jwk( $provider, $p['jwks'], (string) ( $head['kid'] ?? '' ) );
	if ( ! $key ) {
		return $fail;
	}
	$pem = bd_jwk_to_pem( $key['n'], $key['e'] );
	if ( '' === $pem || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256 ) ) {
		return $fail;
	}

	$iss = (string) ( $claims['iss'] ?? '' );
	if ( 'microsoft' === $p['iss'] ) {
		if ( ! preg_match( '#^https://login\.microsoftonline\.com/([0-9a-f-]{36})/v2\.0$#', $iss, $m ) || ( $claims['tid'] ?? '' ) !== $m[1] ) {
			return $fail;
		}
	} elseif ( ! in_array( $iss, $p['iss'], true ) ) {
		return $fail;
	}

	$aud       = $claims['aud'] ?? '';
	$auds      = is_array( $aud ) ? $aud : array( $aud );
	$audiences = array_values( array_filter( array_map( 'strval', (array) $audiences ) ) );
	if ( ! $audiences || ! array_intersect( array_map( 'strval', $auds ), $audiences ) ) {
		return $fail;
	}

	$now = time();
	if ( ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || (int) $claims['exp'] + BD_TOKEN_LEEWAY < $now ) {
		return new WP_Error( 'bd_token_expired', 'That sign-in took too long. Try again.', array( 'status' => 401 ) );
	}
	if ( isset( $claims['nbf'] ) && (int) $claims['nbf'] - BD_TOKEN_LEEWAY > $now ) {
		return $fail;
	}
	if ( isset( $claims['iat'] ) && (int) $claims['iat'] - BD_TOKEN_LEEWAY > $now ) {
		return $fail;
	}
	$sub = isset( $claims['sub'] ) && is_string( $claims['sub'] ) ? $claims['sub'] : '';
	if ( '' === $sub || strlen( $sub ) > 190 ) {
		return $fail;
	}

	$email    = '';
	$verified = $claims['email_verified'] ?? false;
	if ( 'microsoft' !== $provider && ( true === $verified || 'true' === $verified ) && ! empty( $claims['email'] ) ) {
		$e = strtolower( trim( (string) $claims['email'] ) );
		if ( strlen( $e ) <= 190 && is_email( $e ) ) {
			$email = $e;
		}
	}
	return array(
		'sub'   => $sub,
		'email' => $email,
	);
}
