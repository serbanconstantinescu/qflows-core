<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: GitHub Pull Requests - a repository's pull requests (NodeAppGitHub). Operations:
	list      by state, base branch, head (owner:branch); newest first, or recently updated
	get       a pull request, with its size (additions, deletions, changed files, commits) and whether it can be merged
	files     the files it changes: { path, status, additions, deletions, patch (cut at 20,000 characters) }
	checks    the checks and statuses of its last commit: { state (success, failure, pending), checks: [ { name, status, conclusion, url } ] }
	create    from a head branch into a base branch, title, body, draft
	update    title, body, base, state (open / closed)
	comment   a comment on the conversation (Markdown)
	review    approve, request changes, or comment, with a text
	reviewers request reviews of people (logins) and teams (org/team or team slugs)
	merge     merge, squash or rebase, an optional commit title and message; refused when GitHub says it cannot be merged
    A pull request: { number, title, state (open, closed, merged), draft, body, url, user, head, base, sha, merged, mergeable, labels, assignees, reviewers, repository, created,
    updated, merged_at, closed, … }
*/

namespace models\node;

class NodeAppGitHubPulls extends NodeAppGitHub {
    public static $name = 'github-pulls';
    public static $label = 'GitHub Pull Requests';
    public static $icon = 'bi-git';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'pr';

    const OPERATIONS = [
	'list' => 'List pull requests',
	'get' => 'Get a pull request',
	'files' => 'List the changed files',
	'checks' => 'Get the checks',
	'create' => 'Create a pull request',
	'update' => 'Update a pull request',
	'comment' => 'Add a comment',
	'review' => 'Review',
	'reviewers' => 'Request reviewers',
	'merge' => 'Merge',
    ];
    const WRITES = [ 'create', 'update', 'comment', 'review', 'reviewers', 'merge' ];
    const LISTS = [ 'list', 'files' ];
    const STATES = [ 'open' => 'Open', 'closed' => 'Closed (and merged)', 'all' => 'All' ];
    const SORTS = [ 'created' => 'Newest first', 'updated' => 'Recently updated' ];
    const REVIEWS = [ 'APPROVE' => 'Approve', 'REQUEST_CHANGES' => 'Request changes', 'COMMENT' => 'Comment' ];
    const MERGES = [ 'merge' => 'Merge commit', 'squash' => 'Squash and merge', 'rebase' => 'Rebase and merge' ];
    const MAX_PATCH = 20000;
    const BY_NUMBER = [ 'get', 'files', 'checks', 'update', 'comment', 'review', 'reviewers', 'merge' ];

    protected static function operationFields() {
	return [
	    self::field('number', 'Pull request', self::BY_NUMBER, [ 'required' => true, 'help' => 'Its number (12, #12) or address, e.g. {{ pr.number }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'open', 'choices' => self::STATES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('sort', 'Order', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'created', 'choices' => self::SORTS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('head', 'Head branch', [ 'list', 'create' ], [ 'help' => 'The branch with the changes, e.g. feature/x (list: optional; another owner\'s: owner:branch)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('base', 'Base branch', [ 'list', 'create', 'update' ], [ 'help' => 'Where they go, e.g. main (create: empty takes the default branch)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('title', 'Title', [ 'create', 'update' ], [ 'help' => 'Update: empty leaves it', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('body', 'Description', [ 'create', 'update' ], [ 'type' => 'textarea', 'help' => 'Markdown; update: empty leaves it', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('draft', 'Draft', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('newState', 'State', [ 'update' ], [ 'type' => 'select', 'default' => '', 'choices' => [ '' => 'As it is', 'open' => 'Open', 'closed' => 'Closed' ], 'ui' => [ 'width' => 6 ] ]),
	    self::field('event', 'Review', [ 'review' ], [ 'type' => 'select', 'required' => true, 'default' => 'APPROVE', 'choices' => self::REVIEWS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('comment', 'Comment', [ 'comment', 'review' ], [ 'type' => 'textarea', 'help' => 'Markdown; a review that is not an approval needs one', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('people', 'Reviewers', [ 'reviewers' ], [ 'help' => 'Logins, separated by commas, or a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('teams', 'Teams', [ 'reviewers' ], [ 'help' => 'Optional: team slugs of the organization', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('method', 'Merge with', [ 'merge' ], [ 'type' => 'select', 'required' => true, 'default' => 'merge', 'choices' => self::MERGES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('commitTitle', 'Commit title', [ 'merge' ], [ 'help' => 'Optional', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('commitMessage', 'Commit message', [ 'merge' ], [ 'type' => 'textarea', 'help' => 'Optional', 'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	return [ $name => match($op) {
	    'checks' => [ 'type' => 'object', 'info' => '{ sha, state, total, failed, checks: [ { name, status, conclusion, url } ] }', 'fields' => [ 'state' => [ 'type' => 'string', 'info' => 'success, failure or pending' ],
		'failed' => [ 'type' => 'number' ], 'checks' => [ 'type' => 'array' ] ] ],
	    'merge' => [ 'type' => 'object', 'info' => '{ merged, sha, message }' ],
	    'comment', 'review' => [ 'type' => 'object', 'info' => '{ id, url, user, body, state }' ],
	    default => [ 'type' => 'object', 'info' => 'the pull request: { number, title, state, url, head, base, sha, merged, mergeable, … }', 'fields' => [ 'number' => [ 'type' => 'number' ],
		'title' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string', 'info' => 'open, closed or merged' ], 'url' => [ 'type' => 'string' ], 'head' => [ 'type' => 'string' ], 'base' => [ 'type' => 'string' ],
		'sha' => [ 'type' => 'string', 'info' => 'the head commit' ], 'merged' => [ 'type' => 'boolean' ], 'mergeable' => [ 'type' => 'boolean' ] ] ],
	} ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$repo = $this->repository();
	if ($operation === 'list') {
	    $owner = explode('/', $repo)[0];
	    $head = $text('head');
	    $q = array_filter([ 'state' => isset(self::STATES[(string)$this->cfg('state', 'open')]) ? (string)$this->cfg('state', 'open') : 'open', 'sort' => (string)$this->cfg('sort', 'created'),
		'direction' => 'desc', 'base' => $text('base'), 'head' => $head === '' ? null : (str_contains($head, ':') ? $head : "{$owner}:{$head}") ], fn($v) => $v !== null && $v !== '');
	    return $this->listOf("/repos/{$repo}/pulls", $q, 'pull request', fn($p) => self::pullOf($p));
	}
	if ($operation === 'create') {
	    $head = $text('head');
	    $title = $text('title');
	    if ($head === '' || $title === '')
		throw new \RuntimeException('a pull request needs its head branch and a title');
	    $base = $text('base');
	    if ($base === '')
		$base = (string)($this->gh->request('GET', "/repos/{$repo}")['default_branch'] ?? 'main');
	    $p = self::pullOf($this->gh->request('POST', "/repos/{$repo}/pulls", [], [ 'title' => mb_substr($title, 0, 256), 'head' => $head, 'base' => $base,
		'body' => \util\Expression::toText($value('body')), 'draft' => (bool)$this->cfg('draft', false) ]));
	    return [ $p, "#{$p['number']} created: {$head} into {$base}" ];
	}
	$n = \util\GitHub::number($value('number'), 'Pull request');
	switch($operation) {
	    case 'get':
		$p = self::pullOf($this->gh->request('GET', "/repos/{$repo}/pulls/{$n}"));
		return [ $p, "#{$n} {$p['title']} ({$p['state']})" ];
	    case 'files':
		return $this->listOf("/repos/{$repo}/pulls/{$n}/files", [], 'file', fn($f) => [ 'path' => (string)($f['filename'] ?? ''), 'status' => (string)($f['status'] ?? ''),
		    'additions' => (int)($f['additions'] ?? 0), 'deletions' => (int)($f['deletions'] ?? 0), 'previous' => (string)($f['previous_filename'] ?? ''),
		    'patch' => mb_substr((string)($f['patch'] ?? ''), 0, self::MAX_PATCH) ]);
	    case 'checks':
		$sha = (string)($this->gh->request('GET', "/repos/{$repo}/pulls/{$n}")['head']['sha'] ?? '');
		[ $runs ] = $this->gh->pages("/repos/{$repo}/commits/{$sha}/check-runs", [], 300, 'check_runs');
		$status = $this->gh->request('GET', "/repos/{$repo}/commits/{$sha}/status");
		$checks = array_map(fn($c) => [ 'name' => (string)($c['name'] ?? ''), 'status' => (string)($c['status'] ?? ''), 'conclusion' => (string)($c['conclusion'] ?? ''),
		    'url' => (string)($c['html_url'] ?? $c['details_url'] ?? '') ], $runs);
		foreach((array)($status['statuses'] ?? []) as $s)
		    $checks[] = [ 'name' => (string)($s['context'] ?? ''), 'status' => in_array($s['state'] ?? '', [ 'pending' ], true) ? 'in_progress' : 'completed',
			'conclusion' => ($s['state'] ?? '') === 'pending' ? '' : (string)$s['state'], 'url' => (string)($s['target_url'] ?? '') ];
		$failed = count(array_filter($checks, fn($c) => in_array($c['conclusion'], [ 'failure', 'error', 'timed_out', 'cancelled', 'action_required' ], true)));
		$pending = count(array_filter($checks, fn($c) => $c['status'] !== 'completed'));
		$state = $failed ? 'failure' : ($pending ? 'pending' : 'success');
		return [ [ 'sha' => $sha, 'state' => $state, 'total' => count($checks), 'failed' => $failed, 'pending' => $pending, 'checks' => $checks ], "#{$n}: {$state} (" . count($checks) . ' checks)' ];
	    case 'update':
		$body = [];
		if ($text('title') !== '')
		    $body['title'] = mb_substr($text('title'), 0, 256);
		$desc = \util\Expression::toText($value('body'));
		if (trim($desc) !== '')
		    $body['body'] = $desc;
		if ($text('base') !== '')
		    $body['base'] = $text('base');
		if (in_array((string)$this->cfg('newState', ''), [ 'open', 'closed' ], true))
		    $body['state'] = (string)$this->cfg('newState');
		if (!$body)
		    throw new \RuntimeException('nothing to change: give a title, a description, a base branch or a state');
		$p = self::pullOf($this->gh->request('PATCH', "/repos/{$repo}/pulls/{$n}", [], $body));
		return [ $p, "#{$n} updated (" . implode(', ', array_keys($body)) . ')' ];
	    case 'comment':
		$comment = \util\Expression::toText($value('comment'));
		if (trim($comment) === '')
		    throw new \RuntimeException('Comment: empty');
		$c = $this->gh->request('POST', "/repos/{$repo}/issues/{$n}/comments", [], [ 'body' => $comment ]);
		return [ [ 'id' => (int)($c['id'] ?? 0), 'url' => (string)($c['html_url'] ?? ''), 'user' => (string)($c['user']['login'] ?? ''), 'body' => (string)($c['body'] ?? '') ], "comment added to #{$n}" ];
	    case 'review':
		$event = isset(self::REVIEWS[(string)$this->cfg('event', 'APPROVE')]) ? (string)$this->cfg('event', 'APPROVE') : 'APPROVE';
		$comment = \util\Expression::toText($value('comment'));
		if ($event !== 'APPROVE' && trim($comment) === '')
		    throw new \RuntimeException('Comment: ' . strtolower(self::REVIEWS[$event]) . ' needs one');
		$r = $this->gh->request('POST', "/repos/{$repo}/pulls/{$n}/reviews", [], array_filter([ 'event' => $event, 'body' => $comment ], fn($v) => $v !== ''));
		return [ [ 'id' => (int)($r['id'] ?? 0), 'url' => (string)($r['html_url'] ?? ''), 'user' => (string)($r['user']['login'] ?? ''), 'body' => (string)($r['body'] ?? ''), 'state' => (string)($r['state'] ?? '') ],
		    "#{$n}: " . strtolower(self::REVIEWS[$event]) ];
	    case 'reviewers':
		$people = self::names($value('people'));
		$teams = self::names($value('teams'));
		if (!$people && !$teams)
		    throw new \RuntimeException('Reviewers: none given');
		$p = self::pullOf($this->gh->request('POST', "/repos/{$repo}/pulls/{$n}/requested_reviewers", [], array_filter([ 'reviewers' => $people, 'team_reviewers' => $teams ])));
		return [ $p, "#{$n}: reviews requested of " . implode(', ', array_merge($people, $teams)) ];
	    case 'merge':
		$method = isset(self::MERGES[(string)$this->cfg('method', 'merge')]) ? (string)$this->cfg('method', 'merge') : 'merge';
		$body = array_filter([ 'merge_method' => $method, 'commit_title' => $text('commitTitle'), 'commit_message' => \util\Expression::toText($value('commitMessage')) ], fn($v) => $v !== '');
		try {
		    $m = $this->gh->request('PUT', "/repos/{$repo}/pulls/{$n}/merge", [], $body);
		} catch (\RuntimeException $e) {
		    throw new \RuntimeException(str_starts_with($e->getMessage(), 'HTTP status 405') ? "#{$n} cannot be merged: " . preg_replace('/^HTTP status 405: /', '', $e->getMessage()) : $e->getMessage());
		}
		return [ [ 'merged' => (bool)($m['merged'] ?? false), 'sha' => (string)($m['sha'] ?? ''), 'message' => (string)($m['message'] ?? '') ], "#{$n} merged ({$method})" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
