<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: Amazon SNS messages posted to an HTTPS subscription (the S3 object trigger's /s3/<token>, controllers\S3Controller): their signature and the
    subscription's confirmation.
	verify($m)    the message's signature: SignatureVersion 1 (SHA1; refused where the crypto policy refuses SHA1, as RHEL 9's: the topic's SignatureVersion 2 then) or 2 (SHA256)
	              with the certificate at SigningCertURL, which must be AWS's
	              (https://sns.<region>.amazonaws.com/….pem); the certificates fetched are kept (a minute's worth of messages does not fetch it again)
	confirm($m)   a SubscriptionConfirmation confirmed: a GET of its SubscribeURL (https://sns.<region>.amazonaws.com/?Action=ConfirmSubscription…)
    The string signed: the message's fields in this order, each "Name\nvalue\n": Notification: Message, MessageId, Subject (when there is one), Timestamp, TopicArn, Type;
    the confirmations: Message, MessageId, SubscribeURL, Timestamp, Token, TopicArn, Type.
    Sns::$testing: the check suite's certificate (PEM) and the address its confirmations go to (any host)
*/

namespace util\vendor;

use util\Http;

class Sns {
    const AWS_HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/';
    const MAX_CERT = 16384;
    //a message older than this (s) is refused
    const MAX_AGE = 3600;

    public static $testing = null;
    private static $certs = [];

    //the string SNS signed
    public static function signedString(array $m) {
	$fields = ($m['Type'] ?? '') === 'Notification' ? [ 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' ]
	    : [ 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' ];
	$s = '';
	foreach($fields as $f) {
	    if ($f === 'Subject' && !isset($m['Subject']))
		continue;
	    $s .= $f . "\n" . (string)($m[$f] ?? '') . "\n";
	}
	return $s;
    }

    //whether $url is an address of AWS's SNS (https, sns.<region>.amazonaws.com)
    public static function isAwsUrl($url) {
	$u = parse_url((string)$url);
	return ($u['scheme'] ?? '') === 'https' && preg_match(self::AWS_HOST, strtolower((string)($u['host'] ?? ''))) && !isset($u['user']) && !isset($u['port']);
    }

    //the message's signature, checked: '' when right, else why not
    public static function verify(array $m, $now = null) {
	$type = (string)($m['Type'] ?? '');
	if (!in_array($type, [ 'Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation' ], true))
	    return 'not an SNS message';
	$version = (string)($m['SignatureVersion'] ?? '');
	if (!in_array($version, [ '1', '2' ], true))
	    return 'an unknown SignatureVersion';
	$time = strtotime((string)($m['Timestamp'] ?? ''));
	if ($time === false || abs(($now ?? time()) - $time) > self::MAX_AGE)
	    return 'the message is too old';
	$certUrl = (string)($m['SigningCertURL'] ?? $m['SigningCertUrl'] ?? '');
	$pem = self::$testing['cert'] ?? null;
	if ($pem === null) {
	    if (!self::isAwsUrl($certUrl) || !str_ends_with(parse_url($certUrl, PHP_URL_PATH) ?? '', '.pem'))
		return 'its certificate is not AWS\'s';
	    $pem = self::$certs[$certUrl] ?? null;
	    if ($pem === null) {
		$r = Http::request('GET', $certUrl, [ 'https' => true, 'public' => true, 'timeout' => 10, 'maxBytes' => self::MAX_CERT ]);
		if ($r['error'] !== null || $r['status'] !== 200)
		    return 'its certificate could not be read';
		$pem = (string)$r['body'];
		self::$certs[$certUrl] = $pem;
	    }
	}
	$key = openssl_pkey_get_public($pem);
	if ($key === false)
	    return 'its certificate is not one';
	$sig = base64_decode((string)($m['Signature'] ?? ''), true);
	if ($sig === false || $sig === '')
	    return 'no signature';
	while (openssl_error_string() !== false);
	$ok = openssl_verify(self::signedString($m), $sig, $key, $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);
	if ($ok === 1)
	    return '';
	//SHA1 signatures refused by the system's crypto policy (RHEL 9's DEFAULT): the topic must sign with SHA256
	$errors = '';
	while (($e = openssl_error_string()) !== false)
	    $errors .= $e;
	if ($version === '1' && ($ok === -1 || str_contains($errors, 'invalid digest')))
	    return 'SignatureVersion 1 (SHA1), which this server\'s OpenSSL refuses: set the topic\'s SignatureVersion attribute to 2';
	return 'the signature does not match';
    }

    //a SubscriptionConfirmation confirmed (its SubscribeURL); \RuntimeException when it could not be
    public static function confirm(array $m) {
	$url = (string)($m['SubscribeURL'] ?? '');
	if (self::$testing === null && !self::isAwsUrl($url))
	    throw new \RuntimeException('its SubscribeURL is not AWS\'s');
	$r = Http::request('GET', $url, [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'timeout' => 15, 'maxBytes' => 65536 ]);
	if ($r['error'] !== null || $r['status'] !== 200)
	    throw new \RuntimeException('SNS did not confirm: ' . ($r['error'] ?? "HTTP status {$r['status']}"));
    }
}
