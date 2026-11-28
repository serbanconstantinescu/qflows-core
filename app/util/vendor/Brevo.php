<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: the client of Brevo's API (formerly Sendinblue; https://api.brevo.com/v3), for the Brevo credential and steps: the `api-key` header (an API key
    of the account: SMTP & API > API keys). Errors: Brevo's { code, message }. As util\EmailApi
*/

namespace util\vendor;

use util\EmailApi;

class Brevo extends EmailApi {
    const NAME = 'Brevo';
    const BASE = 'https://api.brevo.com/v3';

    public static $testing = null;

    public function base() {
	return static::$testing ?? self::BASE;
    }

    protected function headers($method, $url, $payload) {
	return [ 'api-key' => trim((string)$this->cred->apiKey), 'Accept' => 'application/json' ];
    }

    protected static function errorText($d, array $r) {
	if (!is_array($d))
	    return '';
	$m = trim((string)($d['message'] ?? ''));
	$code = trim((string)($d['code'] ?? ''));
	return mb_substr($m . ($code !== '' ? " [{$code}]" : ''), 0, 1000);
    }
}
