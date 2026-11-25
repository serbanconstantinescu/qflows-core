<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: GitLab Pipelines - a project's CI/CD pipelines and jobs (NodeAppGitLab). Operations:
	run        a pipeline on a branch or tag, with variables; followed to the end with Wait (or until the step's timeout: then as it is, finished false)
	pipelines  the pipelines, newest first: of a branch, a status (success, failed, running, …), a source (push, schedule, web, api, merge_request_event, …)
	get        a pipeline; wait: a pipeline followed until it ends
	jobs       a pipeline's jobs; log: a job's log (its last lines)
	retry      a pipeline's failed jobs again; cancel
	retryJob, playJob (a manual job started)
    A pipeline: { id, iid, status, finished, ref, sha, source, url, user, created, updated, duration }
*/

namespace models\node;

class NodeAppGitLabPipelines extends NodeAppGitLab {
    public static $name = 'gitlab-pipelines';
    public static $label = 'GitLab Pipelines';
    public static $icon = 'bi-play-circle';
    public static $defaultOperation = 'run';
    public static $defaultVariable = 'pipeline';
    const DEFAULT_TIMEOUT = 600;

    const OPERATIONS = [
	'run' => 'Run a pipeline',
	'pipelines' => 'List pipelines',
	'get' => 'Get a pipeline',
	'wait' => 'Wait for a pipeline to end',
	'jobs' => 'List a pipeline\'s jobs',
	'log' => 'Read a job\'s log',
	'retry' => 'Retry a pipeline',
	'cancel' => 'Cancel a pipeline',
	'retryJob' => 'Retry a job',
	'playJob' => 'Start a manual job',
    ];
    const WRITES = [ 'run', 'retry', 'cancel', 'retryJob', 'playJob' ];
    const LISTS = [ 'pipelines', 'jobs' ];
    const STATUSES = [ '' => 'Any', 'success' => 'Succeeded', 'failed' => 'Failed', 'running' => 'Running', 'pending' => 'Pending', 'canceled' => 'Cancelled', 'manual' => 'Waiting (manual)' ];
    //a pipeline's statuses that are its end
    const ENDS = [ 'success', 'failed', 'canceled', 'skipped', 'manual' ];
    //seconds between two looks (the check suite: less)
    public static $poll = 5;
    const MAX_LOG = 200000;
    const BY_PIPELINE = [ 'get', 'wait', 'jobs', 'retry', 'cancel' ];

    protected static function operationFields() {
	return [
	    self::field('ref', 'Branch or tag', [ 'run', 'pipelines' ], [ 'help' => 'Run: empty is the default branch; list: optional', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('variables', 'Variables', [ 'run' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object, e.g. {"ENVIRONMENT": "staging"}', 'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	    self::field('waitEnd', 'Wait for it to end', [ 'run' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Until it ends or the step\'s timeout', 'ui' => [ 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'pipelines' ], [ 'type' => 'select', 'default' => '', 'choices' => self::STATUSES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('source', 'Source', [ 'pipelines' ], [ 'help' => 'Optional: push, schedule, web, api, merge_request_event, …', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('pipelineId', 'Pipeline', self::BY_PIPELINE, [ 'required' => true, 'help' => 'Its ID or address, e.g. {{ pipeline.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('jobId', 'Job', [ 'log', 'retryJob', 'playJob' ], [ 'required' => true, 'help' => 'Its ID or address, e.g. {{ jobs.items[0].id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
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
	    return [ $name => [ 'type' => 'object', 'info' => '{ job, lines, total, text }', 'fields' => [ 'text' => [ 'type' => 'string' ] ] ] ];
	if (in_array($op, [ 'retryJob', 'playJob' ], true))
	    return [ $name => [ 'type' => 'object', 'info' => 'the job: { id, name, stage, status, url }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the pipeline: { id, status, finished, ref, sha, url, … }', 'fields' => [ 'id' => [ 'type' => 'number' ],
	    'status' => [ 'type' => 'string', 'info' => 'created, pending, running, success, failed, canceled, skipped, manual' ], 'finished' => [ 'type' => 'boolean' ], 'ref' => [ 'type' => 'string' ],
	    'sha' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string' ] ] ] ];
    }

    public static function pipelineOf($p) {
	$p = is_array($p) ? $p : [];
	return [ 'id' => (int)($p['id'] ?? 0), 'iid' => (int)($p['iid'] ?? 0), 'status' => (string)($p['status'] ?? ''), 'finished' => in_array($p['status'] ?? '', self::ENDS, true),
	    'ref' => (string)($p['ref'] ?? ''), 'sha' => (string)($p['sha'] ?? ''), 'source' => (string)($p['source'] ?? ''), 'url' => (string)($p['web_url'] ?? ''), 'user' => (string)($p['user']['username'] ?? ''),
	    'created' => (string)($p['created_at'] ?? ''), 'updated' => (string)($p['updated_at'] ?? ''), 'duration' => isset($p['duration']) ? (int)$p['duration'] : null ];
    }

    public static function jobOf($j) {
	$j = is_array($j) ? $j : [];
	return [ 'id' => (int)($j['id'] ?? 0), 'name' => (string)($j['name'] ?? ''), 'stage' => (string)($j['stage'] ?? ''), 'status' => (string)($j['status'] ?? ''), 'url' => (string)($j['web_url'] ?? ''),
	    'started' => (string)($j['started_at'] ?? ''), 'finished' => (string)($j['finished_at'] ?? ''), 'duration' => isset($j['duration']) ? (float)$j['duration'] : null,
	    'allowFailure' => (bool)($j['allow_failure'] ?? false), 'failureReason' => (string)($j['failure_reason'] ?? '') ];
    }

    //a pipeline's or job's ID of a setting: a number, an item ({ id }), or its address (…/pipelines/123, …/jobs/456)
    private static function idOf($value, $label) {
	if (is_array($value))
	    $value = $value['id'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (preg_match('~/(?:pipelines|jobs)/(\d+)~', $v, $m))
	    $v = $m[1];
	if (!preg_match('/^\d{1,20}$/', $v))
	    throw new \RuntimeException("{$label}: an ID (a number) or its address: `" . mb_substr($v, 0, 80) . '`');
	return $v;
    }

    //a pipeline followed until it ends, or until the step's timeout is near
    private function follow($project, $id) {
	$p = $this->gl->request('GET', "/projects/{$project}/pipelines/{$id}");
	while (!in_array($p['status'] ?? '', self::ENDS, true) && $this->gl->remaining() > self::$poll + 10) {
	    usleep((int)(self::$poll * 1000000));
	    $p = $this->gl->request('GET', "/projects/{$project}/pipelines/{$id}");
	}
	return self::pipelineOf($p);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$project = $this->project();
	switch($operation) {
	    case 'run':
		$ref = $text('ref');
		if ($ref === '')
		    $ref = (string)($this->gl->request('GET', "/projects/{$project}")['default_branch'] ?? 'main');
		$vars = [];
		foreach((array)($this->jsonOf('variables', 'Variables') ?? []) as $k => $v) {
		    if (!preg_match('/^[A-Za-z0-9_]{1,255}$/', (string)$k))
			throw new \RuntimeException("Variables: not a variable name: `{$k}`");
		    $vars[] = [ 'key' => (string)$k, 'value' => is_bool($v) ? ($v ? 'true' : 'false') : \util\Expression::toText($v), 'variable_type' => 'env_var' ];
		}
		$p = $this->gl->request('POST', "/projects/{$project}/pipeline", [], array_filter([ 'ref' => $ref, 'variables' => $vars ?: null ]));
		$r = $this->cfg('waitEnd', true) ? $this->follow($project, (int)$p['id']) : self::pipelineOf($p);
		return [ $r, "pipeline {$r['id']} on {$ref}: {$r['status']}" ];
	    case 'pipelines':
		$q = array_filter([ 'ref' => $text('ref'), 'status' => isset(self::STATUSES[(string)$this->cfg('status', '')]) ? (string)$this->cfg('status', '') : '', 'source' => $text('source'),
		    'order_by' => 'id', 'sort' => 'desc' ]);
		return $this->listOf("/projects/{$project}/pipelines", $q, 'pipeline', fn($p) => self::pipelineOf($p));
	    case 'get':
	    case 'wait':
		$id = self::idOf($value('pipelineId'), 'Pipeline');
		$r = $operation === 'wait' ? $this->follow($project, $id) : self::pipelineOf($this->gl->request('GET', "/projects/{$project}/pipelines/{$id}"));
		return [ $r, "pipeline {$id}: {$r['status']}" ];
	    case 'jobs':
		$id = self::idOf($value('pipelineId'), 'Pipeline');
		return $this->listOf("/projects/{$project}/pipelines/{$id}/jobs", [ 'include_retried' => 'false' ], 'job', fn($j) => self::jobOf($j));
	    case 'log':
		$id = self::idOf($value('jobId'), 'Job');
		$log = $this->gl->request('GET', "/projects/{$project}/jobs/{$id}/trace", [], null, [ 'text' => true, 'maxBytes' => 50 * 1024 * 1024 ]);
		//the log's colors and sections (ANSI escapes, section_start / section_end markers) left out
		$log = preg_replace([ '/\x1b\[[0-9;]*[A-Za-z]/', '/section_(start|end):\d+:[A-Za-z0-9_.-]+(\[[^\]]*\])?\r?/' ], '', (string)$log);
		$lines = preg_split('/\r?\n/', rtrim($log));
		$keep = max(1, min(10000, (int)$this->cfg('lines', 200)));
		$tail = implode("\n", array_slice($lines, -$keep));
		return [ [ 'job' => (int)$id, 'lines' => min($keep, count($lines)), 'total' => count($lines), 'text' => mb_substr($tail, -self::MAX_LOG) ], "job {$id}: " . min($keep, count($lines)) . ' of ' . count($lines) . ' lines' ];
	    case 'retry':
	    case 'cancel':
		$id = self::idOf($value('pipelineId'), 'Pipeline');
		$r = self::pipelineOf($this->gl->request('POST', "/projects/{$project}/pipelines/{$id}/{$operation}", [], (object)[]));
		return [ $r, "pipeline {$id} " . ($operation === 'retry' ? 'retried' : 'cancelled') . ": {$r['status']}" ];
	    case 'retryJob':
	    case 'playJob':
		$id = self::idOf($value('jobId'), 'Job');
		$j = self::jobOf($this->gl->request('POST', "/projects/{$project}/jobs/{$id}/" . ($operation === 'retryJob' ? 'retry' : 'play'), [], (object)[]));
		return [ $j, "job {$j['name']} ({$j['id']}): {$j['status']}" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
