<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: GitHub Issues - a repository's issues (NodeAppGitHub). Operations:
	list      the issues (not pull requests), by state, labels, assignee, creator, updated since; newest first, or by updated / comments
	search    GitHub's issue search (its syntax, e.g. is:open label:bug), limited to the repository unless the query names repo: / org:
	get       an issue
	create    title, body (Markdown), labels, assignees, milestone (its number or title)
	update    the settings given (empty ones are left as they are; Labels replaces them)
	close, reopen   (close: completed or not planned)
	comment   a comment (Markdown); comments: an issue's comments, oldest first
	addLabels, removeLabel
    An issue: { number, title, state, stateReason, body, url, user, labels, assignees, milestone, comments, isPullRequest, repository, created, updated, closed }
*/

namespace models\node;

class NodeAppGitHubIssues extends NodeAppGitHub {
    public static $name = 'github-issues';
    public static $label = 'GitHub Issues';
    public static $icon = 'bi-record-circle';
    public static $defaultOperation = 'create';
    public static $defaultVariable = 'issue';

    const OPERATIONS = [
	'list' => 'List issues',
	'search' => 'Search issues',
	'get' => 'Get an issue',
	'create' => 'Create an issue',
	'update' => 'Update an issue',
	'close' => 'Close an issue',
	'reopen' => 'Reopen an issue',
	'comment' => 'Add a comment',
	'comments' => 'List the comments',
	'addLabels' => 'Add labels',
	'removeLabel' => 'Remove a label',
    ];
    const WRITES = [ 'create', 'update', 'close', 'reopen', 'comment', 'addLabels', 'removeLabel' ];
    const LISTS = [ 'list', 'search', 'comments' ];
    const STATES = [ 'open' => 'Open', 'closed' => 'Closed', 'all' => 'All' ];
    const SORTS = [ 'created' => 'Newest first', 'updated' => 'Recently updated', 'comments' => 'Most commented' ];
    const REASONS = [ 'completed' => 'Completed', 'not_planned' => 'Not planned' ];
    const BY_NUMBER = [ 'get', 'update', 'close', 'reopen', 'comment', 'comments', 'addLabels', 'removeLabel' ];

    protected static function operationFields() {
	return [
	    self::field('number', 'Issue', self::BY_NUMBER, [ 'required' => true, 'help' => 'Its number (12, #12) or address, e.g. {{ issue.number }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'open', 'choices' => self::STATES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('sort', 'Order', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'created', 'choices' => self::SORTS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('filterLabels', 'With labels', [ 'list' ], [ 'help' => 'Optional: all of them, separated by commas', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('assignee', 'Assignee', [ 'list' ], [ 'help' => 'Optional: a login, none or *', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('creator', 'Creator', [ 'list' ], [ 'help' => 'Optional: a login', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('since', 'Updated since', [ 'list' ], [ 'help' => 'Optional: a date and time (ISO 8601)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('query', 'Query', [ 'search' ], [ 'required' => true, 'help' => 'GitHub\'s search, e.g. is:open label:bug in:title printer', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('title', 'Title', [ 'create', 'update' ], [ 'help' => 'Update: empty leaves it', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('body', 'Description', [ 'create', 'update' ], [ 'type' => 'textarea', 'help' => 'Markdown; update: empty leaves it', 'ui' => [ 'rows' => 5, 'expression' => true ] ]),
	    self::field('labels', 'Labels', [ 'create', 'update', 'addLabels' ], [ 'help' => 'Names, separated by commas, or a list; update: replaces them (empty leaves them)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('assignees', 'Assignees', [ 'create', 'update' ], [ 'help' => 'Logins, separated by commas, or a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('milestone', 'Milestone', [ 'create', 'update' ], [ 'help' => 'Optional: its number or title', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('label', 'Label', [ 'removeLabel' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('reason', 'Reason', [ 'close' ], [ 'type' => 'select', 'required' => true, 'default' => 'completed', 'choices' => self::REASONS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('comment', 'Comment', [ 'comment', 'close' ], [ 'type' => 'textarea', 'help' => 'Markdown; close: optional, added before closing', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if ($op === 'comment')
	    return [ $name => [ 'type' => 'object', 'info' => 'the comment: { id, url, user, body, created }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the issue: { number, title, state, url, labels, assignees, … }', 'fields' => [ 'number' => [ 'type' => 'number' ], 'title' => [ 'type' => 'string' ],
	    'state' => [ 'type' => 'string', 'info' => 'open or closed' ], 'url' => [ 'type' => 'string' ], 'body' => [ 'type' => 'string' ], 'user' => [ 'type' => 'string' ],
	    'labels' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'assignees' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'created' => [ 'type' => 'string' ] ] ] ];
    }

    private static function commentOf($c) {
	$c = is_array($c) ? $c : [];
	return [ 'id' => (int)($c['id'] ?? 0), 'url' => (string)($c['html_url'] ?? ''), 'user' => (string)($c['user']['login'] ?? ''), 'body' => (string)($c['body'] ?? ''),
	    'created' => (string)($c['created_at'] ?? ''), 'updated' => (string)($c['updated_at'] ?? '') ];
    }

    //a milestone's number of a setting: a number, or a title of the repository's milestones
    private function milestone($repo, $value) {
	$t = trim(\util\Expression::toText($value));
	if ($t === '')
	    return null;
	if (preg_match('/^\d{1,10}$/', $t))
	    return (int)$t;
	[ $all ] = $this->gh->pages("/repos/{$repo}/milestones", [ 'state' => 'all' ], 500);
	foreach($all as $m)
	    if (strcasecmp((string)($m['title'] ?? ''), $t) === 0)
		return (int)$m['number'];
	throw new \RuntimeException("Milestone: no milestone `{$t}`");
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$repo = $this->repository();
	if ($operation === 'list') {
	    $q = array_filter([ 'state' => isset(self::STATES[(string)$this->cfg('state', 'open')]) ? (string)$this->cfg('state', 'open') : 'open', 'sort' => (string)$this->cfg('sort', 'created'),
		'direction' => 'desc', 'labels' => implode(',', self::names($value('filterLabels'))), 'assignee' => $text('assignee'), 'creator' => $text('creator'),
		'since' => self::timeOf($value('since'), 'Updated since') ], fn($v) => $v !== null && $v !== '');
	    //the issues list has pull requests too: left out
	    [ $all, $truncated ] = $this->gh->pages("/repos/{$repo}/issues", $q, $this->maxItems() * 2);
	    $issues = array_values(array_filter(array_map(fn($i) => self::issueOf($i), $all), fn($i) => !$i['isPullRequest']));
	    $items = array_slice($issues, 0, $this->maxItems());
	    $truncated = $truncated || count($issues) > count($items);
	    return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'issue') . ($truncated ? ' (more left out)' : '') ];
	}
	if ($operation === 'search') {
	    $query = $text('query');
	    if (!preg_match('/(^|\s)(repo|org|user):/', $query))
		$query = "repo:{$repo} {$query}";
	    if (!preg_match('/(^|\s)(is|type):(issue|pr|pull-request)\b/', $query))
		$query .= ' is:issue';
	    return $this->listOf('/search/issues', [ 'q' => $query ], 'issue', fn($i) => self::issueOf($i), 'items');
	}
	if ($operation === 'create') {
	    $title = $text('title');
	    if ($title === '')
		throw new \RuntimeException('Title: required to create an issue');
	    $body = array_filter([ 'title' => mb_substr($title, 0, 256), 'body' => \util\Expression::toText($value('body')), 'labels' => self::names($value('labels')),
		'assignees' => self::names($value('assignees')), 'milestone' => $this->milestone($repo, $value('milestone')) ], fn($v) => $v !== null && $v !== '' && $v !== []);
	    $i = self::issueOf($this->gh->request('POST', "/repos/{$repo}/issues", [], $body));
	    return [ $i, "#{$i['number']} created: {$i['title']}" ];
	}
	$n = \util\vendor\GitHub::number($value('number'), 'Issue');
	switch($operation) {
	    case 'get':
		$i = self::issueOf($this->gh->request('GET', "/repos/{$repo}/issues/{$n}"));
		return [ $i, "#{$n} {$i['title']} ({$i['state']})" ];
	    case 'update':
		$body = [];
		if ($text('title') !== '')
		    $body['title'] = mb_substr($text('title'), 0, 256);
		$desc = \util\Expression::toText($value('body'));
		if (trim($desc) !== '')
		    $body['body'] = $desc;
		if ($labels = self::names($value('labels')))
		    $body['labels'] = $labels;
		if ($assignees = self::names($value('assignees')))
		    $body['assignees'] = $assignees;
		if (($m = $this->milestone($repo, $value('milestone'))) !== null)
		    $body['milestone'] = $m;
		if (!$body)
		    throw new \RuntimeException('nothing to change: give a title, a description, labels, assignees or a milestone');
		$i = self::issueOf($this->gh->request('PATCH', "/repos/{$repo}/issues/{$n}", [], $body));
		return [ $i, "#{$n} updated (" . implode(', ', array_keys($body)) . ')' ];
	    case 'close':
	    case 'reopen':
		$comment = \util\Expression::toText($value('comment'));
		if ($operation === 'close' && trim($comment) !== '')
		    $this->gh->request('POST', "/repos/{$repo}/issues/{$n}/comments", [], [ 'body' => $comment ]);
		$body = $operation === 'close' ? [ 'state' => 'closed', 'state_reason' => isset(self::REASONS[(string)$this->cfg('reason', 'completed')]) ? (string)$this->cfg('reason', 'completed') : 'completed' ]
		    : [ 'state' => 'open' ];
		$i = self::issueOf($this->gh->request('PATCH', "/repos/{$repo}/issues/{$n}", [], $body));
		return [ $i, "#{$n} " . ($operation === 'close' ? 'closed' : 'reopened') ];
	    case 'comment':
		$comment = \util\Expression::toText($value('comment'));
		if (trim($comment) === '')
		    throw new \RuntimeException('Comment: empty');
		$c = self::commentOf($this->gh->request('POST', "/repos/{$repo}/issues/{$n}/comments", [], [ 'body' => $comment ]));
		return [ $c, "comment added to #{$n}" ];
	    case 'comments':
		return $this->listOf("/repos/{$repo}/issues/{$n}/comments", [], 'comment', fn($c) => self::commentOf($c));
	    case 'addLabels':
		$labels = self::names($value('labels'));
		if (!$labels)
		    throw new \RuntimeException('Labels: none given');
		$this->gh->request('POST', "/repos/{$repo}/issues/{$n}/labels", [], [ 'labels' => $labels ]);
		$i = self::issueOf($this->gh->request('GET', "/repos/{$repo}/issues/{$n}"));
		return [ $i, "#{$n}: " . implode(', ', $labels) . ' added' ];
	    case 'removeLabel':
		$label = $text('label');
		try {
		    $this->gh->request('DELETE', "/repos/{$repo}/issues/{$n}/labels/" . rawurlencode($label));
		} catch (\RuntimeException $e) {
		    //not on the issue: nothing to remove
		    if (!str_starts_with($e->getMessage(), 'HTTP status 404'))
			throw $e;
		}
		$i = self::issueOf($this->gh->request('GET', "/repos/{$repo}/issues/{$n}"));
		return [ $i, "#{$n}: {$label} removed" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
