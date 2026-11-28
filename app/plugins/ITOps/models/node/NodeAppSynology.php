<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: the base of the Synology steps (Files, System, Storage, Users, Backup, API; not registered itself), through a Synology credential of the
    project (CredentialSynology, provides `synology`; plugins\ITOps\util\Synology). As NodeAppPatchMon:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Synology step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the call's parameters), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); signed in at the first call, signed out at the end; the result in the variable, logged as "<label> <operation>: <summary>
	    (<n> ms)"; a \RuntimeException (DSM's errors, no connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, total, truncated } (up to At most)
*/

namespace plugins\ITOps\models\node;

abstract class NodeAppSynology extends \models\wf\WfNode {
    //its operations can be an AI step's tools (util\agent\Tools: settingValue, setResult)
    use \models\node\AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-hdd-network';
    public static $category = 'storage';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'nas';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 3600;
    const PAGE = 500;

    //the client of the run
    protected $nas = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('synology', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Synology credential',
		'choices' => $choices,
		'help' => 'A Synology NAS credential of the project',
		'required' => true,
	    ],
	    [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => static::$defaultOperation,
		'choices' => static::OPERATIONS,
	    ],
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
	$fields[] = [
	    'key' => 'params',
	    'type' => 'textarea',
	    'label' => 'Other parameters',
	    'default' => '',
	    'help' => 'A JSON object added to the parameters of DSM\'s call (arrays and objects are sent as JSON)',
	    'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true ],
	];
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 1000,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => 60,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => 'A list: { items, count, total, truncated }; else DSM\'s answer',
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
	    'help' => 'DSM\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => static::itemInfo($op) ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items given' ],
		'total' => [ 'type' => 'number', 'info' => 'the items the NAS has' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'DSM\'s answer (null on error)' ] ];
    }

    //what an item of the list of $op holds (the variables' info)
    protected static function itemInfo($op) {
	return 'an item, as DSM gives it';
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'synology')))) {
	    $this->errors('config', static::$label . ': choose a Synology credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function timeoutSeconds() {
	return max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60)));
    }

    public function execute($edgeId = null) {
	//the settings through settingValue() (as they are when the step runs as a tool: AgentToolTrait)
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'synology');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->nas = $cred->client($this->exec->aid, $this->timeoutSeconds());

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
	} finally {
	    $this->nas->signOut();
	}
	//setResult() (a tool keeps it)
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //Other parameters: a JSON object (or an expression giving one); [] when empty
    protected function extraParams() {
	$raw = trim((string)$this->cfg('params', ''));
	if ($raw === '')
	    return [];
	$v = \util\Expression::render($raw, $this->exec->expressionVariables());
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException('Other parameters: not a JSON object');
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException('Other parameters: not a JSON object');
	return $v;
    }

    //a call with Other parameters added
    protected function call($api, $method, array $params = [], $version = null) {
	return $this->nas->call($api, $method, array_replace($params, $this->extraParams()), $version);
    }

    //the max items of a list
    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
    }

    //a list read by pages (offset / limit) until At most + 1 or its end: [ rows, total ]; $key: the rows' key in the data
    protected function pages($api, $method, array $params, $key, $version = null) {
	$max = $this->maxItems();
	$rows = [];
	$total = null;
	for($offset = 0; count($rows) <= $max; $offset += self::PAGE) {
	    $d = $this->call($api, $method, $params + [ 'offset' => $offset, 'limit' => self::PAGE ], $version);
	    $page = array_values(array_filter((array)($d[$key] ?? []), 'is_array'));
	    $total = (int)($d['total'] ?? count($page));
	    $rows = array_merge($rows, $page);
	    if (count($page) < self::PAGE || count($rows) >= $total)
		break;
	}
	return [ $rows, $total ?? count($rows) ];
    }

    //a list of rows: [ { items, count, total, truncated }, summary ] (up to At most)
    protected function listResult(array $rows, $what, $total = null) {
	$max = $this->maxItems();
	$rows = array_values($rows);
	$total ??= count($rows);
	$truncated = count($rows) > $max || $total > $max;
	$rows = array_slice($rows, 0, $max);
	return [ [ 'items' => $rows, 'count' => count($rows), 'total' => (int)$total, 'truncated' => $truncated ],
	    \util\Format::plural(count($rows), $what) . ($truncated ? ' (truncated)' : '') ];
    }

    //texts: a list, or one per line or comma (e.g. names)
    public static function names($value, $key = 'name') {
	$parts = is_array($value) ? array_values($value) : preg_split('/[\r\n,]+/', \util\Expression::toText($value));
	return array_values(array_unique(array_filter(array_map(fn($v) => trim(\util\Expression::toText(is_array($v) ? ($v[$key] ?? '') : $v)), $parts), fn($v) => $v !== '')));
    }

    //a path on the NAS: /<shared folder>/...; no `..`, no control characters
    public static function path($value, $label = 'Path') {
	if (is_array($value))
	    $value = $value['path'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	$v = '/' . trim(preg_replace('~/+~', '/', $v), '/');
	if (preg_match('/[\x00-\x1f]/', $v) || in_array('..', explode('/', $v), true))
	    throw new \RuntimeException("{$label}: not a path on the NAS: `" . mb_substr($v, 0, 120) . '`');
	if ($v === '/')
	    throw new \RuntimeException("{$label}: a shared folder or below it, e.g. /docs/reports");
	return $v;
    }

    //paths: a list (of texts or items with path), or one per line (commas can be in names)
    public static function paths($value, $label = 'Paths') {
	$parts = is_array($value) ? (array_is_list($value) ? $value : [ $value ]) : preg_split('/[\r\n]+/', \util\Expression::toText($value));
	$out = [];
	foreach($parts as $p)
	    if (trim(\util\Expression::toText(is_array($p) ? ($p['path'] ?? '') : $p)) !== '')
		$out[] = self::path($p, $label);
	if (!$out)
	    throw new \RuntimeException("{$label}: none given");
	return array_values(array_unique($out));
    }

    //a bytes value of DSM (numbers often as text)
    public static function bytes($v) {
	return is_numeric($v) ? (int)$v : 0;
    }
}
