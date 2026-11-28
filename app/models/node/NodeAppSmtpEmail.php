<?php

/*
    Send email (SMTP) - CLD: 2026-11-28T18:00:00+02:00: an email made of blocks (models\EmailBlocks, edited with the block designer app.emaildesigner.js) sent during the step
    through an SMTP server credential of the project (models\credential\CredentialSmtp: host, port, STARTTLS / SSL/TLS / none, an optional username and password).
	The step fills the {{ }} with the run's variables and renders the email (util\EmailHtml: responsive, inline styles, Outlook-safe, a text version, the images
	embedded), attaches project files (ids, a list, {{ file }} of Download File / Spreadsheet file: the project's, MAX_ATTACHMENTS in all), then sends it
	(util\EmailSend::messageOf, transmit). From: the step's address, else the credential's; the sender name and Reply-To of the step.
	The variable (default `email`): { messageId, from, accepted: [ address ], rejected: [ { address, reason } ], queueId } (queueId: the server's, when it says it).
	On error (no connection, TLS, sign-in, the sender or every recipient refused, an address that is not one): fail the step, or continue on the `error` output.
    The base of Send email (NodeAppSendEmail: $ownServer false): the same message, sent from the app's address through the installation's server (app.env), queued.
*/
namespace models\node;

class NodeAppSmtpEmail extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'smtp-email';
    public static $label = 'Send email (SMTP)';
    public static $icon = 'bi-envelope-at';
    //the step picker: an advanced step, in Email
    public static $audience = 'advanced';
    public static $category = 'email';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];
    //the server: an SMTP server credential and its settings (false: the installation's, NodeAppSendEmail)
    protected static $ownServer = true;

    const MAX_RECIPIENTS = 50;
    //the attachments of an email, in all (as the email services' steps: NodeAppEmailService)
    const MAX_ATTACHMENTS = NodeAppEmailService::MAX_ATTACHMENTS;
    const MAX_TIMEOUT = 300;

    public static function configSchema() {
	$own = static::$ownServer;
	$fields = [];
	if ($own)
	    $fields[] = [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'SMTP server',
		'required' => true,
		'choices' => array_column(static::credentialChoices(), 'label', 'value'),
		'help' => 'An SMTP server credential of the project (menu: Credentials)',
	    ];
	array_push($fields, [
		'key' => 'to',
		'type' => 'text',
		'label' => 'To',
		'required' => true,
		'help' => 'Addresses separated by commas, or an expression giving one or a list, e.g. {{ form.email }}',
		'ui' => [ 'expression' => true ],
	    ],
	    [
		'key' => 'cc',
		'type' => 'text',
		'label' => 'Cc',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'bcc',
		'type' => 'text',
		'label' => 'Bcc',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'subject',
		'type' => 'text',
		'label' => 'Subject',
		'required' => true,
		'ui' => [ 'expression' => true ],
	    ]);
	if ($own)
	    $fields[] = [
		'key' => 'from',
		'type' => 'text',
		'label' => 'From address',
		'help' => 'Empty: the credential\'s',
		'ui' => [ 'expression' => true, 'width' => 4 ],
	    ];
	array_push($fields, [
		'key' => 'fromName',
		'type' => 'text',
		'label' => 'Sender name',
		'default' => (string)\V::app()->config('app.name'),
		'ui' => [ 'expression' => true, 'width' => $own ? 4 : 6 ],
	    ] + ($own ? [] : [ 'help' => 'Sent from ' . \V::app()->config('mail.from') ]),
	    [
		'key' => 'replyTo',
		'type' => 'text',
		'label' => 'Reply to',
		'help' => $own ? 'Where replies go (empty: the From address)' : 'Where replies go (empty: nowhere useful)',
		'ui' => [ 'expression' => true, 'width' => $own ? 4 : 6 ],
	    ],
	    \models\EmailBlocks::schema(),
	    [
		'key' => 'attachments',
		'type' => 'text',
		'label' => 'Attachments',
		'help' => 'Project files: an id, ids separated by commas, or a list ({{ file.id }}, {{ files }}); at most ' . (self::MAX_ATTACHMENTS >> 20) . ' MB in all',
		'ui' => [ 'expression' => true ],
	    ]);
	if ($own)
	    array_push($fields, [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => 60,
		'validate' => [ [ 'inRange', 'lower' => 5, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 5 to ' . self::MAX_TIMEOUT ] ],
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'email',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write', 'width' => 4 ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => NodeAppHttp::ON_ERROR,
		'help' => 'No connection, sign-in refused, the sender or every recipient refused',
		'ui' => [ 'width' => 4 ],
	    ]);
	return $fields;
    }

    //the SMTP server credentials of the current project, for the designer
    protected static function credentialChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor('smtp', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    public static function outputVariables($config) {
	if (!static::$ownServer)
	    return [];
	$name = is_array($config) ? (string)($config['variable'] ?? 'email') : 'email';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the email sent: { messageId, from, accepted, rejected, queueId }', 'fields' => [
	    'messageId' => [ 'type' => 'string', 'info' => 'its Message-ID, without < >' ], 'from' => [ 'type' => 'string' ],
	    'accepted' => [ 'type' => 'array', 'info' => 'the addresses the server took' ],
	    'rejected' => [ 'type' => 'array', 'info' => 'the addresses it refused: { address, reason }', 'items' => [ 'type' => 'object', 'fields' => [ 'address' => [ 'type' => 'string' ], 'reason' => [ 'type' => 'string' ] ] ] ],
	    'queueId' => [ 'type' => 'string', 'info' => 'the server\'s id of the message, or null' ] ] ] ] : [];
    }

    //the email stored normalized; its blocks complete; the expressions in its blocks well formed; the images files of the workflow's project; an SMTP server of the project
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$config['email'] = \models\EmailBlocks::normalize($config['email'] ?? []);
	$this->config = $config;
	//CLD: 2026-11-15T16:00:00+02:00: the checks of the message (blocks, expressions, images) in models\EmailBlocks::problems, shared with the email services' send steps
	foreach(\models\EmailBlocks::problems($config['email'], $this->workflow) as $m) {
	    $this->errors('config', "Message: {$m}");
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if (static::$ownServer && $cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'smtp')))) {
	    $this->errors('config', 'SMTP server: choose an SMTP server credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    //the addresses of an expression's value: a list, or text separated by commas / semicolons / spaces; each must be an e-mail address (else a \RuntimeException)
    protected function addresses($key, array $vars) {
	$value = \util\Expression::render((string)$this->cfg($key, ''), $vars);
	$list = is_array($value) ? array_map([ \util\Expression::class, 'toText' ], array_values($value)) : preg_split('/[\s,;]+/', \util\Expression::toText($value));
	$list = array_values(array_unique(array_filter(array_map('trim', $list), fn($a) => $a !== '')));
	foreach($list as $a) {
	    if (!\util\Validator::rule('email', $a, 'any'))
		throw new \RuntimeException("`{$a}` ({$key}) is not an e-mail address");
	}
	return $list;
    }

    //the files to attach: [ id => name ], the project's own, MAX_ATTACHMENTS in all
    protected function attachments(array $vars) {
	$raw = trim((string)$this->cfg('attachments', ''));
	if ($raw === '')
	    return [];
	$out = [];
	$total = 0;
	foreach(\models\File::idsOf(\util\Expression::render($raw, $vars)) as $id) {
	    $file = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('Attachments: no project file `' . mb_substr($id, 0, 60) . '`');
	    $total += (int)$file->length;
	    if ($total > self::MAX_ATTACHMENTS)
		throw new \RuntimeException('Attachments: larger than ' . (self::MAX_ATTACHMENTS >> 20) . ' MB in all');
	    $out[(string)$file->_id] = (string)$file->filename;
	}
	return $out;
    }

    //the email of the step (util\EmailSend::messageOf's data: aid, from_name, to, cc, bcc, reply_to, subject, html, text, images, attachments) and the attachments'
    //names [ id => name ]; \RuntimeException for an address that is not one, no recipient, too many
    protected function email(array $vars) {
	$to = $this->addresses('to', $vars);
	$cc = $this->addresses('cc', $vars);
	$bcc = $this->addresses('bcc', $vars);
	if (!$to)
	    throw new \RuntimeException('no recipient (To is empty)');
	if (count($to) + count($cc) + count($bcc) > self::MAX_RECIPIENTS)
	    throw new \RuntimeException('more than ' . self::MAX_RECIPIENTS . ' recipients');
	$replyTo = \util\EmailHtml::fillText($this->cfg('replyTo', ''), $vars);
	if ($replyTo !== '' && !\util\Validator::rule('email', $replyTo, 'any'))
	    throw new \RuntimeException("`{$replyTo}` (Reply to) is not an e-mail address");
	$subject = mb_substr(\util\EmailHtml::fillText($this->cfg('subject', ''), $vars), 0, 300);
	$attachments = $this->attachments($vars);
	$r = \util\EmailHtml::render(is_array($this->cfg('email')) ? $this->cfg('email') : [], $vars, $subject);
	return [ [
	    'aid' => (string)$this->exec->aid,
	    'from_name' => mb_substr(\util\EmailHtml::fillText($this->cfg('fromName', ''), $vars), 0, 100),
	    'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'reply_to' => $replyTo,
	    'subject' => $subject, 'html' => $r['html'], 'text' => $r['text'], 'images' => $r['images'], 'attachments' => array_keys($attachments),
	], $attachments ];
    }

    //the log's "for <to>, attached <names>"
    protected static function logOf(array $email, array $attachments) {
	return 'for ' . implode(', ', array_slice($email['to'], 0, 5)) . (count($email['to']) > 5 ? ', ...' : '')
	    . ($attachments ? ', attached ' . implode(', ', array_slice($attachments, 0, 5)) . (count($attachments) > 5 ? ', ...' : '') : '');
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$name = (string)$this->cfg('variable', 'email');
	try {
	    $cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'smtp');
	    if (empty($cred))
		throw new \RuntimeException('SMTP server: choose an SMTP server credential of this project');
	    [ $email, $attachments ] = $this->email($vars);
	    $from = trim(\util\EmailHtml::fillText($this->cfg('from', ''), $vars)) ?: trim((string)$cred->fromAddress);
	    if ($from === '')
		throw new \RuntimeException('From address: none (the step\'s or the SMTP server credential\'s)');
	    if (!\util\Validator::rule('email', $from, 'any'))
		throw new \RuntimeException("`{$from}` (From address) is not an e-mail address");
	    //its own Message-ID, given back
	    $messageId = bin2hex(random_bytes(16)) . '@' . (substr(strrchr($from, '@'), 1) ?: 'localhost');
	    [ $headers, $body ] = \util\EmailSend::messageOf($email + [ 'message_id' => $messageId ], $from);
	    $timeout = max(5, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60)));
	    $r = \util\EmailSend::transmit($cred->server($this->exec->aid), [ 'sender' => $from, 'headers' => $headers, 'body' => $body ] + \util\EmailSend::envelopeOf($email),
		[ 'timeout' => $timeout ]);
	} catch (\RuntimeException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, 'smtp-email: ' . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ': ' . $e->getMessage());
	}
	$result = [ 'messageId' => $messageId, 'from' => $from, 'accepted' => $r['accepted'],
	    'rejected' => array_map(fn($a, $why) => [ 'address' => $a, 'reason' => $why ], array_keys($r['rejected']), array_values($r['rejected'])), 'queueId' => $r['id'] ];
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$this->exec->log($this->wfid, 'smtp-email: "' . mb_substr($email['subject'], 0, 80) . '" sent through ' . $cred->host . ' ' . self::logOf($email, $attachments)
	    . ($r['rejected'] ? '; refused: ' . implode(', ', array_keys($r['rejected'])) : ''));
	return [ 0 ];
    }
}
