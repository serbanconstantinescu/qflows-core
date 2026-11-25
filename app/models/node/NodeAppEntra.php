<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: the base of the Entra ID steps (Users, Groups, Access, API; not registered itself), through an Entra ID credential of the project
    (CredentialEntra, provides `entra`; util\Entra, Microsoft Graph). As NodeAppSlack:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Entra ID step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Graph's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }
    What the steps share: a user by user principal name (ana@acme.com), object ID, or an item ({ id }); a group by object ID or display name (looked up)
*/

namespace models\node;

abstract class NodeAppEntra extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-person-badge';
    public static $category = 'security';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'entra';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;

    //the client of the run
    protected $graph = null;

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
	    foreach(\models\Credential::choicesFor('entra', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Entra ID credential',
		'choices' => self::credentialChoices(),
		'help' => 'An Entra ID credential of the project',
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
	    'help' => 'Graph\'s errors (e.g. not found, a permission not granted), or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, ?array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the User setting of operations $ops
    protected static function userField(array $ops, $key = 'user', $label = 'User') {
	return self::field($key, $label, $ops, [ 'required' => true, 'help' => 'The user principal name (ana@acme.com) or object ID, e.g. {{ user.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]);
    }

    //the Group setting of operations $ops
    protected static function groupField(array $ops, $key = 'group', $label = 'Group') {
	return self::field($key, $label, $ops, [ 'required' => true, 'help' => 'The object ID or display name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]);
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'entra')))) {
	    $this->errors('config', static::$label . ': choose an Entra ID credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'entra');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->graph = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

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
    protected function listOf($path, array $query, $what, \Closure $map, array $o = []) {
	[ $items, $truncated ] = $this->graph->pages($path, $query, $this->maxItems(), $o);
	$items = array_values(array_map($map, $items));
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' (more left out)' : '') ];
    }

    //a user of a setting, for a path: its object ID or user principal name (encoded); an item ({ id } / { userPrincipalName })
    public static function userRef($value, $label = 'User') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['userPrincipalName'] ?? $value['upn'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (\util\Entra::isGuid($v))
	    return $v;
	if (!preg_match('/^[^\s@\/\\#?]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $v))
	    throw new \RuntimeException("{$label}: a user principal name (ana@acme.com) or an object ID: `" . mb_substr($v, 0, 80) . '`');
	return rawurlencode($v);
    }

    //users of a setting: a list, or separated by commas / new lines
    protected static function userRefs($value, $label = 'Users') {
	$out = [];
	foreach(NodeAppZendesk::names($value, [ 'id', 'userPrincipalName' ]) as $u)
	    $out[] = self::userRef($u, $label);
	if (!$out)
	    throw new \RuntimeException("{$label}: none given");
	return array_values(array_unique($out));
    }

    //a user's object ID (a UPN looked up)
    protected function userId($value, $label = 'User') {
	$ref = self::userRef($value, $label);
	return \util\Entra::isGuid($ref) ? $ref : (string)($this->graph->request('GET', "/users/{$ref}", [ '$select' => 'id' ])['id'] ?? '');
    }

    //a group's object ID of a setting: its ID, an item ({ id }), or its display name (exactly one group)
    protected function groupId($value, $label = 'Group') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['displayName'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (\util\Entra::isGuid($v))
	    return $v;
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	$found = (array)($this->graph->request('GET', '/groups', [ '$filter' => 'displayName eq ' . \util\Entra::quote($v), '$select' => 'id,displayName', '$top' => 5 ])['value'] ?? []);
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

    const USER_FIELDS = 'id,displayName,givenName,surname,userPrincipalName,mail,jobTitle,department,officeLocation,mobilePhone,accountEnabled,userType,createdDateTime,usageLocation,employeeId,onPremisesSyncEnabled';

    //a user of Graph's answers
    public static function userOf($u) {
	$u = is_array($u) ? $u : [];
	return [
	    'id' => (string)($u['id'] ?? ''),
	    'displayName' => (string)($u['displayName'] ?? ''),
	    'givenName' => (string)($u['givenName'] ?? ''),
	    'surname' => (string)($u['surname'] ?? ''),
	    'upn' => (string)($u['userPrincipalName'] ?? ''),
	    'mail' => (string)($u['mail'] ?? ''),
	    'jobTitle' => (string)($u['jobTitle'] ?? ''),
	    'department' => (string)($u['department'] ?? ''),
	    'office' => (string)($u['officeLocation'] ?? ''),
	    'mobile' => (string)($u['mobilePhone'] ?? ''),
	    'enabled' => (bool)($u['accountEnabled'] ?? false),
	    'type' => (string)($u['userType'] ?? ''),
	    'employeeId' => (string)($u['employeeId'] ?? ''),
	    'usageLocation' => (string)($u['usageLocation'] ?? ''),
	    'synced' => (bool)($u['onPremisesSyncEnabled'] ?? false),
	    'created' => (string)($u['createdDateTime'] ?? ''),
	];
    }

    const GROUP_FIELDS = 'id,displayName,description,mail,mailEnabled,securityEnabled,groupTypes,membershipRule,createdDateTime,visibility,onPremisesSyncEnabled';

    //a group of Graph's answers
    public static function groupOf($g) {
	$g = is_array($g) ? $g : [];
	$types = array_map('strval', (array)($g['groupTypes'] ?? []));
	return [
	    'id' => (string)($g['id'] ?? ''),
	    'displayName' => (string)($g['displayName'] ?? ''),
	    'description' => (string)($g['description'] ?? ''),
	    'mail' => (string)($g['mail'] ?? ''),
	    'kind' => in_array('Unified', $types, true) ? 'microsoft365' : (!empty($g['securityEnabled']) ? 'security' : 'distribution'),
	    'dynamic' => in_array('DynamicMembership', $types, true),
	    'rule' => (string)($g['membershipRule'] ?? ''),
	    'synced' => (bool)($g['onPremisesSyncEnabled'] ?? false),
	    'visibility' => (string)($g['visibility'] ?? ''),
	    'created' => (string)($g['createdDateTime'] ?? ''),
	];
    }
}
