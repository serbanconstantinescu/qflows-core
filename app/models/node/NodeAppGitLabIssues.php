<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: GitLab Issues - a project's issues (NodeAppGitLab). Operations:
	list      by state, labels, assignee, author, a search (title and description), updated since; newest first, or recently updated
	get       an issue (by its number in the project, the iid)
	create    title, description (Markdown), labels, assignees (usernames), a milestone (its title or ID), a due date, confidential
	update    the settings given (empty ones are left as they are; Labels replaces them)
	close, reopen
	comment   a comment (a note, Markdown); comments: an issue's comments, oldest first (system notes left out)
	addLabels, removeLabel
    An issue: { number, id, title, state (opened, closed), description, url, author, labels, assignees, milestone, due, confidential, comments, project, created, updated, closed }
*/

namespace models\node;

class NodeAppGitLabIssues extends NodeAppGitLab {
    public static $name = 'gitlab-issues';
    public static $label = 'GitLab Issues';
    public static $icon = 'bi-record-circle';
    public static $defaultOperation = 'create';
    public static $defaultVariable = 'issue';

    const OPERATIONS = [
	'list' => 'List issues',
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
    const LISTS = [ 'list', 'comments' ];
    const STATES = [ 'opened' => 'Open', 'closed' => 'Closed', 'all' => 'All' ];
    const SORTS = [ 'created_at' => 'Newest first', 'updated_at' => 'Recently updated' ];
    const BY_NUMBER = [ 'get', 'update', 'close', 'reopen', 'comment', 'comments', 'addLabels', 'removeLabel' ];

    protected static function operationFields() {
	return [
	    self::field('number', 'Issue', self::BY_NUMBER, [ 'required' => true, 'help' => 'Its number (12, #12) or address, e.g. {{ issue.number }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'opened', 'choices' => self::STATES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('sort', 'Order', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'created_at', 'choices' => self::SORTS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('filterLabels', 'With labels', [ 'list' ], [ 'help' => 'Optional: all of them, separated by commas', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('assignee', 'Assignee', [ 'list' ], [ 'help' => 'Optional: a username', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('author', 'Author', [ 'list' ], [ 'help' => 'Optional: a username', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('search', 'Search', [ 'list' ], [ 'help' => 'Optional: words of the title or description', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('since', 'Updated since', [ 'list' ], [ 'help' => 'Optional: a date and time (ISO 8601)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('title', 'Title', [ 'create', 'update' ], [ 'help' => 'Update: empty leaves it', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('description', 'Description', [ 'create', 'update' ], [ 'type' => 'textarea', 'help' => 'Markdown; update: empty leaves it', 'ui' => [ 'rows' => 5, 'expression' => true ] ]),
	    self::field('labels', 'Labels', [ 'create', 'update', 'addLabels' ], [ 'help' => 'Names, separated by commas, or a list; update: replaces them (empty leaves them)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('assignees', 'Assignees', [ 'create', 'update' ], [ 'help' => 'Usernames, separated by commas, or a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('milestone', 'Milestone', [ 'create', 'update' ], [ 'help' => 'Optional: its title or ID', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('due', 'Due date', [ 'create', 'update' ], [ 'help' => 'Optional: a date, e.g. 2026-12-31', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('confidential', 'Confidential', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('label', 'Label', [ 'removeLabel' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('comment', 'Comment', [ 'comment', 'close' ], [ 'type' => 'textarea', 'help' => 'Markdown; close: optional, added before closing', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if ($op === 'comment')
	    return [ $name => [ 'type' => 'object', 'info' => 'the comment: { id, author, body, created }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the issue: { number, title, state, url, labels, assignees, … }', 'fields' => [ 'number' => [ 'type' => 'number', 'info' => 'its iid' ],
	    'title' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string', 'info' => 'opened or closed' ], 'url' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ],
	    'author' => [ 'type' => 'string' ], 'labels' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'assignees' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ] ];
    }

    public static function noteOf($n) {
	$n = is_array($n) ? $n : [];
	return [ 'id' => (int)($n['id'] ?? 0), 'author' => (string)($n['author']['username'] ?? ''), 'body' => (string)($n['body'] ?? ''), 'created' => (string)($n['created_at'] ?? ''),
	    'updated' => (string)($n['updated_at'] ?? '') ];
    }

    //a milestone's ID of a setting: a number, or a title of the project's milestones
    private function milestone($project, $value) {
	$t = trim(\util\Expression::toText($value));
	if ($t === '')
	    return null;
	if (preg_match('/^\d{1,20}$/', $t))
	    return (int)$t;
	$found = $this->gl->request('GET', "/projects/{$project}/milestones", [ 'title' => $t ]);
	if (!isset($found[0]['id']))
	    throw new \RuntimeException("Milestone: no milestone `{$t}`");
	return (int)$found[0]['id'];
    }

    //CLD: 2026-11-26T03:00:00+02:00: a date as written (2026-12-31 stays that day); another form read in the server's time zone (not UTC: a bare date is its midnight)
    private static function dateOf($v) {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return null;
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && checkdate((int)substr($t, 5, 2), (int)substr($t, 8, 2), (int)substr($t, 0, 4)))
	    return $t;
	$ts = strtotime($t);
	if ($ts === false)
	    throw new \RuntimeException("Due date: not a date: `" . mb_substr($t, 0, 40) . '`');
	return date('Y-m-d', $ts);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$project = $this->project();
	if ($operation === 'list') {
	    $q = array_filter([ 'state' => (string)$this->cfg('state', 'opened') === 'all' ? null : (string)$this->cfg('state', 'opened'), 'order_by' => (string)$this->cfg('sort', 'created_at'),
		'sort' => 'desc', 'labels' => implode(',', self::names($value('filterLabels'))), 'assignee_username' => $text('assignee'), 'author_username' => $text('author'),
		'search' => $text('search'), 'updated_after' => self::timeOf($value('since'), 'Updated since') ], fn($v) => $v !== null && $v !== '');
	    return $this->listOf("/projects/{$project}/issues", $q, 'issue', fn($i) => self::issueOf($i));
	}
	if ($operation === 'create') {
	    $title = $text('title');
	    if ($title === '')
		throw new \RuntimeException('Title: required to create an issue');
	    $labels = self::names($value('labels'));
	    $body = array_filter([ 'title' => mb_substr($title, 0, 255), 'description' => \util\Expression::toText($value('description')), 'labels' => $labels ? implode(',', $labels) : null,
		'assignee_ids' => $this->userIds($value('assignees'), 'Assignees') ?: null, 'milestone_id' => $this->milestone($project, $value('milestone')), 'due_date' => self::dateOf($value('due')),
		'confidential' => $this->cfg('confidential', false) ? true : null ], fn($v) => $v !== null && $v !== '');
	    $i = self::issueOf($this->gl->request('POST', "/projects/{$project}/issues", [], $body));
	    return [ $i, "#{$i['number']} created: {$i['title']}" ];
	}
	$n = \util\GitLab::iid($value('number'), 'Issue');
	$path = "/projects/{$project}/issues/{$n}";
	switch($operation) {
	    case 'get':
		$i = self::issueOf($this->gl->request('GET', $path));
		return [ $i, "#{$n} {$i['title']} ({$i['state']})" ];
	    case 'update':
		$body = [];
		if ($text('title') !== '')
		    $body['title'] = mb_substr($text('title'), 0, 255);
		$desc = \util\Expression::toText($value('description'));
		if (trim($desc) !== '')
		    $body['description'] = $desc;
		if ($labels = self::names($value('labels')))
		    $body['labels'] = implode(',', $labels);
		if ($ids = $this->userIds($value('assignees'), 'Assignees'))
		    $body['assignee_ids'] = $ids;
		if (($m = $this->milestone($project, $value('milestone'))) !== null)
		    $body['milestone_id'] = $m;
		if (($d = self::dateOf($value('due'))) !== null)
		    $body['due_date'] = $d;
		if (!$body)
		    throw new \RuntimeException('nothing to change: give a title, a description, labels, assignees, a milestone or a due date');
		$i = self::issueOf($this->gl->request('PUT', $path, [], $body));
		return [ $i, "#{$n} updated (" . implode(', ', array_keys($body)) . ')' ];
	    case 'close':
	    case 'reopen':
		$comment = \util\Expression::toText($value('comment'));
		if ($operation === 'close' && trim($comment) !== '')
		    $this->gl->request('POST', "{$path}/notes", [], [ 'body' => $comment ]);
		$i = self::issueOf($this->gl->request('PUT', $path, [], [ 'state_event' => $operation ]));
		return [ $i, "#{$n} " . ($operation === 'close' ? 'closed' : 'reopened') ];
	    case 'comment':
		$comment = \util\Expression::toText($value('comment'));
		if (trim($comment) === '')
		    throw new \RuntimeException('Comment: empty');
		$c = self::noteOf($this->gl->request('POST', "{$path}/notes", [], [ 'body' => $comment ]));
		return [ $c, "comment added to #{$n}" ];
	    case 'comments':
		[ $all, $truncated ] = $this->gl->pages("{$path}/notes", [ 'sort' => 'asc', 'order_by' => 'created_at' ], $this->maxItems());
		$items = array_values(array_map(fn($x) => self::noteOf($x), array_filter($all, fn($x) => empty($x['system']))));
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'comment') ];
	    case 'addLabels':
		$labels = self::names($value('labels'));
		if (!$labels)
		    throw new \RuntimeException('Labels: none given');
		$i = self::issueOf($this->gl->request('PUT', $path, [], [ 'add_labels' => implode(',', $labels) ]));
		return [ $i, "#{$n}: " . implode(', ', $labels) . ' added' ];
	    case 'removeLabel':
		$label = $text('label');
		$i = self::issueOf($this->gl->request('PUT', $path, [], [ 'remove_labels' => $label ]));
		return [ $i, "#{$n}: {$label} removed" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
