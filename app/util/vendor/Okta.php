<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: the client of Okta's management API (https://<org>.okta.com/api/v1), for the Okta credential, steps and event trigger.
	auth     an API token (SSWS; it acts as the admin who made it), or an OAuth 2.0 service app (client credentials with a private key JWT: a token with the scopes asked,
	         okta.users.manage, okta.groups.manage, …; kept for the process until shortly before it ends; the app's DPoP requirement must be off)
	answers  JSON; errors { errorCode, errorSummary, errorCauses: [ { errorSummary } ] }
	lists    pages through the Link header (rel="next"), limit 200
	limits   a 429 waits until x-rate-limit-reset while the timeout allows
    $o = new Okta($cred, $aid, $timeout);
    $o->request($method, $path, $query, $body, $opt)   the decoded answer ([] when empty; $opt: full, ok)
    $o->pages($path, $query, $max)                     a list's items through its pages: [ items, truncated ]
    Okta::verify($header, $secret)                     an event hook's Authorization header

    Okta::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of the org's address
*/

namespace util\vendor;

use util\Http;
use util\Jwt;

class Okta {
    const MAX_ANSWER = 20 * 1024 * 1024;
    const PAGE = 200;
    const MAX_WAIT = 60;
    const SCOPES = 'okta.users.manage okta.groups.manage okta.apps.manage okta.logs.read okta.users.read okta.groups.read okta.apps.read';

    public static $testing = null;
    private static $tokens = [];

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the org's address: https://acme.okta.com (or a custom domain), no trailing /
    public function org() {
	return self::$testing ?? rtrim(trim((string)$this->cred->orgUrl), '/');
    }

    public function remaining() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    protected function left() {
	$left = $this->remaining();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function options() {
	return [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'compressed' => true ];
    }

    //the Authorization header's value
    protected function authorization($fresh = false) {
	if (($this->cred->auth ?? 'token') !== 'oauth')
	    return 'SSWS ' . trim((string)$this->cred->apiToken);
	$url = $this->org() . '/oauth2/v1/token';
	$scopes = trim((string)($this->cred->scopes ?? '')) ?: self::SCOPES;
	$key = $url . '|' . trim((string)$this->cred->clientId) . '|' . $scopes;
	if (!$fresh && isset(self::$tokens[$key]) && self::$tokens[$key][1] > time() + 120)
	    return 'Bearer ' . self::$tokens[$key][0];
	$cid = trim((string)$this->cred->clientId);
	$assertion = Jwt::rs256([ 'aud' => $url, 'iss' => $cid, 'sub' => $cid, 'jti' => bin2hex(random_bytes(16)), 'iat' => time(), 'exp' => time() + 300 ], (string)$this->cred->privateKey,
	    trim((string)($this->cred->keyId ?? '')) !== '' ? [ 'kid' => trim((string)$this->cred->keyId) ] : []);
	$r = Http::json('POST', $url, [ 'form' => [ 'grant_type' => 'client_credentials', 'scope' => $scopes, 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
	    'client_assertion' => $assertion ], 'headers' => [ 'Accept' => 'application/json' ], 'timeout' => min(30, $this->left()) ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from the sign-in: ' . $r['error']);
	if ($r['status'] >= 400 || empty($r['data']['access_token'])) {
	    $d = (array)$r['data'];
	    $m = trim((string)($d['error_description'] ?? $d['error'] ?? ''));
	    if (($d['error'] ?? '') === 'invalid_dpop_proof' || stripos($m, 'DPoP') !== false)
		$m .= ' (turn off "Require Demonstrating Proof of Possession (DPoP)" in the app\'s settings)';
	    throw new \RuntimeException('the sign-in was refused' . ($m !== '' ? ": {$m}" : " (HTTP status {$r['status']})"));
	}
	self::$tokens[$key] = [ (string)$r['data']['access_token'], time() + (int)($r['data']['expires_in'] ?? 3600) ];
	return 'Bearer ' . self::$tokens[$key][0];
    }

    const HINTS = [ 401 => 'the API token (or the app\'s token) is refused', 403 => 'not allowed: the admin\'s role, or the app\'s scopes and admin role', 404 => 'not found' ];

    public static function message($d, $status) {
	$d = is_array($d) ? $d : [];
	$m = trim((string)($d['errorSummary'] ?? $d['error_description'] ?? ''));
	$causes = array_values(array_filter(array_map(fn($c) => trim((string)($c['errorSummary'] ?? '')), (array)($d['errorCauses'] ?? []))));
	$text = $m !== '' ? $m : (self::HINTS[$status] ?? '');
	if ($causes)
	    $text .= ' (' . implode('; ', array_slice($causes, 0, 5)) . ')';
	$code = (string)($d['errorCode'] ?? '');
	return mb_substr("HTTP status {$status}" . ($code !== '' ? " ({$code})" : '') . ($text !== '' ? ": {$text}" : ''), 0, 1000);
    }

    public function url($path, array $query = []) {
	$path = (string)$path;
	if (preg_match('~^https?://~i', $path)) {
	    if (!str_starts_with($path, $this->org() . '/api/v1/'))
		throw new \RuntimeException('not an address of the Okta org\'s API: `' . mb_substr($path, 0, 100) . '`');
	    $url = $path;
	} else {
	    $path = '/' . ltrim(preg_replace('~^/?api/v1~', '', $path), '/');
	    if (str_contains($path, '..') || !preg_match('#^/[A-Za-z0-9/_.,:~@%+=-]*$#', $path))
		throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	    $url = $this->org() . '/api/v1' . $path;
	}
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	return $url . ($q !== '' ? (str_contains($url, '?') ? '&' : '?') . $q : '');
    }

    public function request($method, $path, array $query = [], $body = null, array $opt = []) {
	$method = strtoupper((string)$method);
	$url = $this->url($path, $query);
	$renewed = false;
	while (true) {
	    $req = [ 'headers' => [ 'Authorization' => $this->authorization($renewed), 'Accept' => 'application/json' ], 'timeout' => $this->left(), 'maxBytes' => self::MAX_ANSWER ] + $this->options();
	    if ($body !== null)
		$req['json'] = $body;
	    elseif (in_array($method, [ 'POST', 'PUT' ], true))
		$req['json'] = (object)[];
	    $r = Http::json($method, $url, $req);
	    if ($r['status'] === 401 && !$renewed && ($this->cred->auth ?? 'token') === 'oauth') {
		$renewed = true;
		continue;
	    }
	    if ($r['status'] === 429) {
		$wait = max(1, (int)($r['headers']['x-rate-limit-reset'] ?? 0) - time());
		if ($wait > self::MAX_WAIT || $wait >= $this->left())
		    throw new \RuntimeException("Okta's rate limit is reached (HTTP status 429): it resets in {$wait} s, more than the step's timeout leaves");
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}");
	if ($r['status'] >= 400 && !in_array($r['status'], (array)($opt['ok'] ?? []), true))
	    throw new \RuntimeException(self::message($r['data'], $r['status']));
	if (!empty($opt['full'])) {
	    //Okta sends self and next as two Link headers: both kept
	    $h = $r['headers'];
	    $links = array_map(fn($x) => (string)$x[1], array_filter((array)($r['headerList'] ?? []), fn($x) => strtolower((string)$x[0]) === 'link'));
	    if ($links)
		$h['link'] = implode(', ', $links);
	    return [ $r['status'], $h, is_array($r['data']) ? $r['data'] : (string)$r['body'] ];
	}
	return is_array($r['data']) ? $r['data'] : [];
    }

    public function pages($path, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$url = $this->url($path, $query + [ 'limit' => min(self::PAGE, $max + 1) ]);
	while (true) {
	    [ , $h, $d ] = $this->request('GET', $url, [], null, [ 'full' => true ]);
	    foreach((is_array($d) && array_is_list($d) ? $d : []) as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $next = '';
	    foreach(explode(',', (string)($h['link'] ?? '')) as $part)
		if (preg_match('/<([^>]+)>;\s*rel="next"/', $part, $m))
		    $next = $m[1];
	    if ($next === '' || !$d)
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	    $url = $next;
	}
    }

    //an event hook's Authorization header: the step's secret
    public static function verify($header, $secret) {
	$secret = (string)$secret;
	return $secret !== '' && hash_equals($secret, (string)$header);
    }
}
