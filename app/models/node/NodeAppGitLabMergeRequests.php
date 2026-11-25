<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: GitLab Merge Requests - a project's merge requests (NodeAppGitLab). Operations:
	list       by state (opened, merged, closed), target and source branch; newest first, or recently updated
	get        a merge request, with its merge status, conflicts and head pipeline's status
	changes    the files it changes: { path, old, status (added, deleted, renamed, modified), diff (cut at 20,000 characters) }
	pipelines  its pipelines, newest first
	create     from a source branch into a target branch (empty: the default branch), title, description, draft, remove the source branch, squash
	update     title, description, target, state (close / reopen)
	comment    a comment (a note); approve, unapprove
	reviewers  the reviewers (usernames), replacing them
	merge      now, or when the pipeline succeeds (auto-merge); squash, remove the source branch, a commit message; refused when GitLab says it cannot be merged
    A merge request: { number, id, title, state (opened, closed, merged, locked), draft, description, url, author, source, target, sha, merged, mergeStatus, conflicts, pipeline,
    labels, assignees, reviewers, project, created, updated, merged_at, closed }
*/

namespace models\node;

class NodeAppGitLabMergeRequests extends NodeAppGitLab {
    public static $name = 'gitlab-merge-requests';
    public static $label = 'GitLab Merge Requests';
    public static $icon = 'bi-git';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'mr';

    const OPERATIONS = [
	'list' => 'List merge requests',
	'get' => 'Get a merge request',
	'changes' => 'List the changed files',
	'pipelines' => 'List its pipelines',
	'create' => 'Create a merge request',
	'update' => 'Update a merge request',
	'comment' => 'Add a comment',
	'approve' => 'Approve',
	'unapprove' => 'Remove the approval',
	'reviewers' => 'Set reviewers',
	'merge' => 'Merge',
    ];
    const WRITES = [ 'create', 'update', 'comment', 'approve', 'unapprove', 'reviewers', 'merge' ];
    const LISTS = [ 'list', 'changes', 'pipelines' ];
    const STATES = [ 'opened' => 'Open', 'merged' => 'Merged', 'closed' => 'Closed', 'all' => 'All' ];
    const SORTS = [ 'created_at' => 'Newest first', 'updated_at' => 'Recently updated' ];
    const MAX_DIFF = 20000;
    const BY_NUMBER = [ 'get', 'changes', 'pipelines', 'update', 'comment', 'approve', 'unapprove', 'reviewers', 'merge' ];

    protected static function operationFields() {
	return [
	    self::field('number', 'Merge request', self::BY_NUMBER, [ 'required' => true, 'help' => 'Its number (12, !12) or address, e.g. {{ mr.number }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('state', 'State', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'opened', 'choices' => self::STATES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('sort', 'Order', [ 'list' ], [ 'type' => 'select', 'required' => true, 'default' => 'created_at', 'choices' => self::SORTS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('source', 'Source branch', [ 'list', 'create' ], [ 'help' => 'The branch with the changes (list: optional)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('target', 'Target branch', [ 'list', 'create', 'update' ], [ 'help' => 'Where they go (create: empty takes the default branch)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('title', 'Title', [ 'create', 'update' ], [ 'help' => 'Update: empty leaves it', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('description', 'Description', [ 'create', 'update' ], [ 'type' => 'textarea', 'help' => 'Markdown; update: empty leaves it', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('draft', 'Draft', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('removeSource', 'Remove the source branch', [ 'create', 'merge' ], [ 'type' => 'switch', 'default' => false, 'help' => 'When it is merged', 'ui' => [ 'width' => 4 ] ]),
	    self::field('squash', 'Squash', [ 'create', 'merge' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('newState', 'State', [ 'update' ], [ 'type' => 'select', 'default' => '', 'choices' => [ '' => 'As it is', 'close' => 'Close', 'reopen' => 'Reopen' ], 'ui' => [ 'width' => 6 ] ]),
	    self::field('comment', 'Comment', [ 'comment' ], [ 'type' => 'textarea', 'help' => 'Markdown', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	    self::field('people', 'Reviewers', [ 'reviewers' ], [ 'required' => true, 'help' => 'Usernames, separated by commas, or a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('when', 'Merge', [ 'merge' ], [ 'type' => 'select', 'required' => true, 'default' => 'now', 'choices' => [ 'now' => 'Now', 'pipeline' => 'When the pipeline succeeds' ], 'ui' => [ 'width' => 6 ] ]),
	    self::field('message', 'Commit message', [ 'merge' ], [ 'type' => 'textarea', 'help' => 'Optional (with Squash: the squashed commit\'s)', 'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if (in_array($op, [ 'comment', 'approve', 'unapprove' ], true))
	    return [ $name => [ 'type' => 'object', 'info' => $op === 'comment' ? 'the comment: { id, author, body, created }' : '{ approved, approvedBy }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the merge request: { number, title, state, url, source, target, sha, merged, mergeStatus, pipeline, … }', 'fields' => [ 'number' => [ 'type' => 'number' ],
	    'title' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string', 'info' => 'opened, closed, merged or locked' ], 'url' => [ 'type' => 'string' ], 'source' => [ 'type' => 'string' ],
	    'target' => [ 'type' => 'string' ], 'sha' => [ 'type' => 'string' ], 'merged' => [ 'type' => 'boolean' ], 'mergeStatus' => [ 'type' => 'string', 'info' => 'mergeable, conflict, ci_must_pass, …' ],
	    'pipeline' => [ 'type' => 'string', 'info' => 'the head pipeline\'s status' ] ] ] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$project = $this->project();
	if ($operation === 'list') {
	    $q = array_filter([ 'state' => (string)$this->cfg('state', 'opened') === 'all' ? null : (string)$this->cfg('state', 'opened'), 'order_by' => (string)$this->cfg('sort', 'created_at'), 'sort' => 'desc',
		'source_branch' => $text('source'), 'target_branch' => $text('target') ], fn($v) => $v !== null && $v !== '');
	    return $this->listOf("/projects/{$project}/merge_requests", $q, 'merge request', fn($m) => self::mergeRequestOf($m));
	}
	if ($operation === 'create') {
	    $source = $text('source');
	    $title = $text('title');
	    if ($source === '' || $title === '')
		throw new \RuntimeException('a merge request needs its source branch and a title');
	    $target = $text('target');
	    if ($target === '')
		$target = (string)($this->gl->request('GET', "/projects/{$project}")['default_branch'] ?? 'main');
	    if ($this->cfg('draft', false) && !preg_match('/^(draft:|\[draft\])/i', $title))
		$title = "Draft: {$title}";
	    $m = self::mergeRequestOf($this->gl->request('POST', "/projects/{$project}/merge_requests", [], array_filter([ 'source_branch' => $source, 'target_branch' => $target,
		'title' => mb_substr($title, 0, 255), 'description' => \util\Expression::toText($value('description')), 'remove_source_branch' => $this->cfg('removeSource', false) ? true : null,
		'squash' => $this->cfg('squash', false) ? true : null ], fn($v) => $v !== null && $v !== '')));
	    return [ $m, "!{$m['number']} created: {$source} into {$target}" ];
	}
	$n = \util\GitLab::iid($value('number'), 'Merge request');
	$path = "/projects/{$project}/merge_requests/{$n}";
	switch($operation) {
	    case 'get':
		$m = self::mergeRequestOf($this->gl->request('GET', $path));
		return [ $m, "!{$n} {$m['title']} ({$m['state']}, {$m['mergeStatus']})" ];
	    case 'changes':
		return $this->listOf("{$path}/diffs", [], 'file', fn($d) => [ 'path' => (string)($d['new_path'] ?? ''), 'old' => (string)($d['old_path'] ?? ''),
		    'status' => !empty($d['new_file']) ? 'added' : (!empty($d['deleted_file']) ? 'deleted' : (!empty($d['renamed_file']) ? 'renamed' : 'modified')), 'diff' => mb_substr((string)($d['diff'] ?? ''), 0, self::MAX_DIFF) ]);
	    case 'pipelines':
		return $this->listOf("{$path}/pipelines", [], 'pipeline', fn($p) => NodeAppGitLabPipelines::pipelineOf($p));
	    case 'update':
		$body = [];
		if ($text('title') !== '')
		    $body['title'] = mb_substr($text('title'), 0, 255);
		$desc = \util\Expression::toText($value('description'));
		if (trim($desc) !== '')
		    $body['description'] = $desc;
		if ($text('target') !== '')
		    $body['target_branch'] = $text('target');
		if (in_array((string)$this->cfg('newState', ''), [ 'close', 'reopen' ], true))
		    $body['state_event'] = (string)$this->cfg('newState');
		if (!$body)
		    throw new \RuntimeException('nothing to change: give a title, a description, a target branch or a state');
		$m = self::mergeRequestOf($this->gl->request('PUT', $path, [], $body));
		return [ $m, "!{$n} updated (" . implode(', ', array_keys($body)) . ')' ];
	    case 'comment':
		$comment = \util\Expression::toText($value('comment'));
		if (trim($comment) === '')
		    throw new \RuntimeException('Comment: empty');
		$c = NodeAppGitLabIssues::noteOf($this->gl->request('POST', "{$path}/notes", [], [ 'body' => $comment ]));
		return [ $c, "comment added to !{$n}" ];
	    case 'approve':
	    case 'unapprove':
		$this->gl->request('POST', "{$path}/{$operation}", [], (object)[]);
		$a = $this->gl->request('GET', "{$path}/approvals");
		return [ [ 'approved' => (bool)($a['approved'] ?? false), 'approvedBy' => array_values(array_map(fn($x) => (string)($x['user']['username'] ?? ''), (array)($a['approved_by'] ?? []))) ],
		    "!{$n} " . ($operation === 'approve' ? 'approved' : 'approval removed') ];
	    case 'reviewers':
		$ids = $this->userIds($value('people'), 'Reviewers');
		if (!$ids)
		    throw new \RuntimeException('Reviewers: none given');
		$m = self::mergeRequestOf($this->gl->request('PUT', $path, [], [ 'reviewer_ids' => $ids ]));
		return [ $m, "!{$n}: reviewers " . implode(', ', $m['reviewers']) ];
	    case 'merge':
		$auto = (string)$this->cfg('when', 'now') === 'pipeline';
		$message = \util\Expression::toText($value('message'));
		$squash = (bool)$this->cfg('squash', false);
		$body = array_filter([ 'squash' => $squash ? true : null, 'should_remove_source_branch' => $this->cfg('removeSource', false) ? true : null,
		    'merge_when_pipeline_succeeds' => $auto ? true : null, $squash ? 'squash_commit_message' : 'merge_commit_message' => trim($message) !== '' ? $message : null ], fn($v) => $v !== null);
		try {
		    $m = self::mergeRequestOf($this->gl->request('PUT', "{$path}/merge", [], $body ?: (object)[]));
		} catch (\RuntimeException $e) {
		    if (preg_match('/^HTTP status (405|406|409|422): (.*)$/s', $e->getMessage(), $x))
			throw new \RuntimeException("!{$n} cannot be merged: {$x[2]}");
		    throw $e;
		}
		return [ $m, "!{$n} " . ($m['merged'] ? 'merged' : ($auto ? 'set to merge when the pipeline succeeds' : $m['state'])) ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
