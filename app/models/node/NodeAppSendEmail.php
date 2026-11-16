<?php

/*
    Send email - an email made of blocks (models\EmailBlocks,
	edited with the block designer app.emaildesigner.js) sent to the recipients of the step.
	The step fills the {{ }} with the run's variables and renders the email
	    (util\EmailHtml: responsive, inline styles, Outlook-safe, a text version, the images embedded),
	    then queues it: the daemon sends it (util\EmailSend::deliver); the run goes on.
	CLD: 2026-11-16T23:30:00+02:00: Attachments: project files (ids, a list, {{ file }} of Download File / Spreadsheet file), checked here (the project's, MAX_ATTACHMENTS in all);
	    the job keeps their ids, deliver() reads them (a file deleted before is left out)

    From: the app's address (config mail.from), the step's sender name and Reply-To
*/
namespace models\node;

class NodeAppSendEmail extends \models\wf\WfNode {
    public static $type = 'app';
    public static $name = 'send-email';
    public static $label = 'Send email';
    public static $icon = 'bi-envelope-paper';
    //the step picker: in Email
    public static $category = 'email';
    public static $inputs = 1;
    public static $outputs = 1;

    const MAX_RECIPIENTS = 50;
    //the attachments of an email, in all (as the email services' steps: NodeAppEmailService)
    const MAX_ATTACHMENTS = NodeAppEmailService::MAX_ATTACHMENTS;

    public static function configSchema() {
	return [
	    [
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
	    ],
	    [
		'key' => 'fromName',
		'type' => 'text',
		'label' => 'Sender name',
		'default' => (string)\V::app()->config('app.name'),
		'help' => 'Sent from ' . \V::app()->config('mail.from'),
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'replyTo',
		'type' => 'text',
		'label' => 'Reply to',
		'help' => 'Where replies go (empty: nowhere useful)',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    \models\EmailBlocks::schema(),
	    [
		'key' => 'attachments',
		'type' => 'text',
		'label' => 'Attachments',
		'help' => 'Project files: an id, ids separated by commas, or a list ({{ file.id }}, {{ files }}); at most ' . (self::MAX_ATTACHMENTS >> 20) . ' MB in all',
		'ui' => [ 'expression' => true ],
	    ],
	];
    }

    //the email stored normalized; its blocks complete; the expressions in its blocks well formed; the images files of the workflow's project
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
	return $valid;
    }

    //the addresses of an expression's value: a list, or text separated by commas / semicolons / spaces; each must be an e-mail address
    private function addresses($key, array $vars) {
	$value = \util\Expression::render((string)$this->cfg($key, ''), $vars);
	$list = is_array($value) ? array_map([ \util\Expression::class, 'toText' ], array_values($value)) : preg_split('/[\s,;]+/', \util\Expression::toText($value));
	$list = array_values(array_unique(array_filter(array_map('trim', $list), fn($a) => $a !== '')));
	foreach($list as $a) {
	    if (!\util\Validator::rule('email', $a, 'any'))
		throw new \ErrorException("Send email: `{$a}` ({$key}) is not an e-mail address");
	}
	return $list;
    }

    //the files to attach: [ id => name ], the project's own, MAX_ATTACHMENTS in all
    private function attachments(array $vars) {
	$raw = trim((string)$this->cfg('attachments', ''));
	if ($raw === '')
	    return [];
	$out = [];
	$total = 0;
	foreach(\models\File::idsOf(\util\Expression::render($raw, $vars)) as $id) {
	    $file = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \ErrorException('Send email: Attachments: no project file `' . mb_substr($id, 0, 60) . '`');
	    $total += (int)$file->length;
	    if ($total > self::MAX_ATTACHMENTS)
		throw new \ErrorException('Send email: Attachments: larger than ' . (self::MAX_ATTACHMENTS >> 20) . ' MB in all');
	    $out[(string)$file->_id] = (string)$file->filename;
	}
	return $out;
    }

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$to = $this->addresses('to', $vars);
	$cc = $this->addresses('cc', $vars);
	$bcc = $this->addresses('bcc', $vars);
	if (!$to)
	    throw new \ErrorException('Send email: no recipient (To is empty)');
	if (count($to) + count($cc) + count($bcc) > self::MAX_RECIPIENTS)
	    throw new \ErrorException('Send email: more than ' . self::MAX_RECIPIENTS . ' recipients');
	$replyTo = \util\EmailHtml::fillText($this->cfg('replyTo', ''), $vars);
	if ($replyTo !== '' && !\util\Validator::rule('email', $replyTo, 'any'))
	    throw new \ErrorException("Send email: `{$replyTo}` (Reply to) is not an e-mail address");
	$subject = mb_substr(\util\EmailHtml::fillText($this->cfg('subject', ''), $vars), 0, 300);

	$attachments = $this->attachments($vars);
	$r = \util\EmailHtml::render(is_array($this->cfg('email')) ? $this->cfg('email') : [], $vars, $subject);
	$job = \models\Job::submit('\util\EmailSend::deliver', [
	    'aid' => (string)$this->exec->aid,
	    'from_name' => mb_substr(\util\EmailHtml::fillText($this->cfg('fromName', ''), $vars), 0, 100),
	    'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'reply_to' => $replyTo,
	    'subject' => $subject, 'html' => $r['html'], 'text' => $r['text'], 'images' => $r['images'], 'attachments' => array_keys($attachments),
	], [ 'aid' => $this->exec->aid, 'project' => $this->exec->project, 'delete_on_done' => true ]);
	$this->exec->log($this->wfid, 'email "' . mb_substr($subject, 0, 80) . '" queued for ' . implode(', ', array_slice($to, 0, 5)) . (count($to) > 5 ? ', ...' : '')
	    . ($attachments ? ', attached ' . implode(', ', array_slice($attachments, 0, 5)) . (count($attachments) > 5 ? ', ...' : '') : '') . " (job {$job})");
	return [ 0 ];
    }
}
