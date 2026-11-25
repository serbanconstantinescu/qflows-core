<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: Entra ID API - any request of Microsoft Graph with the credential's app (what the other Entra ID steps do not have: devices, directory roles,
    conditional access, sign-in logs, administrative units, …): the method, the path (from /v1.0/ or /beta/; else below /v1.0), the query and the JSON body; ConsistencyLevel
    eventual optionally (for $search, $count). The result: { status, data, next } (next: @odata.nextLink; give it as the path to read it)
*/

namespace models\node;

class NodeAppEntraApi extends NodeAppEntra {
    public static $name = 'entra-api';
    public static $label = 'Entra ID API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'graph';

    const OPERATIONS = [ 'request' => 'A Graph request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'e.g. /devices, /beta/auditLogs/signIns, /directoryRoles', 'ui' => [ 'expression' => 'text', 'width' => 9 ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object, e.g. {"$filter": "accountEnabled eq false", "$top": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('eventual', 'ConsistencyLevel: eventual', [ 'request' ], [ 'type' => 'switch', 'default' => false, 'help' => 'For $search and $count', 'ui' => [ 'width' => 6 ] ]),
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
	[ $status, , $data ] = $this->graph->request($method, $path, $query, $body, [ 'full' => true, 'eventual' => (bool)$this->cfg('eventual', false) ]);
	return [ [ 'status' => $status, 'data' => $data, 'next' => is_array($data) ? (string)($data['@odata.nextLink'] ?? '') : '' ], "{$method} " . preg_replace('~^https?://[^/]+~', '', $path) . " ({$status})" ];
    }
}
