<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: the base of the Okta steps (Users, Groups, Access, API; not registered itself), through an Okta credential of the project
    (CredentialOkta, provides `okta`; util\vendor\Okta). As NodeAppEntra:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Okta step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Okta's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }
    What the steps share: a user by login (ana@acme.com), ID, or an item ({ id }); a group by ID or name (looked up)
*/

namespace models\node;

abstract class NodeAppOkta extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-shield-lock';
    public static $category = 'security';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'okta';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;

    //the client of the run
    protected $okta = null;

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
	    foreach(\models\Credential::choicesFor('okta', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Okta credential',
		'choices' => self::credentialChoices(),
		'help' => 'An Okta credential of the project',
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
	    'help' => 'Okta\'s errors (e.g. not found, a role or scope missing), or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, ?array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the User setting of operations $ops
    protected static function userField(array $ops, $key = 'user', $label = 'User') {
	return self::field($key, $label, $ops, [ 'required' => true, 'help' => 'The login (ana@acme.com) or ID, e.g. {{ user.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]);
    }

    //the Group setting of operations $ops
    protected static function groupField(array $ops, $key = 'group', $label = 'Group') {
	return self::field($key, $label, $ops, [ 'required' => true, 'help' => 'The ID or name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]);
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'okta')))) {
	    $this->errors('config', static::$label . ': choose an Okta credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'okta');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->okta = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

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

    //a list read through its pages, as At most cuts it: [ { items, count, truncated }, summary ]
    protected function listOf($path, array $query, $what, \Closure $map) {
	[ $items, $truncated ] = $this->okta->pages($path, $query, $this->maxItems());
	$items = array_values(array_map($map, $items));
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' (more left out)' : '') ];
    }

    //a user of a setting, for a path: its ID or login (encoded); an item ({ id } / { login })
    public static function userRef($value, $label = 'User') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['login'] ?? $value['profile']['login'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '' || strlen($v) > 200 || preg_match('/[\s\/\\#?]/', $v))
	    throw new \RuntimeException("{$label}: a login (ana@acme.com) or an ID: `" . mb_substr($v, 0, 80) . '`');
	return rawurlencode($v);
    }

    //a user's ID (a login looked up)
    protected function userId($value, $label = 'User') {
	$ref = self::userRef($value, $label);
	if (preg_match('/^00u[A-Za-z0-9]{10,30}$/', $ref))
	    return $ref;
	return (string)($this->okta->request('GET', "/users/{$ref}")['id'] ?? '');
    }

    //a group's ID of a setting: its ID (00g…), an item, or its name (exactly one group)
    protected function groupId($value, $label = 'Group') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['name'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (preg_match('/^00g[A-Za-z0-9]{10,30}$/', $v))
	    return $v;
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	$found = array_values(array_filter((array)$this->okta->request('GET', '/groups', [ 'q' => $v, 'limit' => 20 ]), fn($g) => strcasecmp((string)($g['profile']['name'] ?? ''), $v) === 0));
	if (count($found) !== 1)
	    throw new \RuntimeException("{$label}: " . ($found ? count($found) . " groups are named `{$v}`: give its ID" : "no group `{$v}`"));
	return (string)$found[0]['id'];
    }

    //a JSON object setting: null when empty
    protected function jsonOf($key, $label) {
	$v = $this->settingOf($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return null;
	    $v = json_decode($v, true);
	}
	if ($v === null || $v === [])
	    return null;
	if (!is_array($v) || array_is_list($v))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    //a user of Okta's answers
    public static function userOf($u) {
	$u = is_array($u) ? $u : [];
	$p = (array)($u['profile'] ?? []);
	return [
	    'id' => (string)($u['id'] ?? ''),
	    'login' => (string)($p['login'] ?? ''),
	    'email' => (string)($p['email'] ?? ''),
	    'firstName' => (string)($p['firstName'] ?? ''),
	    'lastName' => (string)($p['lastName'] ?? ''),
	    'displayName' => trim((string)($p['displayName'] ?? '') !== '' ? (string)$p['displayName'] : ($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')),
	    'status' => (string)($u['status'] ?? ''),
	    'department' => (string)($p['department'] ?? ''),
	    'title' => (string)($p['title'] ?? ''),
	    'mobile' => (string)($p['mobilePhone'] ?? ''),
	    'created' => (string)($u['created'] ?? ''),
	    'activated' => (string)($u['activated'] ?? ''),
	    'lastLogin' => (string)($u['lastLogin'] ?? ''),
	    'profile' => $p,
	];
    }

    //a group of Okta's answers
    public static function groupOf($g) {
	$g = is_array($g) ? $g : [];
	return [ 'id' => (string)($g['id'] ?? ''), 'name' => (string)($g['profile']['name'] ?? ''), 'description' => (string)($g['profile']['description'] ?? ''),
	    'type' => (string)($g['type'] ?? ''), 'created' => (string)($g['created'] ?? ''), 'membershipUpdated' => (string)($g['lastMembershipUpdated'] ?? '') ];
    }
}
