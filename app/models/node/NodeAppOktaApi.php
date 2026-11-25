<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: Okta API - any request of Okta's management API with the credential (what the other Okta steps do not have: policies, network zones, authorization
    servers, devices, admin roles, …): the method, the path (below /api/v1), the query and the JSON body. The result: { status, data, next } (next: the list's next page, from
    its Link header; give it as the path to read it)
*/

namespace models\node;

class NodeAppOktaApi extends NodeAppOkta {
    public static $name = 'okta-api';
    public static $label = 'Okta API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'okta';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'Below /api/v1, e.g. /policies?type=OKTA_SIGN_ON or /zones', 'ui' => [ 'expression' => 'text', 'width' => 9 ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none)', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	if ($path === '')
	    throw new \RuntimeException('Path: empty');
	$query = array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? json_encode($v) : (string)$v), (array)($this->jsonOf('query', 'Query') ?? []));
	$body = $this->settingOf('body');
	if (is_string($body)) {
	    $raw = $body;
	    $body = trim($raw) === '' ? null : json_decode($raw, true);
	    if ($body === null && trim($raw) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	[ $status, $h, $data ] = $this->okta->request($method, $path, $query, $body, [ 'full' => true ]);
	$next = preg_match('/<([^>]+)>;\s*rel="next"/', (string)($h['link'] ?? ''), $m) ? $m[1] : '';
	return [ [ 'status' => $status, 'data' => $data, 'next' => $next ], "{$method} " . preg_replace('~^https?://[^/]+~', '', $path) . " ({$status})" ];
    }
}
