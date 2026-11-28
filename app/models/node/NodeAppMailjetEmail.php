<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Mailjet Email - email sent through Mailjet (a Mailjet credential, util\vendor\Mailjet). Operations (setting `operation`):
	send       a message (POST /v3.1/send): the message of NodeAppEmailService (text / HTML, designed - its images inline: InlinedAttachments -, or a Mailjet template by its ID
	           with its variables, TemplateLanguage on); attachments base64; the tags as its campaign (CustomCampaign); sandbox: checked, not sent
	messages   the messages sent (GET /v3/REST/message): by recipient, a period, their status; with their subjects
	message    one by its ID (GET /v3/REST/message/{id})
	history    what happened to one (GET /v3/REST/messagehistory/{id}): sent, opened, clicked, bounced, …
	templates  the account's templates (GET /v3/REST/template, OwnerType user)
    A send's result: { messageIds, to, count } (the MessageID of each recipient)
*/

namespace models\node;

class NodeAppMailjetEmail extends NodeAppEmailService {
    public static $name = 'mailjet-email';
    public static $label = 'Mailjet Email';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'email';
    public static $capability = 'mailjet';
    public static $credentialLabel = 'Mailjet credential';

    const OPERATIONS = [
	'send' => 'Send an email',
	'messages' => 'List sent messages',
	'message' => 'Get a message',
	'history' => 'Read a message\'s events',
	'templates' => 'List templates',
    ];
    const WRITES = [ 'send' ];
    const LISTS = [ 'messages', 'history', 'templates' ];
    const SEND_OPS = [ 'send' ];
    const STATUSES = [ '' => 'All', 'sent' => 'Sent', 'opened' => 'Opened', 'clicked' => 'Clicked', 'bounce' => 'Bounced', 'spam' => 'Spam', 'unsub' => 'Unsubscribed',
	'blocked' => 'Blocked', 'hardbounced' => 'Hard bounced', 'softbounced' => 'Soft bounced', 'deferred' => 'Deferred', 'queued' => 'Queued' ];

    protected static function operationFields() {
	return array_merge(self::messageFields('Its ID, a number (Mailjet: Templates)', 'A JSON object of the template\'s variables ({{var:name}} in Mailjet), e.g. {"name": "{{ form.name }}"}'), [
	    self::field('sandbox', 'Sandbox', [ 'send' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Mailjet checks the message, and sends nothing', 'ui' => [ 'advanced' => true ] ]),
	    self::field('recipient', 'Recipient', [ 'messages' ], [ 'help' => 'An address; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'messages' ], [ 'type' => 'select', 'default' => '', 'choices' => self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('sentFrom', 'Sent from', [ 'messages' ], [ 'help' => 'A date (and time)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sentUntil', 'Sent until', [ 'messages' ], [ 'help' => 'A date (and time)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('messageId', 'Message ID', [ 'message', 'history' ], [ 'required' => true, 'help' => 'E.g. {{ email.messageIds[0] }}', 'ui' => [ 'expression' => 'text' ] ]),
	]);
    }

    protected static function resultOf($operation) {
	return $operation === 'send' ? self::sentDef() : null;
    }

    protected function timeOf(\Closure $text, $key, $label) {
	$v = $text($key);
	if ($v === '')
	    return null;
	$ts = strtotime($v);
	if ($ts === false)
	    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($v, 0, 32) . '`');
	return $ts;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$M = $this->client;
	switch($operation) {
	    case 'send':
		$m = $this->message($text, $value, true);
		$who = fn($a) => array_filter([ 'Email' => $a['email'], 'Name' => $a['name'] ], fn($v) => $v !== '');
		$msg = [ 'From' => $who($m['from']), 'To' => array_map($who, $m['to']) ];
		if ($m['cc'])
		    $msg['Cc'] = array_map($who, $m['cc']);
		if ($m['bcc'])
		    $msg['Bcc'] = array_map($who, $m['bcc']);
		if ($m['replyTo'])
		    $msg['ReplyTo'] = $who($m['replyTo']);
		if ($m['subject'] !== '')
		    $msg['Subject'] = $m['subject'];
		if ($m['template'] !== null) {
		    if (!preg_match('/^\d{1,12}$/', $m['template']))
			throw new \RuntimeException('Template: Mailjet\'s template ID, a number');
		    $msg['TemplateID'] = (int)$m['template'];
		    $msg['TemplateLanguage'] = true;
		    if ($m['params'])
			$msg['Variables'] = $m['params'];
		} else {
		    if (trim($m['text']) !== '')
			$msg['TextPart'] = $m['text'];
		    if (trim($m['html']) !== '')
			$msg['HTMLPart'] = $m['html'];
		}
		if ($m['attachments'])
		    $msg['Attachments'] = array_map(fn($a) => [ 'ContentType' => $a['type'], 'Filename' => $a['name'], 'Base64Content' => base64_encode($a['content']) ], $m['attachments']);
		if ($m['images'])
		    $msg['InlinedAttachments'] = array_map(fn($cid, $f) => [ 'ContentType' => $f->imageMimeType() ?: 'application/octet-stream',
			'Filename' => preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f->filename), 'ContentID' => $cid, 'Base64Content' => base64_encode($f->getContents()) ],
			array_keys($m['images']), array_values($m['images']));
		if ($m['tags'])
		    $msg['CustomCampaign'] = implode(', ', $m['tags']);
		$body = [ 'Messages' => [ $msg ] ];
		if ($this->cfg('sandbox', false))
		    $body['SandboxMode'] = true;
		$d = $M->request('POST', '/v3.1/send', [], $body);
		$r = (array)($d['Messages'][0] ?? []);
		if (($r['Status'] ?? '') !== 'success')
		    throw new \RuntimeException('Mailjet did not send it' . (($e = $r['Errors'][0]['ErrorMessage'] ?? '') !== '' ? ": {$e}" : ''));
		$ids = [];
		foreach([ 'To', 'Cc', 'Bcc' ] as $k)
		    foreach((array)($r[$k] ?? []) as $x)
			$ids[] = (string)($x['MessageID'] ?? '');
		return self::sentResult($m, $ids);

	    case 'messages':
		$status = (string)$this->cfg('status', '');
		$q = array_filter([ 'ContactAlt' => $text('recipient'), 'FromTS' => ($t = $this->timeOf($text, 'sentFrom', 'Sent from')) ? gmdate('Y-m-d\TH:i:s', $t) : null,
		    'ToTS' => ($t = $this->timeOf($text, 'sentUntil', 'Sent until')) ? gmdate('Y-m-d\TH:i:s', $t) : null, 'MessageStatus' => isset(self::STATUSES[$status]) ? $status : '' ],
		    fn($v) => $v !== null && $v !== '') + [ 'ShowSubject' => 'true', 'ShowContactAlt' => 'true', 'Sort' => 'ArrivedAt DESC' ];
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($M, $q) {
		    $d = $M->request('GET', '/v3/REST/message', $q + [ 'Limit' => $limit, 'Offset' => $offset ]);
		    return [ (array)($d['Data'] ?? []), isset($d['Total']) ? (int)$d['Total'] : null ];
		}, 1000);
		return $this->listResult($items, 'message', $total, $more);

	    case 'message':
		$d = $M->request('GET', '/v3/REST/message/' . \util\EmailApi::segment($text('messageId'), 'Message ID'), [ 'ShowSubject' => 'true', 'ShowContactAlt' => 'true' ]);
		$msg = (array)($d['Data'][0] ?? []);
		if (!$msg)
		    throw new \RuntimeException('no message ' . $text('messageId'));
		return [ $msg, 'message ' . $text('messageId') . ': ' . ($msg['Status'] ?? '?') ];

	    case 'history':
		$d = $M->request('GET', '/v3/REST/messagehistory/' . \util\EmailApi::segment($text('messageId'), 'Message ID'));
		return $this->listResult((array)($d['Data'] ?? []), 'event');

	    case 'templates':
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($M) {
		    $d = $M->request('GET', '/v3/REST/template', [ 'OwnerType' => 'user', 'Limit' => $limit, 'Offset' => $offset ]);
		    return [ (array)($d['Data'] ?? []), isset($d['Total']) ? (int)$d['Total'] : null ];
		}, 1000);
		return $this->listResult($items, 'template', $total, $more);
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
