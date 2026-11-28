<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: the base of the Slack steps (Messages, Channels, Users, Files, API; not registered itself), through a Slack credential of the project
    (CredentialSlack, provides `slack`; util\vendor\Slack). As NodeAppZendesk:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Slack step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (Slack's errors, no
	    connection, a value that cannot be used) follows On error; other errors fail the step
	- a list's result: { items, count, truncated }
    What the steps share: a channel given by id (C…, G…, D…), #name or its link; a person by id (U…) or email (a message to a person: their direct-message channel);
    a message by its ts or link; the message text as Markdown (made Slack's mrkdwn), Slack's own mrkdwn, or plain text
*/

namespace models\node;

abstract class NodeAppSlack extends \models\wf\WfNode {
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-slack';
    public static $category = 'messaging';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'slack';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;
    const FORMATS = [ 'markdown' => 'Markdown', 'mrkdwn' => 'Slack mrkdwn', 'text' => 'Plain text' ];
    //Slack's limit of a message's text (it cuts longer ones)
    const MAX_TEXT = 40000;

    //the client of the run
    protected $slack = null;
    //channel names looked up in this run: [ name => id ]
    private $channelIds = null;

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
	    foreach(\models\Credential::choicesFor('slack', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Slack credential',
		'choices' => self::credentialChoices(),
		'help' => 'A Slack credential of the project',
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
	    'help' => 'Slack\'s errors (e.g. not_in_channel, missing_scope), or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, ?array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the Channel setting of operations $ops
    protected static function channelField(array $ops, $help = 'An id (C…), #name, or the channel\'s link', $key = 'channel', $label = 'Channel') {
	return self::field($key, $label, $ops, [ 'required' => true, 'help' => $help, 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '#general' ] ]);
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
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'slack')))) {
	    $this->errors('config', static::$label . ': choose a Slack credential of this project');
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
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'slack');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->slack = $cred->client(max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

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

    //a list read through the cursor, as At most cuts it: [ { items, count, truncated }, summary ]
    protected function listOf($method, array $args, $key, $what, \Closure $map) {
	[ $items, $truncated ] = $this->slack->pages($method, $args, $key, $this->maxItems());
	$items = array_map($map, $items);
	return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' (more left out)' : '') ];
    }

    //a channel's id of setting value $value (an id, #name, a link, an item); a name looked up in the workspace's channels the app can see
    protected function channel($value, $label = 'Channel') {
	$v = \util\vendor\Slack::channelId($value);
	if ($v === '')
	    throw new \RuntimeException("{$label}: empty");
	if (\util\vendor\Slack::isChannelId($v))
	    return $v;
	$name = strtolower(ltrim($v, '#'));
	if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $name))
	    throw new \RuntimeException("{$label}: not a channel id or name: `" . mb_substr($v, 0, 80) . '`');
	if ($this->channelIds === null) {
	    $this->channelIds = [];
	    [ $all ] = $this->slack->pages('conversations.list', [ 'types' => 'public_channel,private_channel', 'exclude_archived' => true ], 'channels', self::MAX_ITEMS);
	    foreach($all as $c)
		$this->channelIds[strtolower((string)($c['name'] ?? ''))] = (string)($c['id'] ?? '');
	}
	if (!isset($this->channelIds[$name]))
	    throw new \RuntimeException("{$label}: no channel #{$name} the app can see (a private one: invite the app first)");
	return $this->channelIds[$name];
    }

    //a person's id of setting value $value: an id (U…, W…), <@U…>, an email (users.lookupByEmail), an item ({ id })
    protected function userId($value, $label = 'User') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['user'] ?? $value['email'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (preg_match('/^<?@?([UW][A-Z0-9]{6,})>?$/', $v, $m))
	    return $m[1];
	if (str_contains($v, '@') && filter_var($v, FILTER_VALIDATE_EMAIL)) {
	    try {
		return (string)($this->slack->call('users.lookupByEmail', [ 'email' => $v ])['user']['id'] ?? '');
	    } catch (\RuntimeException $e) {
		throw new \RuntimeException("{$label}: " . (str_starts_with($e->getMessage(), 'users_not_found') ? "no one in the workspace has the email {$v}" : $e->getMessage()));
	    }
	}
	throw new \RuntimeException("{$label}: a user's id (U…) or email: `" . mb_substr($v, 0, 80) . '`');
    }

    //people of a setting: a list, or separated by commas / new lines; each an id or an email
    protected function userIds($value, $label = 'Users') {
	$ids = [];
	foreach(NodeAppZendesk::names($value, [ 'id', 'email' ]) as $u)
	    $ids[] = $this->userId($u, $label);
	if (!$ids)
	    throw new \RuntimeException("{$label}: none given");
	return array_values(array_unique($ids));
    }

    //where a message goes: a channel, or a person (their direct messages, conversations.open)
    protected function destination($value, $label = 'To') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['email'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (preg_match('/^<?@?([UW][A-Z0-9]{6,})>?$/', $v) || (str_contains($v, '@') && filter_var($v, FILTER_VALIDATE_EMAIL)))
	    return (string)($this->slack->call('conversations.open', [ 'users' => $this->userId($v, $label) ])['channel']['id'] ?? '');
	return $this->channel($v, $label);
    }

    //the message text of setting $key in format $format: [ text (mrkdwn), mrkdwn on ]
    protected function messageText($key = 'message', $format = null) {
	$content = trim(\util\Expression::toText($this->settingOf($key)));
	$format = $format ?? (string)$this->cfg('format', 'markdown');
	$text = match($format) { 'mrkdwn' => $content, 'text' => \util\vendor\Slack::escape($content), default => \util\vendor\Slack::mrkdwn($content) };
	if (mb_strlen($text) > self::MAX_TEXT)
	    throw new \RuntimeException('the message is longer than ' . self::MAX_TEXT . ' characters');
	return [ $text, $format !== 'text' ];
    }

    //a JSON setting (blocks, arguments): null when empty; $list: a JSON list
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

    //the message object of a Slack message (of a channel)
    public static function messageOf($m, $channel = '') {
	$m = is_array($m) ? $m : [];
	return [
	    'ts' => (string)($m['ts'] ?? ''),
	    'channel' => (string)($m['channel'] ?? $channel),
	    'thread_ts' => (string)($m['thread_ts'] ?? ''),
	    'user' => (string)($m['user'] ?? $m['bot_id'] ?? ''),
	    'text' => \util\vendor\Slack::plain((string)($m['text'] ?? '')),
	    'replies' => (int)($m['reply_count'] ?? 0),
	    'reactions' => array_map(fn($r) => [ 'name' => (string)($r['name'] ?? ''), 'count' => (int)($r['count'] ?? 0) ], (array)($m['reactions'] ?? [])),
	    'files' => array_map(fn($f) => (string)($f['name'] ?? $f['id'] ?? ''), (array)($m['files'] ?? [])),
	    'time' => isset($m['ts']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$m['ts']) : '',
	];
    }
}
