<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: JSON Web Tokens signed with RS256 (a PEM private key), for the clients that sign in with one: Entra ID's certificate credential (a client
    assertion with the certificate's thumbprint), Okta's private key JWT. SHA-256 signatures (RHEL 9's crypto policy refuses SHA-1 ones; a thumbprint is only a hash).

    Jwt::rs256($claims, $pem, $header)    the token: base64url(header).base64url(claims).base64url(signature); \RuntimeException when the key is not one
    Jwt::b64($s)                          base64url without padding
    Jwt::thumbprint($certPem, $algo)      a certificate's thumbprint (sha1 or sha256 of its DER), base64url: x5t / x5t#S256
*/

namespace util;

class Jwt {
    public static function b64($s) {
	return rtrim(strtr(base64_encode((string)$s), '+/', '-_'), '=');
    }

    public static function rs256(array $claims, $pem, array $header = []) {
	$key = openssl_pkey_get_private(trim((string)$pem));
	if ($key === false)
	    throw new \RuntimeException('the private key is not a PEM private key');
	$h = self::b64(json_encode($header + [ 'alg' => 'RS256', 'typ' => 'JWT' ], JSON_UNESCAPED_SLASHES));
	$c = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
	if (!openssl_sign("{$h}.{$c}", $sig, $key, OPENSSL_ALGO_SHA256))
	    throw new \RuntimeException('the token could not be signed with the private key');
	return "{$h}.{$c}." . self::b64($sig);
    }

    public static function thumbprint($certPem, $algo = 'sha1') {
	$fp = openssl_x509_fingerprint(trim((string)$certPem), $algo, true);
	if ($fp === false)
	    throw new \RuntimeException('the certificate is not a PEM certificate');
	return self::b64($fp);
    }
}
