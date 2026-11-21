<?php
/*
    CLD: 2026-11-21T21:00:00+02:00: the base of the steps that run commands on a server (not registered itself): SSH (NodeAppSsh, Linux and Unix) and Windows (NodeAppWindows,
    PowerShell over WinRM), each through a credential of the project that provides static::CAPABILITY. As the integrations' steps:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings: credential, operation (static::OPERATIONS), the operation's own (operationFields(): each shown for its operations), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (no connection, a
	  refused sign-in, the script's error) follows On error; other errors fail the step
	- an AI step's tools (util\AgentTools families `ssh`, `windows`): WRITES are the operations that change something (run a script, a service started / stopped / restarted),
	  asked for approval by default; the others only read
*/

namespace models\node;

abstract class NodeAppRemote extends \models\wf\WfNode {
    use AgentToolTrait;

    public static $type = 'app';
    public static $category = 'developer';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];
    public static $defaultOperation = 'run';
    public static $defaultVariable = 'result';
    //the credential's capability and its label in the settings
    public static $capability = '';
    public static $credentialLabel = '';

    const OPERATIONS = [];
    const WRITES = [];
    const MAX_TIMEOUT = 3600;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor(static::$capability, \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [ 'key' => 'credential', 'type' => 'select', 'label' => static::$credentialLabel, 'choices' => $choices, 'required' => true ],
	    [ 'key' => 'operation', 'type' => 'select', 'label' => 'Operation', 'required' => true, 'default' => static::$defaultOperation, 'choices' => static::OPERATIONS ],
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
	$fields[] = [ 'key' => 'timeout', 'type' => 'number', 'label' => 'Timeout (s)', 'required' => true, 'default' => 120, 'help' => 'Signing in and the command',
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ], 'ui' => [ 'step' => 1 ] ];
	$fields[] = [ 'key' => 'variable', 'type' => 'text', 'label' => 'Result variable', 'required' => true, 'default' => static::$defaultVariable,
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ], 'ui' => [ 'width' => 6, 'variableName' => 'write' ] ];
	$fields[] = [ 'key' => 'onError', 'type' => 'select', 'label' => 'On error', 'required' => true, 'default' => 'fail', 'choices' => self::ON_ERROR,
	    'help' => 'No connection, a refused sign-in, the command\'s error', 'ui' => [ 'width' => 6 ] ];
	return $fields;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	return [ $name => static::resultInfo($op) ];
    }

    //the variable of operation $op
    protected static function resultInfo($op) {
	return [ 'type' => 'any', 'info' => 'the result (null on error)' ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, static::$capability)))) {
	    $this->errors('config', static::$label . ': choose ' . lcfirst(static::$credentialLabel) . ' of this project');
	    $valid = false;
	}
	return $valid;
    }

    public function execute($edgeId = null) {
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), static::$capability);
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->connect($cred, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120))));

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

    //the client of the run from credential $cred
    abstract protected function connect($cred, $timeout);

    //a parameters setting (a JSON object, or an expression giving one) as an array
    protected static function params($raw, \Closure $value, $key = 'parameters') {
	if (trim((string)$raw) === '')
	    return [];
	$params = $value($key);
	if (is_string($params)) {
	    $params = json_decode($params, true);
	    if (!is_array($params))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($params) || ($params && array_is_list($params)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	return $params;
    }
}
