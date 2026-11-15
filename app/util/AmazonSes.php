<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: the client of Amazon SES's API v2 (https://email.<region>.amazonaws.com/v2/email/...), for the Amazon SES credential and steps: every request signed
    with AWS Signature Version 4 (util\AwsSigV4, service `ses`) by the credential's access key (an IAM user's or role's with the SES permissions the steps use) in its region.
    Errors: { message } and the error's type (the x-amzn-ErrorType header, or __type / code). A 429 (TooManyRequests) and a 503 are waited for. As util\EmailApi
*/

namespace util;

class AmazonSes extends EmailApi {
    const NAME = 'Amazon SES';
    const RETRY = [ 429, 503 ];
    //the regions where SES is offered (an AWS region code otherwise is taken too)
    const REGION = '/^[a-z]{2}(-gov)?-[a-z]+-\d$/';

    public static $testing = null;

    public function region() {
	$r = strtolower(trim((string)$this->cred->region));
	if (!preg_match(self::REGION, $r))
	    throw new \RuntimeException('the credential\'s region is not an AWS region code (e.g. eu-west-1)');
	return $r;
    }

    public function base() {
	return static::$testing ?? 'https://email.' . $this->region() . '.amazonaws.com';
    }

    protected function headers($method, $url, $payload) {
	return AwsSigV4::headers($method, $url, $payload !== '' ? [ 'Content-Type' => 'application/json' ] : [], $payload, [
	    'accessKeyId' => trim((string)$this->cred->accessKeyId),
	    'secretAccessKey' => trim((string)$this->cred->secretAccessKey),
	    'sessionToken' => trim((string)($this->cred->sessionToken ?? '')),
	    'region' => static::$testing === null ? $this->region() : (preg_match(self::REGION, strtolower(trim((string)$this->cred->region))) ? strtolower(trim((string)$this->cred->region)) : 'us-east-1'),
	    'service' => 'ses',
	]);
    }

    protected static function errorText($d, array $r) {
	$type = (string)($r['headers']['x-amzn-errortype'] ?? (is_array($d) ? ($d['__type'] ?? $d['code'] ?? '') : ''));
	$type = preg_replace('/[:#].*$/', '', substr(strrchr('#' . $type, '#'), 1));
	$m = is_array($d) ? trim((string)($d['message'] ?? $d['Message'] ?? '')) : '';
	return mb_substr($m . ($type !== '' ? " [{$type}]" : ''), 0, 1000);
    }
}
