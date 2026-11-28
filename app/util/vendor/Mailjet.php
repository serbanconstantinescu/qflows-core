<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: the client of Mailjet's APIs (https://api.mailjet.com: /v3.1/send to send, /v3/REST/... for the rest, /v4 for deleting contacts), for the Mailjet
    credential and steps: Basic sign-in with the API key and the secret key (Account settings > API key management); the account's region (Global: api.mailjet.com, US:
    api.us.mailjet.com). Errors: { ErrorMessage, ErrorInfo, StatusCode }, or a send's { Messages: [ { Status: error, Errors: [ { ErrorMessage, ErrorRelatedTo } ] } ] }.
    As util\EmailApi
*/

namespace util\vendor;

use util\EmailApi;

class Mailjet extends EmailApi {
    const NAME = 'Mailjet';
    const REGIONS = [ 'global' => 'https://api.mailjet.com', 'us' => 'https://api.us.mailjet.com' ];

    public static $testing = null;

    public function base() {
	return static::$testing ?? self::REGIONS[(string)($this->cred->region ?? 'global')] ?? self::REGIONS['global'];
    }

    protected function headers($method, $url, $payload) {
	return [ 'Authorization' => 'Basic ' . base64_encode(trim((string)$this->cred->apiKey) . ':' . trim((string)$this->cred->secretKey)), 'Accept' => 'application/json' ];
    }

    protected static function errorText($d, array $r) {
	if (!is_array($d))
	    return '';
	if (isset($d['Messages']) && is_array($d['Messages'])) {
	    $out = [];
	    foreach($d['Messages'] as $m)
		foreach((array)($m['Errors'] ?? []) as $e)
		    $out[] = trim((string)($e['ErrorMessage'] ?? '')) . (!empty($e['ErrorRelatedTo']) ? ' (' . implode(', ', array_map('strval', (array)$e['ErrorRelatedTo'])) . ')' : '');
	    return mb_substr(implode('; ', array_unique(array_filter($out))), 0, 1000);
	}
	$m = trim((string)($d['ErrorMessage'] ?? $d['message'] ?? ''));
	$info = trim((string)($d['ErrorInfo'] ?? ''));
	return mb_substr($m . ($info !== '' && $info !== $m ? " ({$info})" : ''), 0, 1000);
    }
}
