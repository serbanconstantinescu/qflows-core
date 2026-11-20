<?php

/*
    the base of the PatchMon steps (Hosts, Packages, Patching, API; not registered itself), through a PatchMon credential of the project (CredentialPatchMon, provides
    `patchmon`; plugins\ITOps\util\PatchMon). As NodeAppCheckmk:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every PatchMon step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    Other parameters (advanced: a JSON object added to the body of a change, or to the query of a read), At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (PatchMon's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step. A step that signed in as the credential's user signs out afterwards
	- a list's result: { items, count, total, truncated } (up to At most)
*/

namespace plugins\ITOps\models\node;

abstract class NodeAppPatchMon extends \models\wf\WfNode {
    //its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use \models\node\AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-bandaid';
    public static $category = 'monitoring';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'pm';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 3600;

    //the client of the run
    protected $pm = null;

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
	    foreach(\models\Credential::choicesFor('patchmon', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'PatchMon credential',
		'choices' => $choices,
		'help' => 'A PatchMon credential of the project',
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
	    'help' => 'A JSON object added to the body of a change or to the query of a read: see PatchMon\'s API reference (/api/v1/api-docs on your server)',
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
	    'help' => 'A list: { items, count, total, truncated }; else PatchMon\'s answer',
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
	    'help' => 'PatchMon\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the Host setting: an id, or a host name / friendly name
    protected static function hostField(array $ops, $required = true) {
	return self::field('host', 'Host', $ops, [ 'required' => $required, 'help' => 'Its id, host name or friendly name' . ($required ? '' : '; empty: every host'),
	    'ui' => [ 'expression' => true, 'width' => 6 ] ]);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item, as PatchMon gives it' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items given' ],
		'total' => [ 'type' => 'number', 'info' => 'the items PatchMon has' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'PatchMon\'s answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'patchmon')))) {
	    $this->errors('config', static::$label . ': choose a PatchMon credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	//the settings through settingValue() (as they are when the step runs as a tool: AgentToolTrait)
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'patchmon');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->pm = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))));

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
	    $this->pm->signOut();
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

    //a read of the integration API (Other parameters in the query)
    protected function get($path, array $query = []) {
	return $this->pm->api('GET', $path, array_replace($query, $this->extraParams()));
    }

    //a list of rows: [ { items, count, total, truncated }, summary ] (up to At most)
    protected function listResult(array $rows, $what, $total = null) {
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$rows = array_values($rows);
	$total ??= count($rows);
	$truncated = count($rows) > $max;
	$rows = array_slice($rows, 0, $max);
	return [ [ 'items' => $rows, 'count' => count($rows), 'total' => (int)$total, 'truncated' => $truncated ],
	    \util\Format::plural(count($rows), $what) . ($truncated ? ' (truncated)' : '') ];
    }

    //texts: a list, or one per line or comma (e.g. package names)
    public static function names($value, $key = 'name') {
	$parts = is_array($value) ? array_values($value) : preg_split('/[\r\n,]+/', \util\Expression::toText($value));
	return array_values(array_unique(array_filter(array_map(fn($v) => trim(\util\Expression::toText(is_array($v) ? ($v[$key] ?? '') : $v)), $parts), fn($v) => $v !== '')));
    }
}
