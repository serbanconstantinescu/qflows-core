<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: the client of Microsoft Graph for Entra ID (users, groups, licenses, app roles, authentication methods), for the Entra ID credential, steps and
    change trigger. App-only: an app registration of the tenant with a client secret or a certificate (a client assertion: a JWT signed with its private key, its thumbprint in
    x5t) and Graph application permissions an administrator consented to. The token (client credentials, scope https://graph.microsoft.com/.default, the national cloud's
    when chosen) kept in this process until 5 minutes before it ends, asked again once on a 401. A 429 / 503 / 504 waits for Retry-After and is sent again while the timeout allows.
    Errors: Graph's { error: { code, message } }, said with a hint.

    $g = new Entra($cred, $aid, $timeout);
    $g->request($method, $path, $query, $body, $o)   the answer (decoded JSON; [] when empty): $path from /v1.0/ or /beta/ (else below /v1.0), or an @odata.nextLink / deltaLink;
                                                     $o: eventual (ConsistencyLevel: eventual, for $search / $count), full ([ status, headers, data ]), ok (statuses returned)
    $g->pages($path, $query, $max, $o)               a list's items through @odata.nextLink: [ items, truncated ]
    $g->delta($url, $max)                            a delta query to its end: [ items, deltaLink ]
    Entra::quote($v), Entra::isGuid($v)              an OData string literal; whether $v is an object's id

    Entra::$testing: the check suite's local server (http://127.0.0.1:<port>) for both the sign-in and Graph
*/

namespace util\vendor;

use util\Http;
use util\Jwt;

class Entra {
    const CLOUDS = [
	'global' => [ 'login' => 'https://login.microsoftonline.com', 'graph' => 'https://graph.microsoft.com' ],
	'usgov' => [ 'login' => 'https://login.microsoftonline.us', 'graph' => 'https://graph.microsoft.us' ],
	'china' => [ 'login' => 'https://login.chinacloudapi.cn', 'graph' => 'https://microsoftgraph.chinacloudapi.cn' ],
    ];
    const MAX_ANSWER = 20 * 1024 * 1024;
    const MAX_WAIT = 60;
    const PATH = '#^/[A-Za-z0-9/_.,:~@%+=()\'$-]*$#';
    const GUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    //a delta query's pages read at most (each up to 200 items)
    const MAX_DELTA_PAGES = 500;

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

    protected function cloud() {
	return self::CLOUDS[(string)($this->cred->cloud ?? 'global')] ?? self::CLOUDS['global'];
    }

    public function graph() {
	return self::$testing ?? $this->cloud()['graph'];
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

    public static function isGuid($v) {
	return is_string($v) && (bool)preg_match(self::GUID, $v);
    }

    //an OData string literal
    public static function quote($v) {
	return "'" . str_replace("'", "''", (string)$v) . "'";
    }

    // ------------------------------------------------------------------------------------
    //signing in

    protected function tokenUrl() {
	$tenant = trim((string)$this->cred->tenantId);
	return (self::$testing ?? $this->cloud()['login']) . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    }

    //the access token; $fresh: not the one kept
    public function token($fresh = false) {
	$key = $this->tokenUrl() . '|' . trim((string)$this->cred->clientId) . '|' . (string)($this->cred->auth ?? 'secret');
	if (!$fresh && isset(self::$tokens[$key]) && self::$tokens[$key][1] > time() + 300)
	    return self::$tokens[$key][0];
	$form = [ 'grant_type' => 'client_credentials', 'client_id' => trim((string)$this->cred->clientId), 'scope' => $this->graph() . '/.default' ];
	if (($this->cred->auth ?? 'secret') === 'certificate') {
	    $form['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
	    $form['client_assertion'] = Jwt::rs256([ 'aud' => $this->tokenUrl(), 'iss' => $form['client_id'], 'sub' => $form['client_id'], 'jti' => bin2hex(random_bytes(16)),
		'nbf' => time() - 60, 'exp' => time() + 600 ], (string)$this->cred->privateKey, [ 'x5t' => Jwt::thumbprint((string)$this->cred->certificate) ]);
	} else
	    $form['client_secret'] = (string)$this->cred->clientSecret;
	$r = Http::json('POST', $this->tokenUrl(), [ 'form' => $form, 'timeout' => min(30, $this->left()) ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from the sign-in: ' . $r['error']);
	if ($r['status'] >= 400 || empty($r['data']['access_token'])) {
	    $d = (array)$r['data'];
	    $m = trim((string)($d['error_description'] ?? $d['error'] ?? ''));
	    //AADSTS codes: the first line says it
	    $m = trim(strtok($m, "\r\n") ?: $m);
	    throw new \RuntimeException('the sign-in was refused' . ($m !== '' ? ": {$m}" : " (HTTP status {$r['status']})"));
	}
	self::$tokens[$key] = [ (string)$r['data']['access_token'], time() + (int)($r['data']['expires_in'] ?? 3599) ];
	return self::$tokens[$key][0];
    }

    // ------------------------------------------------------------------------------------
    //requests

    const HINTS = [
	'Authorization_RequestDenied' => 'the app lacks a Graph application permission, or an administrator\'s consent',
	'Request_ResourceNotFound' => 'not found',
	'ResourceNotFound' => 'not found',
	'Request_BadRequest' => 'Graph refused the values',
	'Request_MultipleObjectsWithSameKeyValue' => 'one exists with that value already',
	'Directory_QuotaExceeded' => 'the directory\'s quota is reached',
	'accessDenied' => 'the app may not do this',
    ];

    public static function message($d, $status) {
	$e = is_array($d) ? (array)($d['error'] ?? []) : [];
	$code = (string)($e['code'] ?? '');
	$m = trim((string)($e['message'] ?? ''));
	$text = $m !== '' ? $m : ([ 401 => 'the token is refused', 403 => 'not allowed: the app\'s permissions', 404 => 'not found' ][$status] ?? '');
	if (isset(self::HINTS[$code]) && stripos($text, self::HINTS[$code]) === false)
	    $text .= ($text !== '' ? ': ' : '') . self::HINTS[$code];
	return mb_substr("HTTP status {$status}" . ($code !== '' ? " ({$code})" : '') . ($text !== '' ? ": {$text}" : ''), 0, 1000);
    }

    public function url($path, array $query = []) {
	$path = (string)$path;
	if (preg_match('~^https?://~i', $path)) {
	    if (!str_starts_with($path, $this->graph() . '/'))
		throw new \RuntimeException('not an address of Microsoft Graph: `' . mb_substr($path, 0, 100) . '`');
	    $url = $path;
	} else {
	    //a query of its own (e.g. /users/delta?$select=…) kept apart from the path's check
	    [ $path, $own ] = array_pad(explode('?', $path, 2), 2, '');
	    $path = '/' . ltrim($path, '/');
	    if (!preg_match('~^/(v1\.0|beta)/~', $path))
		$path = '/v1.0' . $path;
	    if (str_contains($path, '..') || !preg_match(self::PATH, $path) || preg_match('/[\x00-\x20#]/', $own))
		throw new \RuntimeException('not a Graph path: `' . mb_substr($path, 0, 100) . '`');
	    $url = $this->graph() . $path . ($own !== '' ? "?{$own}" : '');
	}
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	return $url . ($q !== '' ? (str_contains($url, '?') ? '&' : '?') . $q : '');
    }

    public function request($method, $path, array $query = [], $body = null, array $o = []) {
	$method = strtoupper((string)$method);
	$url = $this->url($path, $query);
	$renewed = false;
	while (true) {
	    $headers = [ 'Authorization' => 'Bearer ' . $this->token($renewed), 'Accept' => 'application/json' ];
	    if (!empty($o['eventual']))
		$headers['ConsistencyLevel'] = 'eventual';
	    $req = [ 'headers' => $headers, 'timeout' => $this->left(), 'maxBytes' => self::MAX_ANSWER ] + $this->options();
	    if ($body !== null)
		$req['json'] = $body;
	    elseif (in_array($method, [ 'POST', 'PATCH', 'PUT' ], true))
		$req['body'] = '';
	    $r = Http::json($method, $url, $req);
	    if ($r['status'] === 401 && !$renewed) {
		$renewed = true;
		continue;
	    }
	    if (in_array($r['status'], [ 429, 503, 504 ], true)) {
		$wait = max(1, min(self::MAX_WAIT, (int)($r['headers']['retry-after'] ?? 5)));
		if ($wait >= $this->left())
		    throw new \RuntimeException("Graph is busy (HTTP status {$r['status']}), and the step's timeout does not leave time to wait");
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from Graph: ' . $r['error']);
	if ($r['status'] >= 400 && !in_array($r['status'], (array)($o['ok'] ?? []), true))
	    throw new \RuntimeException(self::message($r['data'], $r['status']));
	if (!empty($o['full']))
	    return [ $r['status'], $r['headers'], is_array($r['data']) ? $r['data'] : (string)$r['body'] ];
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a list's items through @odata.nextLink, at most $max
    public function pages($path, array $query, $max, array $o = []) {
	$max = max(1, (int)$max);
	$items = [];
	$d = $this->request('GET', $path, $query + [ '$top' => min(999, $max + 1) ], null, $o);
	while (true) {
	    foreach((array)($d['value'] ?? []) as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $next = (string)($d['@odata.nextLink'] ?? '');
	    if ($next === '')
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	    $d = $this->request('GET', $next, [], null, $o);
	}
    }

    //a delta query (its first address, or a deltaLink) followed to its end: [ items, the new deltaLink ]; at most $max items kept (the others: read, not kept)
    public function delta($url, $max) {
	$items = [];
	$d = $this->request('GET', $url);
	for ($page = 0; $page < self::MAX_DELTA_PAGES; $page++) {
	    foreach((array)($d['value'] ?? []) as $i)
		if (count($items) < $max)
		    $items[] = $i;
	    if (!empty($d['@odata.deltaLink']))
		return [ $items, (string)$d['@odata.deltaLink'] ];
	    $next = (string)($d['@odata.nextLink'] ?? '');
	    if ($next === '')
		break;
	    $d = $this->request('GET', $next);
	}
	throw new \RuntimeException('the delta query did not end (more than ' . self::MAX_DELTA_PAGES . ' pages)');
    }
}
