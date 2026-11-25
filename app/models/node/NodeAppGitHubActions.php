<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: GitHub Actions - a repository's workflows and their runs (NodeAppGitHub). Operations:
	workflows  the workflows: { id, name, file, state }
	runs       the runs, newest first: of a workflow, a branch, a status (completed, success, failure, in_progress, queued, …), an event
	run        a run; wait: a run followed until it ends (or the step's timeout: then as it is, completed false)
	dispatch   a workflow started (its workflow_dispatch trigger) on a branch with inputs; the run found (GitHub does not return it), and followed to the end with Wait
	jobs       a run's jobs and their steps; log: a job's log (its last lines)
	rerun      a run again (all its jobs, or only the failed ones); cancel
	artifacts  a run's artifacts: { id, name, size, expired, url }
    A run: { id, name, number, attempt, event, status, conclusion, completed, branch, sha, url, actor, created, updated, workflow }
*/

namespace models\node;

class NodeAppGitHubActions extends NodeAppGitHub {
    public static $name = 'github-actions';
    public static $label = 'GitHub Actions';
    public static $icon = 'bi-play-circle';
    public static $defaultOperation = 'dispatch';
    public static $defaultVariable = 'run';
    const DEFAULT_TIMEOUT = 600;

    const OPERATIONS = [
	'dispatch' => 'Start a workflow',
	'runs' => 'List runs',
	'run' => 'Get a run',
	'wait' => 'Wait for a run to end',
	'jobs' => 'List a run\'s jobs',
	'log' => 'Read a job\'s log',
	'rerun' => 'Re-run a run',
	'cancel' => 'Cancel a run',
	'artifacts' => 'List a run\'s artifacts',
	'workflows' => 'List workflows',
    ];
    const WRITES = [ 'dispatch', 'rerun', 'cancel' ];
    const LISTS = [ 'runs', 'jobs', 'artifacts', 'workflows' ];
    const STATUSES = [ '' => 'Any', 'completed' => 'Completed', 'success' => 'Succeeded', 'failure' => 'Failed', 'cancelled' => 'Cancelled', 'in_progress' => 'In progress', 'queued' => 'Queued',
	'waiting' => 'Waiting (approval)' ];
    //seconds between two looks at a run (the check suite: less); how long a dispatched run is looked for
    public static $poll = 5;
    const FIND_FOR = 60;
    const MAX_LOG = 200000;
    const BY_RUN = [ 'run', 'wait', 'jobs', 'rerun', 'cancel', 'artifacts' ];

    protected static function operationFields() {
	return [
	    self::field('workflow', 'Workflow', [ 'dispatch', 'runs' ], [ 'help' => 'Its file name (deploy.yml), path or ID (runs: optional)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ref', 'Branch', [ 'dispatch', 'runs' ], [ 'help' => 'Start: the branch or tag (empty: the default branch); runs: optional', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('inputs', 'Inputs', [ 'dispatch' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object of the workflow\'s inputs, e.g. {"environment": "staging"}', 'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('waitEnd', 'Wait for it to end', [ 'dispatch' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Until it ends or the step\'s timeout', 'ui' => [ 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'runs' ], [ 'type' => 'select', 'default' => '', 'choices' => self::STATUSES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('event', 'Event', [ 'runs' ], [ 'help' => 'Optional: push, pull_request, schedule, workflow_dispatch, …', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('runId', 'Run', self::BY_RUN, [ 'required' => true, 'help' => 'Its ID or address, e.g. {{ run.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('failedOnly', 'Only the failed jobs', [ 'rerun' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 6 ] ]),
	    self::field('jobId', 'Job', [ 'log' ], [ 'required' => true, 'help' => 'Its ID, e.g. {{ jobs.items[0].id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('lines', 'Last lines', [ 'log' ], [ 'type' => 'number', 'required' => true, 'default' => 200, 'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 10000, 'skipEmpty' => true, 'message' => 'from 1 to 10000' ] ],
		'ui' => [ 'step' => 1, 'width' => 6 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if ($op === 'log')
	    return [ $name => [ 'type' => 'object', 'info' => '{ job, lines, text }', 'fields' => [ 'text' => [ 'type' => 'string' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the run: { id, status, conclusion, completed, url, … }', 'fields' => [ 'id' => [ 'type' => 'number' ], 'name' => [ 'type' => 'string' ],
	    'status' => [ 'type' => 'string', 'info' => 'queued, in_progress, completed, …' ], 'conclusion' => [ 'type' => 'string', 'info' => 'success, failure, cancelled, … (when completed)' ],
	    'completed' => [ 'type' => 'boolean' ], 'url' => [ 'type' => 'string' ], 'branch' => [ 'type' => 'string' ], 'sha' => [ 'type' => 'string' ] ] ] ];
    }

    public static function runOf($r) {
	$r = is_array($r) ? $r : [];
	return [ 'id' => (int)($r['id'] ?? 0), 'name' => (string)($r['name'] ?? ''), 'number' => (int)($r['run_number'] ?? 0), 'attempt' => (int)($r['run_attempt'] ?? 1), 'event' => (string)($r['event'] ?? ''),
	    'status' => (string)($r['status'] ?? ''), 'conclusion' => (string)($r['conclusion'] ?? ''), 'completed' => ($r['status'] ?? '') === 'completed', 'branch' => (string)($r['head_branch'] ?? ''),
	    'sha' => (string)($r['head_sha'] ?? ''), 'url' => (string)($r['html_url'] ?? ''), 'actor' => (string)($r['actor']['login'] ?? ''), 'created' => (string)($r['created_at'] ?? ''),
	    'updated' => (string)($r['updated_at'] ?? ''), 'workflow' => (int)($r['workflow_id'] ?? 0) ];
    }

    //a run's (or job's) ID of a setting: a number, an item ({ id }), or its address (…/runs/123, …/job/456)
    private static function idOf($value, $label) {
	if (is_array($value))
	    $value = $value['id'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (preg_match('~/(?:runs|job|jobs)/(\d+)~', $v, $m))
	    $v = $m[1];
	if (!preg_match('/^\d{1,20}$/', $v))
	    throw new \RuntimeException("{$label}: an ID (a number) or its address: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //a workflow's ID or file name of a setting (a path .github/workflows/x.yml: its file name)
    private static function workflowOf($value) {
	if (is_array($value))
	    $value = $value['id'] ?? $value['file'] ?? '';
	$v = basename(trim(\util\Expression::toText($value)));
	if (!preg_match('/^(\d{1,20}|[A-Za-z0-9_.-]{1,200}\.ya?ml)$/', $v))
	    throw new \RuntimeException('Workflow: its file name (deploy.yml) or ID: `' . mb_substr($v, 0, 80) . '`');
	return rawurlencode($v);
    }

    //a run followed until it ends, or until the step's timeout is near (then as it is: completed false)
    private function follow($repo, $id) {
	$run = $this->gh->request('GET', "/repos/{$repo}/actions/runs/{$id}");
	while (($run['status'] ?? '') !== 'completed' && $this->gh->remaining() > self::$poll + 10) {
	    usleep((int)(self::$poll * 1000000));
	    $run = $this->gh->request('GET', "/repos/{$repo}/actions/runs/{$id}");
	}
	return self::runOf($run);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$repo = $this->repository();
	switch($operation) {
	    case 'workflows':
		return $this->listOf("/repos/{$repo}/actions/workflows", [], 'workflow', fn($w) => [ 'id' => (int)($w['id'] ?? 0), 'name' => (string)($w['name'] ?? ''),
		    'file' => basename((string)($w['path'] ?? '')), 'path' => (string)($w['path'] ?? ''), 'state' => (string)($w['state'] ?? ''), 'url' => (string)($w['html_url'] ?? '') ], 'workflows');
	    case 'runs':
		$wf = $text('workflow');
		$path = $wf !== '' ? "/repos/{$repo}/actions/workflows/" . self::workflowOf($wf) . '/runs' : "/repos/{$repo}/actions/runs";
		$q = array_filter([ 'branch' => $text('ref'), 'status' => isset(self::STATUSES[(string)$this->cfg('status', '')]) ? (string)$this->cfg('status', '') : '', 'event' => $text('event') ]);
		return $this->listOf($path, $q, 'run', fn($r) => self::runOf($r), 'workflow_runs');
	    case 'dispatch':
		$wf = self::workflowOf($value('workflow'));
		$ref = $text('ref');
		if ($ref === '')
		    $ref = (string)($this->gh->request('GET', "/repos/{$repo}")['default_branch'] ?? 'main');
		$inputs = $this->jsonOf('inputs', 'Inputs');
		$since = gmdate('Y-m-d\TH:i:s\Z', time() - 10);
		$this->gh->request('POST', "/repos/{$repo}/actions/workflows/{$wf}/dispatches", [], array_filter([ 'ref' => $ref, 'inputs' => $inputs ? array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : \util\Expression::toText($v), $inputs) : null ]));
		//the run it made: the newest of that workflow, branch and event since then
		$run = null;
		$until = time() + self::FIND_FOR;
		while (!$run && time() < $until && $this->gh->remaining() > 5) {
		    usleep((int)(min(2, self::$poll) * 1000000));
		    $d = $this->gh->request('GET', "/repos/{$repo}/actions/workflows/{$wf}/runs", [ 'event' => 'workflow_dispatch', 'branch' => $ref, 'created' => ">={$since}", 'per_page' => 5 ]);
		    $run = ((array)($d['workflow_runs'] ?? []))[0] ?? null;
		}
		if (!$run)
		    return [ [ 'id' => 0, 'status' => 'requested', 'completed' => false, 'branch' => $ref, 'url' => '' ], rawurldecode($wf) . " started on {$ref} (its run not found yet)" ];
		$r = $this->cfg('waitEnd', true) ? $this->follow($repo, (int)$run['id']) : self::runOf($run);
		return [ $r, rawurldecode($wf) . " #{$r['number']} on {$ref}: " . ($r['completed'] ? $r['conclusion'] : $r['status']) ];
	    case 'run':
	    case 'wait':
		$id = self::idOf($value('runId'), 'Run');
		$r = $operation === 'wait' ? $this->follow($repo, $id) : self::runOf($this->gh->request('GET', "/repos/{$repo}/actions/runs/{$id}"));
		return [ $r, "run {$id}: " . ($r['completed'] ? $r['conclusion'] : $r['status']) ];
	    case 'jobs':
		$id = self::idOf($value('runId'), 'Run');
		return $this->listOf("/repos/{$repo}/actions/runs/{$id}/jobs", [], 'job', fn($j) => [ 'id' => (int)($j['id'] ?? 0), 'name' => (string)($j['name'] ?? ''), 'status' => (string)($j['status'] ?? ''),
		    'conclusion' => (string)($j['conclusion'] ?? ''), 'started' => (string)($j['started_at'] ?? ''), 'completed' => (string)($j['completed_at'] ?? ''), 'url' => (string)($j['html_url'] ?? ''),
		    'steps' => array_map(fn($s) => [ 'name' => (string)($s['name'] ?? ''), 'status' => (string)($s['status'] ?? ''), 'conclusion' => (string)($s['conclusion'] ?? '') ], (array)($j['steps'] ?? [])) ], 'jobs');
	    case 'log':
		$id = self::idOf($value('jobId'), 'Job');
		$log = $this->gh->request('GET', "/repos/{$repo}/actions/jobs/{$id}/logs", [], null, [ 'text' => true, 'redirects' => 3, 'maxBytes' => 50 * 1024 * 1024 ]);
		$lines = preg_split('/\r?\n/', rtrim((string)$log));
		$keep = max(1, min(10000, (int)$this->cfg('lines', 200)));
		$tail = implode("\n", array_slice($lines, -$keep));
		return [ [ 'job' => (int)$id, 'lines' => min($keep, count($lines)), 'total' => count($lines), 'text' => mb_substr($tail, -self::MAX_LOG) ], "job {$id}: " . min($keep, count($lines)) . ' of ' . count($lines) . ' lines' ];
	    case 'rerun':
		$id = self::idOf($value('runId'), 'Run');
		$this->gh->request('POST', "/repos/{$repo}/actions/runs/{$id}/" . ($this->cfg('failedOnly', true) ? 'rerun-failed-jobs' : 'rerun'), [], (object)[]);
		$r = self::runOf($this->gh->request('GET', "/repos/{$repo}/actions/runs/{$id}"));
		return [ $r, "run {$id} started again" . ($this->cfg('failedOnly', true) ? ' (the failed jobs)' : '') ];
	    case 'cancel':
		$id = self::idOf($value('runId'), 'Run');
		$this->gh->request('POST', "/repos/{$repo}/actions/runs/{$id}/cancel", [], (object)[]);
		$r = self::runOf($this->gh->request('GET', "/repos/{$repo}/actions/runs/{$id}"));
		return [ $r, "run {$id} cancelled" ];
	    case 'artifacts':
		$id = self::idOf($value('runId'), 'Run');
		return $this->listOf("/repos/{$repo}/actions/runs/{$id}/artifacts", [], 'artifact', fn($a) => [ 'id' => (int)($a['id'] ?? 0), 'name' => (string)($a['name'] ?? ''),
		    'size' => (int)($a['size_in_bytes'] ?? 0), 'expired' => (bool)($a['expired'] ?? false), 'url' => (string)($a['archive_download_url'] ?? '') ], 'artifacts');
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
