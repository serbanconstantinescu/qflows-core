<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: the base of the email services' steps (Brevo, Mailjet, Amazon SES; not registered itself), as the integration bases (NodeAppInfobip, RegionalRO's
    NodeAppRo):
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output"); in the picker's group Email
	- the settings: the credential (static::$capability, labelled $credentialLabel: its client() in $client), the operation (static::OPERATIONS), the operation's own settings
	    (operationFields(): each shown for its operations), At most (LISTS), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (the service's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
    The message of a send operation (SEND_OPS), the same settings in every service (messageFields(), message()): to, cc, bcc (addresses, "Name <address>", or lists of them / of
	{ email, name }), from (empty: the credential's), reply to, subject, the content (CONTENTS): text and / or HTML, a message designed with blocks (the Send email step's designer,
	models\EmailBlocks: rendered by util\EmailHtml, its images inline), or a template of the service with its parameters (a JSON object); project files attached; tags.
	message() gives it normalized: { from: { email, name }, to / cc / bcc: [ { email, name } ], replyTo: { email, name } | null, subject, text, html, images: [ cid => File ],
	attachments: [ { name, type, content } ], template, params, tags }; each service turns it into its API's request.
    Helpers: listResult() (a list as At most cuts it), jsonOf() (a JSON setting), apiFields() / apiCall() (Call the API: method, path, query, body), addressesOf() (recipients)
*/

namespace models\node;

abstract class NodeAppEmailService extends \models\wf\WfNode {
    use AgentToolTrait;

    public static $type = 'app';
    public static $icon = 'bi-send';
    public static $category = 'email';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'result';
    //the credential's capability, its label
    public static $capability = '';
    public static $credentialLabel = '';

    const OPERATIONS = [];
    const LISTS = [];
    //the operations sending a message (message(): its settings, the designer's checks)
    const SEND_OPS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;
    const MAX_RECIPIENTS = 50;
    //the attachments of a message, in all (the services take 10 to 40 MB; base64 adds a third)
    const MAX_ATTACHMENTS = 10 * 1024 * 1024;
    //the content of a message
    const CONTENTS = [ 'html' => 'Text / HTML', 'designer' => 'Designed (blocks)', 'template' => 'A template of the service' ];
    const API_METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE' ];

    //the run: the credential, its client
    protected $cred = null;
    protected $client = null;

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
	    foreach(\models\Credential::choicesFor(static::$capability, \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => static::$credentialLabel,
		'choices' => static::credentialChoices(),
		'help' => 'A credential of the project',
		'required' => true,
	    ],
	];
	if (count(static::OPERATIONS) > 1)
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
	    'help' => static::LISTS ? 'A list: { items, count, total, truncated }; otherwise what the operation gives' : 'What the operation gives',
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
	    'help' => 'The service\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, ?array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the settings of a message (SEND_OPS); $template: the help of Template (what the service takes: an id, a name)
    protected static function messageFields($template = 'Its ID', $params = 'A JSON object of the template\'s variables, e.g. {"name": "{{ form.name }}"}') {
	$ops = static::SEND_OPS;
	//a content's own settings: hidden for the other contents (shown for the send operations)
	$content = fn(array $kinds) => [ 'key' => 'content', 'operator' => 'in', 'value' => array_values(array_diff(array_keys(self::CONTENTS), $kinds)) ];
	return [
	    self::field('to', 'To', $ops, [ 'required' => true, 'help' => 'Addresses (Name <address>) separated by commas, or a list', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('from', 'From', $ops, [ 'help' => 'Name <address> of a sender the service allows; empty: the credential\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('cc', 'Cc', $ops, [ 'ui' => [ 'expression' => true, 'width' => 4 ] ]),
	    self::field('bcc', 'Bcc', $ops, [ 'ui' => [ 'expression' => true, 'width' => 4 ] ]),
	    self::field('replyTo', 'Reply to', $ops, [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('content', 'Content', $ops, [ 'type' => 'select', 'required' => true, 'default' => 'html', 'choices' => self::CONTENTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('subject', 'Subject', $ops, [ 'help' => 'Required, except with a template that has its own', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('text', 'Text', $ops, [ 'type' => 'textarea', 'help' => 'Plain text; a text or an HTML body is required', 'ui' => [ 'rows' => 3, 'expression' => 'text', 'hideIf' => $content([ 'html' ]) ] ]),
	    self::field('html', 'HTML', $ops, [ 'type' => 'textarea', 'ui' => [ 'rows' => 4, 'expression' => 'text', 'code' => true, 'hideIf' => $content([ 'html' ]) ] ]),
	    \models\EmailBlocks::schema([ 'required' => false, 'ops' => $ops, 'ui' => [ 'type' => 'emaildesigner', 'hideIf' => $content([ 'designer' ]) ] ]),
	    self::field('template', 'Template', $ops, [ 'help' => $template, 'ui' => [ 'expression' => 'text', 'width' => 6, 'hideIf' => $content([ 'template' ]) ] ]),
	    self::field('templateVars', 'Template variables', $ops, [ 'type' => 'textarea', 'help' => $params,
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ], 'hideIf' => $content([ 'template' ]) ] ]),
	    self::field('attachments', 'Attachments', $ops, [ 'help' => 'Project files: an id, ids separated by commas, or a list ({{ file.id }}, {{ files }})', 'ui' => [ 'expression' => true ] ]),
	    self::field('tags', 'Tags', $ops, [ 'help' => 'Names separated by commas, to find the message in the service\'s reports', 'ui' => [ 'expression' => 'text', 'advanced' => true ] ]),
	];
    }

    protected static function operationOf($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	return isset(static::OPERATIONS[$op]) ? $op : static::$defaultOperation;
    }

    //the result variable of an operation: [ type, info, fields ] (none: { items, count, total, truncated } for LISTS, else any)
    protected static function resultOf($operation) {
	return null;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = static::operationOf($config);
	if ($def = static::resultOf($op))
	    return [ $name => $def ];
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'all the matching items (when the service says)' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'the service\'s answer (null on error)' ] ];
    }

    //the result of a send: { messageIds, to, count } (sentResult())
    protected static function sentDef() {
	return [ 'type' => 'object', 'info' => '{ messageIds, to, count }', 'fields' => [
	    'messageIds' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the service\'s ids of the message (one per recipient for some)' ],
	    'to' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the recipients: To, Cc and Bcc' ],
	    'count' => [ 'type' => 'number', 'info' => 'the recipients' ],
	] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, static::$capability)))) {
	    $this->errors('config', static::$label . ': choose a credential of this project (' . static::$credentialLabel . ')');
	    $valid = false;
	}
	//a designed message: stored normalized, checked as the Send email step's
	if (in_array(static::operationOf($this->config), static::SEND_OPS, true) && (string)$this->cfg('content', 'html') === 'designer') {
	    $config = is_array($this->config) ? $this->config : [];
	    $config['email'] = \models\EmailBlocks::normalize($config['email'] ?? []);
	    $this->config = $config;
	    foreach(\models\EmailBlocks::problems($config['email'], $this->workflow) as $m) {
		$this->errors('config', "Message: {$m}");
		$valid = false;
	    }
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$this->settingVars = $this->varsForSettings();
	$operation = static::operationOf($this->config);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$this->cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), static::$capability);
	if (empty($this->cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->client = $this->cred->client(max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT))));

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

    //a list as At most cuts it: [ { items, count, total, truncated }, summary ]; $total: all the matching items, when the service says (else those given); $more: the service has more
    protected function listResult(array $items, $what, $total = null, $more = false) {
	$all = count($items);
	$items = array_slice(array_values($items), 0, $this->maxItems());
	$total = $total === null ? $all : (int)$total;
	$truncated = $more || $all > count($items) || $total > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $truncated ],
	    \util\Format::plural(count($items), $what) . ($truncated ? ' of ' . ($total > count($items) ? $total : 'more') : '') ];
    }

    //a list read in pages by offset up to At most: $fetch($offset, $limit) => [ items, total (null: not said) ]; $page: the service's largest page. [ items, total, more ]
    protected function offsetPages(\Closure $fetch, $page) {
	$max = $this->maxItems();
	$items = [];
	$total = null;
	$offset = 0;
	while (count($items) < $max) {
	    $limit = min($page, $max - count($items));
	    [ $got, $t ] = $fetch($offset, $limit);
	    $got = array_values((array)$got);
	    $total = $t ?? $total;
	    $items = array_merge($items, $got);
	    $offset += count($got);
	    if (count($got) < $limit || ($total !== null && $offset >= $total))
		return [ $items, $total ?? count($items), false ];
	}
	return [ $items, $total ?? count($items), $total === null || $total > count($items) ];
    }

    //a list read in pages by token up to At most: $fetch($token, $limit) => [ items, next token (null / '': the last page) ]; $page: the service's largest page. [ items, more ]
    protected function tokenPages(\Closure $fetch, $page) {
	$max = $this->maxItems();
	$items = [];
	$token = null;
	do {
	    [ $got, $token ] = $fetch($token, min($page, $max - count($items)));
	    $items = array_merge($items, array_values((array)$got));
	} while ($token !== null && $token !== '' && count($items) < $max);
	return [ array_slice($items, 0, $max), $token !== null && $token !== '' ];
    }

    //a JSON setting: an object ($list false) or a list, given as one ({{ }}, a tool's argument) or as its text; [] when empty
    protected function jsonOf(\Closure $value, $key, $label, $list = false) {
	$v = $value($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return [];
	    $d = json_decode($v, true);
	    if (!is_array($d))
		throw new \RuntimeException("{$label}: not JSON (" . json_last_error_msg() . ')');
	    $v = $d;
	}
	if ($v === null || $v === '')
	    return [];
	if (!is_array($v) || ($v && array_is_list($v) !== $list))
	    throw new \RuntimeException("{$label}: " . ($list ? 'a list' : 'an object'));
	return $v;
    }

    //a whole number of setting $key, from $min to $max; empty: $default
    protected function intOf(\Closure $text, $key, $label, $min, $max, $default = null) {
	$raw = $text($key);
	if ($raw === '')
	    return $default;
	if (!preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max)
	    throw new \RuntimeException("{$label}: a whole number from {$min} to {$max}");
	return (int)$raw;
    }

    // ------------------------------------------------------------------------------------
    //the message

    //recipients of a setting's value: "Name <a@x>, b@x", a list of them, or of { email, name }: [ { email, name } ]
    public static function addressesOf($value, $label) {
	$parts = is_array($value) ? (array_is_list($value) ? $value : [ $value ]) : [ \util\Expression::toText($value) ];
	$out = [];
	foreach($parts as $p) {
	    if (is_array($p)) {
		$email = trim((string)($p['email'] ?? $p['address'] ?? ''));
		$found = $email !== '' ? [ [ 'email' => $email, 'name' => trim((string)($p['name'] ?? '')) ] ] : [];
	    } else {
		$t = trim(\util\Expression::toText($p));
		$found = $t === '' ? [] : \util\MailParser::parseAddresses(str_replace(';', ',', $t));
		if ($t !== '' && !$found)
		    throw new \RuntimeException("{$label}: not an email address: `" . mb_substr($t, 0, 100) . '`');
	    }
	    foreach($found as $a) {
		$email = trim((string)$a['email']);
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
		    throw new \RuntimeException("{$label}: not an email address: `" . mb_substr($email, 0, 100) . '`');
		$out[strtolower($email)] = [ 'email' => $email, 'name' => mb_substr(\util\EmailSend::headerText($a['name'] ?? ''), 0, 100) ];
	    }
	}
	return array_values($out);
    }

    //the message of a send operation, normalized (see the header); $inline: the service takes inline images (a designed message's), else a designed message with images is refused
    protected function message(\Closure $text, \Closure $value, $inline = true) {
	$m = [
	    'to' => self::addressesOf($value('to'), 'To'),
	    'cc' => self::addressesOf($value('cc'), 'Cc'),
	    'bcc' => self::addressesOf($value('bcc'), 'Bcc'),
	    'subject' => mb_substr(\util\EmailSend::headerText($text('subject')), 0, 998),
	    'text' => '', 'html' => '', 'images' => [], 'attachments' => [], 'template' => null, 'params' => [],
	    'tags' => array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $text('tags'))), fn($t) => $t !== ''))), 0, 10),
	];
	if (!$m['to'])
	    throw new \RuntimeException('To: no address');
	if (count($m['to']) + count($m['cc']) + count($m['bcc']) > self::MAX_RECIPIENTS)
	    throw new \RuntimeException('more than ' . self::MAX_RECIPIENTS . ' recipients');
	$from = self::addressesOf($text('from') !== '' ? $text('from') : trim((string)($this->cred->fromAddress ?? '')), 'From');
	if (!$from)
	    throw new \RuntimeException('From: no sender (the step\'s or the credential\'s)');
	$m['from'] = $from[0];
	$reply = self::addressesOf($text('replyTo'), 'Reply to');
	$m['replyTo'] = $reply[0] ?? null;

	$content = isset(self::CONTENTS[(string)$this->cfg('content', 'html')]) ? (string)$this->cfg('content', 'html') : 'html';
	if ($content === 'template') {
	    $m['template'] = $text('template');
	    if ($m['template'] === '')
		throw new \RuntimeException('Template: none given');
	    $m['params'] = $this->jsonOf($value, 'templateVars', 'Template variables');
	} elseif ($content === 'designer') {
	    if ($m['subject'] === '')
		throw new \RuntimeException('Subject: empty');
	    $email = \models\EmailBlocks::normalize(is_array($this->cfg('email')) ? $this->cfg('email') : []);
	    if (!$email['blocks'])
		throw new \RuntimeException('Message: no block');
	    $r = \util\EmailHtml::render($email, $this->exec->expressionVariables(), $m['subject']);
	    $m['html'] = $r['html'];
	    $m['text'] = $r['text'];
	    foreach($r['images'] as $cid => $fid) {
		$file = \V::app()->om->findOne('File', [ '_id' => (string)$fid, 'aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]);
		if (!$file)
		    throw new \RuntimeException('Message: an image is no longer a file of the project');
		$m['images'][$cid] = $file;
	    }
	    if ($m['images'] && !$inline)
		throw new \RuntimeException('Message: ' . static::$label . ' cannot send images inside the message: use an image by its address in an HTML block, or attach it');
	} else {
	    if ($m['subject'] === '')
		throw new \RuntimeException('Subject: empty');
	    $m['text'] = \util\Expression::toText($value('text'));
	    $m['html'] = \util\Expression::toText($value('html'));
	    if (trim($m['text']) === '' && trim($m['html']) === '')
		throw new \RuntimeException('Text / HTML: give one');
	}
	$m['attachments'] = $this->attachmentsOf($value('attachments'));
	return $m;
    }

    //project files to attach (ids, a list of ids or of { id, ... }): [ { name, type, content } ], MAX_ATTACHMENTS in all
    protected function attachmentsOf($v) {
	$parts = is_array($v) ? (array_is_list($v) ? $v : [ $v ]) : preg_split('/[\s,;]+/', \util\Expression::toText($v));
	$out = [];
	$total = 0;
	foreach($parts as $p) {
	    $id = is_array($p) ? (string)($p['id'] ?? '') : trim((string)$p);
	    if ($id === '')
		continue;
	    $file = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('Attachments: no project file `' . mb_substr($id, 0, 60) . '`');
	    $total += (int)$file->length;
	    if ($total > self::MAX_ATTACHMENTS)
		throw new \RuntimeException('Attachments: larger than ' . (self::MAX_ATTACHMENTS >> 20) . ' MB in all');
	    $ext = strtolower(pathinfo((string)$file->filename, PATHINFO_EXTENSION));
	    $out[] = [ 'name' => (string)$file->filename, 'type' => \models\File::CONTENT_TYPES[$ext] ?? 'application/octet-stream', 'content' => (string)$file->getContents() ];
	}
	return $out;
    }

    //the result of a send: { messageIds, to, count } and its summary ("sent to a@x, b@x (2 attachments)")
    protected static function sentResult(array $m, array $ids) {
	$to = array_column(array_merge($m['to'], $m['cc'], $m['bcc']), 'email');
	$ids = array_values(array_filter(array_map('strval', $ids), fn($i) => $i !== ''));
	return [ [ 'messageIds' => $ids, 'to' => $to, 'count' => count($to) ],
	    'sent to ' . implode(', ', array_slice($to, 0, 3)) . (count($to) > 3 ? ', …' : '') . ($m['attachments'] ? ' (' . \util\Format::plural(count($m['attachments']), 'attachment') . ')' : '')
	    . ($ids ? ': ' . implode(', ', array_slice($ids, 0, 2)) . (count($ids) > 2 ? ', …' : '') : '') ];
    }

    // ------------------------------------------------------------------------------------
    //Call the API

    //the settings of a request of the service's API (operation `request`)
    protected static function apiFields($pathHelp, $placeholder = '/…') {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::API_METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => $pathHelp, 'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => $placeholder ] ]),
	    self::field('query', 'Query', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object of the query\'s parameters; empty: none',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('body', 'Body', [ 'request' ], [ 'type' => 'textarea', 'help' => 'JSON (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    //the request of Call the API: [ method, path (no address, no ..), query, body (null: none) ]
    protected function apiCall(\Closure $text, \Closure $value) {
	$method = isset(self::API_METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = '/' . ltrim($text('path'), '/');
	if ($path === '/' || preg_match('~^/?[a-z]+:|\.\.|[\s\\\\]~i', $path))
	    throw new \RuntimeException('Path: a path of the API (no address, no ..)');
	$query = $this->jsonOf($value, 'query', 'Query');
	array_walk_recursive($query, function(&$v) {
	    if (is_bool($v))
		$v = $v ? 'true' : 'false';
	});
	$body = $value('body');
	if (is_string($body)) {
	    $raw = $body;
	    $body = trim($raw) === '' ? null : json_decode($raw, true);
	    if ($body === null && trim($raw) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	return [ $method, $path, $query, $body === '' ? null : $body ];
    }
}
