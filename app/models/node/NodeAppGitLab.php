<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: the base of the GitLab steps (Issues, Merge Requests, Repository, Pipelines, API; not registered itself), through a GitLab credential of the
    project (CredentialGitLab, provides `gitlab`; util\vendor\GitLab). As NodeAppGitHub:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every GitLab step has: credential, project (empty: the credential's default), operation (static::OPERATIONS), the operation's own settings
	    (operationFields(): each shown for its operations), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (GitLab's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }
    What the steps share: issues and merge requests by number (their iid: 12, #12, !12, their address, an item), people by username (their IDs looked up), labels as names
*/

namespace models\node;

abstract class NodeAppGitLab extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-gitlab';
    public static $category = 'development';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'gitlab';
    //whether the step works on a project
    public static $withProject = true;

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 3600;
    const DEFAULT_TIMEOUT = 60;

    //the client of the run
    protected $gl = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function credentialChoices() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('gitlab', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'GitLab credential',
		'choices' => self::credentialChoices(),
		'help' => 'A GitLab credential of the project',
		'required' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	];
	if (static::$withProject)
	    $fields[] = [
		'key' => 'project',
		'type' => 'text',
		'label' => 'Project',
		'default' => '',
		'help' => 'group/project (or its ID), e.g. acme/website; empty: the credential\'s default',
		'ui' => [ 'width' => 6, 'expression' => 'text' ],
	    ];
	$fields[] = [
	    'key' => 'operation',
	    'type' => 'select',
	    'label' => 'Operation',
	    'required' => true,
	    'default' => static::$defaultOperation,
	    'choices' => static::OPERATIONS,
	];
	foreach(static::operationFields() as $f) {
	    $ops = $f['ops'] ?? null;
	    unset($f['ops']);
	    $f += [ 'default' => '' ];
	    if ($ops !== null) {
		$f['ui'] = ($f['ui'] ?? []) + [ 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ] ];
		if (!empty($f['required'])) {
		    unset($f['required']);
		    $f['requiredIf'] = [ 'if' => 'operation', 'in' => $ops ];
		}
	    }
	    $fields[] = $f;
	}
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 100,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => static::DEFAULT_TIMEOUT,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => 'A list: { items, count, truncated }; otherwise what the operation gives',
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
	    'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	];
	$fields[] = [
	    'key' => 'onError',
	    'type' => 'select',
	    'label' => 'On error',
	    'required' => true,
	    'default' => 'fail',
	    'choices' => self::ON_ERROR,
	    'help' => 'GitLab\'s errors (e.g. not found, a protected branch), or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, ?array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'what the operation gives (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'gitlab')))) {
	    $this->errors('config', static::$label . ': choose a GitLab credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'gitlab');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->gl = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $this->runOperation($operation, $this->settingText(...), $this->settingOf(...));
	} catch (\RuntimeException $e) {
	    $this->setResult($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 100)));
    }

    //the project of the step: its API ID (a number, or its path encoded)
    protected function project() {
	return $this->gl->project($this->settingOf('project'));
    }

    //a list read through its pages, as At most cuts it: [ { items, count, truncated }, summary ]
    protected function listOf($path, array $query, $what, \Closure $map) {
	[ $items, $truncated ] = $this->gl->pages($path, $query, $this->maxItems());
	$items = array_values(array_map($map, $items));
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' (more left out)' : '') ];
    }

    //names (labels, usernames): a list (of texts, or of objects with name / username), or separated by commas / new lines
    public static function names($value) {
	return NodeAppZendesk::names($value, [ 'name', 'username' ]);
    }

    //users' IDs of usernames (a list, or separated by commas), looked up
    protected function userIds($value, $label = 'Users') {
	$ids = [];
	foreach(self::names($value) as $u) {
	    $u = ltrim($u, '@');
	    if (preg_match('/^\d{1,20}$/', $u)) {
		$ids[] = (int)$u;
		continue;
	    }
	    $found = $this->gl->request('GET', '/users', [ 'username' => $u ]);
	    if (!isset($found[0]['id']))
		throw new \RuntimeException("{$label}: no user {$u}");
	    $ids[] = (int)$found[0]['id'];
	}
	return $ids;
    }

    //a JSON setting: null when empty; $list: a JSON list
    protected function jsonOf($key, $label, $list = false) {
	$v = $this->settingOf($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return null;
	    $v = json_decode($v, true);
	}
	if ($v === null || $v === [] || $v === '')
	    return null;
	if (!is_array($v) || ($v && array_is_list($v) !== $list))
	    throw new \RuntimeException("{$label}: not a JSON " . ($list ? 'list' : 'object'));
	return $v;
    }

    //a time setting as ISO 8601 (UTC); null when empty
    protected static function timeOf($v, $label) {
	$v = trim(\util\Expression::toText($v));
	if ($v === '')
	    return null;
	$t = strtotime($v);
	if ($t === false)
	    throw new \RuntimeException("{$label}: not a date and time: `" . mb_substr($v, 0, 60) . '`');
	return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    //the project's path of an item (references.full: group/project#12, or web_url)
    protected static function projectOf($i) {
	$full = (string)($i['references']['full'] ?? '');
	if ($full !== '' && preg_match('~^(.+?)[#!]\d+$~', $full, $m))
	    return $m[1];
	return preg_match('~^https?://[^/]+/(.+?)/-/~', (string)($i['web_url'] ?? ''), $m) ? $m[1] : '';
    }

    //an issue of GitLab's answers
    public static function issueOf($i) {
	$i = is_array($i) ? $i : [];
	return [
	    'number' => (int)($i['iid'] ?? 0),
	    'id' => (int)($i['id'] ?? 0),
	    'title' => (string)($i['title'] ?? ''),
	    'state' => (string)($i['state'] ?? ''),
	    'description' => (string)($i['description'] ?? ''),
	    'url' => (string)($i['web_url'] ?? ''),
	    'author' => (string)($i['author']['username'] ?? ''),
	    'labels' => array_values(array_map(fn($l) => is_array($l) ? (string)($l['name'] ?? '') : (string)$l, (array)($i['labels'] ?? []))),
	    'assignees' => array_values(array_map(fn($a) => (string)($a['username'] ?? ''), (array)($i['assignees'] ?? []))),
	    'milestone' => (string)($i['milestone']['title'] ?? ''),
	    'due' => (string)($i['due_date'] ?? ''),
	    'confidential' => (bool)($i['confidential'] ?? false),
	    'comments' => (int)($i['user_notes_count'] ?? 0),
	    'project' => self::projectOf($i),
	    'created' => (string)($i['created_at'] ?? ''),
	    'updated' => (string)($i['updated_at'] ?? ''),
	    'closed' => (string)($i['closed_at'] ?? ''),
	];
    }

    //a merge request of GitLab's answers
    public static function mergeRequestOf($m) {
	$m = is_array($m) ? $m : [];
	return [
	    'number' => (int)($m['iid'] ?? 0),
	    'id' => (int)($m['id'] ?? 0),
	    'title' => (string)($m['title'] ?? ''),
	    'state' => (string)($m['state'] ?? ''),
	    'draft' => (bool)($m['draft'] ?? $m['work_in_progress'] ?? false),
	    'description' => (string)($m['description'] ?? ''),
	    'url' => (string)($m['web_url'] ?? ''),
	    'author' => (string)($m['author']['username'] ?? ''),
	    'source' => (string)($m['source_branch'] ?? ''),
	    'target' => (string)($m['target_branch'] ?? ''),
	    'sha' => (string)($m['sha'] ?? ''),
	    'merged' => ($m['state'] ?? '') === 'merged',
	    'mergeStatus' => (string)($m['detailed_merge_status'] ?? $m['merge_status'] ?? ''),
	    'conflicts' => (bool)($m['has_conflicts'] ?? false),
	    'pipeline' => (string)($m['head_pipeline']['status'] ?? $m['pipeline']['status'] ?? ''),
	    'labels' => array_values(array_map(fn($l) => is_array($l) ? (string)($l['name'] ?? '') : (string)$l, (array)($m['labels'] ?? []))),
	    'assignees' => array_values(array_map(fn($a) => (string)($a['username'] ?? ''), (array)($m['assignees'] ?? []))),
	    'reviewers' => array_values(array_map(fn($a) => (string)($a['username'] ?? ''), (array)($m['reviewers'] ?? []))),
	    'project' => self::projectOf($m),
	    'created' => (string)($m['created_at'] ?? ''),
	    'updated' => (string)($m['updated_at'] ?? ''),
	    'merged_at' => (string)($m['merged_at'] ?? ''),
	    'closed' => (string)($m['closed_at'] ?? ''),
	] + (isset($m['changes_count']) ? [ 'changes' => (string)$m['changes_count'] ] : []);
    }
}
