<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: GitLab API - any request of GitLab's REST API v4 with the credential (what the other GitLab steps do not have: environments, deployments,
    epics, group members, CI/CD variables, …): the method, the path ({project} in it: the step's project, its ID encoded), the query and the JSON body.
    The result: { status, data, next } (next: the address of the list's next page, from its Link header; give it as the path to read it)
*/

namespace models\node;

class NodeAppGitLabApi extends NodeAppGitLab {
    public static $name = 'gitlab-api';
    public static $label = 'GitLab API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'gitlab';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'Below /api/v4, e.g. /projects/{project}/environments or /groups/acme/members ({project}: the project)', 'ui' => [ 'expression' => 'text', 'width' => 9 ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"per_page": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	if (str_contains($path, '{project}'))
	    $path = str_replace('{project}', $this->project(), $path);
	if ($path === '')
	    throw new \RuntimeException('Path: empty');
	$query = $this->jsonOf('query', 'Query') ?? [];
	array_walk_recursive($query, function(&$v) {
	    if (is_bool($v))
		$v = $v ? 'true' : 'false';
	});
	$body = $this->settingOf('body');
	if (is_string($body)) {
	    $raw = $body;
	    $body = trim($raw) === '' ? null : json_decode($raw, true);
	    if ($body === null && trim($raw) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	[ $status, $h, $data ] = $this->gl->request($method, $path, $query, $body, [ 'full' => true ]);
	$next = preg_match('/<([^>]+)>;\s*rel="next"/', (string)($h['link'] ?? ''), $m) ? $m[1] : '';
	return [ [ 'status' => $status, 'data' => $data, 'next' => $next ], "{$method} " . preg_replace('~^https?://[^/]+~', '', $path) . " ({$status})" ];
    }
}
