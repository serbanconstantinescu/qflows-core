<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: the client of GitLab's REST API v4 (<address>/api/v4: gitlab.com or a self-managed GitLab), for the GitLab credential, steps and event trigger.
	auth     an access token (personal, project or group; PRIVATE-TOKEN)
	answers  JSON; errors { message: text | { field: [ … ] } | [ … ], error, error_description }, said with a hint
	lists    pages through the Link header (rel="next"), per_page 100
	limits   a 429 waits for Retry-After (or RateLimit-Reset) while the step's timeout allows
	private  a self-managed GitLab on a private network only when the account allows private network addresses; its certificate checked unless Trust the certificate
    $g = new GitLab($cred, $aid, $timeout);
    $g->request($method, $path, $query, $body, $o)   the decoded answer ([] when empty; $o: raw [ body, type ], text (the body as it is), full ([ status, headers, data ]), sink, maxBytes, ok)
    $g->pages($path, $query, $max)                   a list's items through its pages: [ items, truncated ]
    $g->project($v)                                  a project's ID for a path: group/sub/project (encoded) or its number, of a setting (a URL, an item, else the credential's default)
    GitLab::message($d, $status), GitLab::verify($header, $secret)

    GitLab::$testing: the check suite's local server (http://127.0.0.1:<port>) instead of the GitLab address
*/

namespace util;

class GitLab {
    const CLOUD = 'https://gitlab.com';
    const MAX_ANSWER = 30 * 1024 * 1024;
    const PAGE = 100;
    const MAX_WAIT = 60;
    const PROJECT = '/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+){1,20}$/';

    public static $testing = null;

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

    //the GitLab's address (no trailing /): the credential's, else gitlab.com
    public function base() {
	if (self::$testing !== null)
	    return self::$testing;
	$u = rtrim(trim((string)($this->cred->baseUrl ?? '')), '/');
	return $u !== '' ? $u : self::CLOUD;
    }

    public function api() {
	return $this->base() . '/api/v4';
    }

    public function isCloud() {
	return self::$testing === null && $this->base() === self::CLOUD;
    }

    //the seconds the step's timeout still leaves
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
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [ 'https' => self::$testing === null, 'public' => self::$testing === null, 'private' => !$this->isCloud() && (bool)($account->allow_private_addresses ?? false),
	    'insecure' => !$this->isCloud() && (bool)($this->cred->trustCertificate ?? false), 'compressed' => true ];
    }

    const HINTS = [ 401 => 'the token is refused (wrong, expired or revoked)', 403 => 'not allowed: the token\'s scopes, or its role in the project', 404 => 'not found, or the token may not see it',
	405 => 'not allowed in this state', 409 => 'a conflict', 422 => 'GitLab refused the values' ];

    //GitLab's error said
    public static function message($d, $status) {
	$m = '';
	if (is_array($d)) {
	    $msg = $d['message'] ?? $d['error_description'] ?? $d['error'] ?? '';
	    if (is_array($msg)) {
		$parts = [];
		foreach($msg as $k => $v)
		    $parts[] = (is_string($k) ? "{$k} " : '') . (is_array($v) ? implode(', ', array_map('strval', array_filter($v, 'is_scalar'))) : (string)$v);
		$m = implode('; ', array_slice($parts, 0, 5));
	    } else
		$m = trim((string)$msg);
	}
	$hint = self::HINTS[$status] ?? '';
	$text = $m !== '' ? $m : $hint;
	if ($m !== '' && $hint !== '' && in_array($status, [ 401, 403, 404 ], true) && stripos($m, $hint) === false)
	    $text .= ": {$hint}";
	return mb_substr("HTTP status {$status}" . ($text !== '' ? ": {$text}" : ''), 0, 1000);
    }

    //the address of $path (below /api/v4, or an address of it: a list's next page)
    public function url($path, array $query = []) {
	$path = (string)$path;
	if (preg_match('~^https?://~i', $path)) {
	    if (!str_starts_with($path, $this->api() . '/'))
		throw new \RuntimeException('not an address of the GitLab API: `' . mb_substr($path, 0, 100) . '`');
	    $url = $path;
	} else {
	    $path = '/' . ltrim(preg_replace('~^/?api/v4~', '', $path), '/');
	    if (str_contains($path, '..') || !preg_match('#^/[A-Za-z0-9/_.,:~@%+=-]*$#', $path))
		throw new \RuntimeException('not an API path: `' . mb_substr($path, 0, 100) . '`');
	    $url = $this->api() . $path;
	}
	$q = http_build_query(array_filter($query, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
	return $url . ($q !== '' ? (str_contains($url, '?') ? '&' : '?') . $q : '');
    }

    public function request($method, $path, array $query = [], $body = null, array $o = []) {
	$method = strtoupper((string)$method);
	$url = $this->url($path, $query);
	while (true) {
	    $req = [ 'headers' => [ 'PRIVATE-TOKEN' => trim((string)$this->cred->token), 'Accept' => 'application/json' ], 'timeout' => $this->left(),
		'maxBytes' => (int)($o['maxBytes'] ?? self::MAX_ANSWER), 'redirects' => (int)($o['redirects'] ?? 0) ] + $this->options();
	    if (isset($o['raw'])) {
		$req['body'] = $o['raw'][0];
		$req['headers']['Content-Type'] = $o['raw'][1];
	    } elseif ($body !== null)
		$req['json'] = $body;
	    if (isset($o['sink']))
		$req['sink'] = $o['sink'];
	    $r = Http::json($method, $url, $req);
	    if ($r['status'] === 429) {
		$wait = isset($r['headers']['retry-after']) ? (int)$r['headers']['retry-after'] : (int)($r['headers']['ratelimit-reset'] ?? 0) - time();
		$wait = max(1, $wait);
		if ($wait > self::MAX_WAIT || $wait >= $this->left())
		    throw new \RuntimeException('GitLab\'s rate limit is reached (HTTP status 429): it resets in ' . $wait . ' s, more than the step\'s timeout leaves');
		sleep($wait);
		continue;
	    }
	    break;
	}
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (((int)($o['maxBytes'] ?? self::MAX_ANSWER)) >> 20) . ' MB' : 'no answer from ' . Http::shown($url) . ": {$r['error']}");
	if ($r['status'] >= 400 && !in_array($r['status'], (array)($o['ok'] ?? []), true))
	    throw new \RuntimeException(self::message($r['data'], $r['status']));
	if (!empty($o['text']))
	    return (string)$r['body'];
	if (!empty($o['full']))
	    return [ $r['status'], $r['headers'], is_array($r['data']) ? $r['data'] : (string)$r['body'] ];
	return is_array($r['data']) ? $r['data'] : [];
    }

    //a list's items through its pages (Link rel="next"), at most $max
    public function pages($path, array $query, $max) {
	$max = max(1, (int)$max);
	$items = [];
	$url = $this->url($path, $query + [ 'per_page' => min(self::PAGE, $max + 1) ]);
	while (true) {
	    [ , $h, $d ] = $this->request('GET', $url, [], null, [ 'full' => true ]);
	    foreach((is_array($d) && array_is_list($d) ? $d : []) as $i) {
		if (count($items) >= $max)
		    return [ $items, true ];
		$items[] = $i;
	    }
	    $next = preg_match('/<([^>]+)>;\s*rel="next"/', (string)($h['link'] ?? ''), $m) ? $m[1] : '';
	    if ($next === '')
		return [ $items, false ];
	    if (count($items) >= $max)
		return [ $items, true ];
	    $url = $next;
	}
    }

    //the project of a setting as the API's ID: its number, or its path (group/project) encoded; a URL, an item ({ path_with_namespace } / { id }); empty: the credential's default
    public function project($value, $label = 'Project') {
	if (is_array($value))
	    $value = $value['path_with_namespace'] ?? $value['project'] ?? $value['id'] ?? '';
	$v = trim(Expression::toText($value));
	if ($v === '')
	    $v = trim((string)($this->cred->defaultProject ?? ''));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty, and the credential has no default project");
	if (preg_match('/^\d{1,20}$/', $v))
	    return $v;
	if (preg_match('~^https?://[^/]+/(.+?)(?:/-/.*|\.git)?/?$~', $v, $m))
	    $v = $m[1];
	if (!preg_match(self::PROJECT, $v))
	    throw new \RuntimeException("{$label}: group/project (or its ID), e.g. acme/website: `" . mb_substr($v, 0, 100) . '`');
	return rawurlencode($v);
    }

    //an iid (an issue's, a merge request's) of a setting: 12, #12, !12, its address, an item ({ iid })
    public static function iid($value, $label = 'Number') {
	if (is_array($value))
	    $value = $value['iid'] ?? $value['number'] ?? '';
	$v = trim(Expression::toText($value));
	if (preg_match('~/-/(?:issues|merge_requests|work_items)/(\d+)~', $v, $m))
	    $v = $m[1];
	$v = ltrim($v, '#!');
	if (!preg_match('/^\d{1,10}$/', $v) || (int)$v < 1)
	    throw new \RuntimeException("{$label}: a number, e.g. 12: `" . mb_substr($v, 0, 60) . '`');
	return (int)$v;
    }

    //a file path, encoded whole (the files API takes it as one segment)
    public static function filePath($value, $label = 'Path') {
	$v = trim(str_replace('\\', '/', Expression::toText(is_array($value) ? ($value['path'] ?? '') : $value)), '/ ');
	foreach(explode('/', $v) as $p)
	    if ($p === '' || $p === '.' || $p === '..' || preg_match('/[\x00-\x1f]/', $p))
		throw new \RuntimeException("{$label}: not a path in the repository: `" . mb_substr($v, 0, 100) . '`');
	return $v;
    }

    //a webhook's secret token (X-Gitlab-Token), compared
    public static function verify($header, $secret) {
	$secret = (string)$secret;
	return $secret !== '' && hash_equals($secret, (string)$header);
    }
}
