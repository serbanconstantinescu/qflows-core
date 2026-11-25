<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: GitHub API - any request of GitHub's REST API with the credential (what the other GitHub steps do not have: projects, teams, environments,
    deployments, secrets' names, Dependabot alerts, …): the method, the path ({repo} in it: the step's repository, else the credential's default), the query and the JSON body.
    The result: { status, data, next } (next: the address of the list's next page, from its Link header; give it as the path to read it)
*/

namespace models\node;

class NodeAppGitHubApi extends NodeAppGitHub {
    public static $name = 'github-api';
    public static $label = 'GitHub API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'github';

    const OPERATIONS = [ 'request' => 'A REST request' ];
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'e.g. /repos/{repo}/environments or /orgs/acme/teams ({repo}: the repository)', 'ui' => [ 'expression' => 'text', 'width' => 9 ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"per_page": 50}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	if (str_contains($path, '{repo}'))
	    $path = str_replace('{repo}', $this->repository(), $path);
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
	[ $status, $h, $data ] = $this->gh->request($method, $path, $query, $body, [ 'full' => true ]);
	$next = preg_match('/<([^>]+)>;\s*rel="next"/', (string)($h['link'] ?? ''), $m) ? $m[1] : '';
	return [ [ 'status' => $status, 'data' => $data, 'next' => $next ], "{$method} " . preg_replace('~^https?://[^/]+~', '', $path) . " ({$status})" ];
    }
}
