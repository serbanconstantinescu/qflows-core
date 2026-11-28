<?php

/*
    PatchMon API - any request of PatchMon's API (what the other PatchMon steps do not have), as the API token (the integration API: the path after /api/v1, e.g.
    /api/hosts) or signed in as the credential's PatchMon user (the web interface's API: e.g. /patching/policies, /hosts/<id>/notes; undocumented for integrations: see
    the API reference at /api/v1/api-docs on the server). The method, the path, the query and the body (JSON objects, or expressions giving them; Other parameters is
    not used here). Its result: PatchMon's answer
*/

namespace plugins\ITOps\models\node;

class NodeAppPmApi extends NodeAppPatchMon {
    public static $name = 'pm-api';
    public static $label = 'PatchMon API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'pm';

    const OPERATIONS = [ 'request' => 'Send a request' ];
    //the operations that change something (any request); the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE' ];
    const SEND_AS = [ 'token' => 'The API token', 'user' => 'The PatchMon user' ];

    protected static function operationFields() {
	return [
	    self::field('as', 'Send as', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'token', 'choices' => self::SEND_AS,
		'help' => 'The token: the integration API (paths /api/...); the user: everything the web interface does', 'ui' => [ 'width' => 6 ] ]),
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'After /api/v1, e.g. /api/hosts or /patching/policies ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'placeholder' => '/api/hosts' ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"include": "stats"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    //a JSON object of a setting ([] when empty)
    protected static function object($v, $label) {
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException("{$label}: not a JSON object");
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = '/' . ltrim($text('path'), '/');
	$query = self::object($value('query'), 'Query');
	$body = trim((string)$this->cfg('body', '')) === '' ? null : self::object($value('body'), 'Body');
	if ((string)$this->cfg('as', 'token') === 'user')
	    return [ $this->pm->user($method, $path, $query, $body), "{$method} {$path} (as the user)" ];
	//the integration API is under /api/v1/api
	if (!preg_match('~^/api(/|$)~', $path))
	    throw new \RuntimeException('Path: the API token reaches only the integration API, under /api (e.g. /api/hosts); send as the user for the rest');
	return [ $this->pm->api($method, substr($path, 4) ?: '/', $query, $body), "{$method} {$path}" ];
    }
}
