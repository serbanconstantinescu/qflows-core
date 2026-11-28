<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Brevo Email - transactional email sent through Brevo (formerly Sendinblue; a Brevo credential, util\vendor\Brevo). Operations (setting `operation`):
	send       a message (POST /smtp/email): the message of NodeAppEmailService (text / HTML, designed - its images are refused: Brevo's API has no inline images -, or a Brevo
	           template by its ID with its params); attachments base64, tags
	sent       the transactional emails sent (GET /smtp/emails): by recipient, template or message ID (Brevo asks for one of them), a period
	events     what happened to them (GET /smtp/statistics/events): delivered, opened, clicked, bounced, …; by recipient, event, message ID, template, tags, a period
	report     the totals of a period (GET /smtp/statistics/aggregatedReport): requests, delivered, opens, clicks, bounces, …; by tag
	templates  the transactional templates (GET /smtp/templates): active only, or all
    A send's result: { messageIds, to, count }
*/

namespace models\node;

class NodeAppBrevoEmail extends NodeAppEmailService {
    public static $name = 'brevo-email';
    public static $label = 'Brevo Email';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'email';
    public static $capability = 'brevo';
    public static $credentialLabel = 'Brevo credential';

    const OPERATIONS = [
	'send' => 'Send an email',
	'sent' => 'List sent emails',
	'events' => 'List email events',
	'report' => 'Read the statistics of a period',
	'templates' => 'List templates',
    ];
    const WRITES = [ 'send' ];
    const LISTS = [ 'sent', 'events', 'templates' ];
    const SEND_OPS = [ 'send' ];
    const EVENTS = [ '' => 'All', 'requests' => 'Sent (requests)', 'delivered' => 'Delivered', 'opened' => 'Opened', 'clicks' => 'Clicked', 'bounces' => 'Bounced',
	'hardBounces' => 'Hard bounces', 'softBounces' => 'Soft bounces', 'spam' => 'Spam reports', 'invalid' => 'Invalid', 'deferred' => 'Deferred', 'blocked' => 'Blocked',
	'unsubscribed' => 'Unsubscribed', 'error' => 'Errors', 'loadedByProxy' => 'Loaded by a proxy' ];

    protected static function operationFields() {
	$period = [ 'sent', 'events', 'report' ];
	return array_merge(self::messageFields('Its ID, a number (Brevo: Transactional > Templates)', 'A JSON object of the template\'s params ({{ params.name }} in Brevo), e.g. {"name": "{{ form.name }}"}'), [
	    self::field('recipient', 'Recipient', [ 'sent', 'events' ], [ 'help' => 'An address (sent: this, a template or a message ID is required)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('messageId', 'Message ID', [ 'sent', 'events' ], [ 'help' => 'E.g. {{ email.messageIds[0] }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('templateId', 'Template ID', [ 'sent', 'events' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('event', 'Event', [ 'events' ], [ 'type' => 'select', 'default' => '', 'choices' => self::EVENTS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('tag', 'Tag', [ 'events', 'report' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('startDate', 'Period from', $period, [ 'help' => 'YYYY-MM-DD; with Period to', 'ui' => [ 'expression' => 'text', 'width' => 4, 'newRow' => true ] ]),
	    self::field('endDate', 'Period to', $period, [ 'help' => 'YYYY-MM-DD', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('days', 'Or the last (days)', $period, [ 'help' => 'Instead of Period from / to; up to 90', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('activeOnly', 'Active only', [ 'templates' ], [ 'type' => 'switch', 'default' => false ]),
	]);
    }

    protected static function resultOf($operation) {
	return $operation === 'send' ? self::sentDef() : null;
    }

    //a period's parameters: startDate + endDate, or days
    protected function period(\Closure $text) {
	$q = [];
	foreach([ 'startDate' => 'Period from', 'endDate' => 'Period to' ] as $k => $label) {
	    $v = $text($k);
	    if ($v !== '') {
		$ts = strtotime($v);
		if ($ts === false)
		    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($v, 0, 32) . '`');
		$q[$k] = date('Y-m-d', $ts);
	    }
	}
	if (count($q) === 1)
	    throw new \RuntimeException('Period from / to: give both, or neither');
	$days = $this->intOf($text, 'days', 'Or the last (days)', 1, 90);
	if ($days !== null) {
	    if ($q)
		throw new \RuntimeException('give Period from / to or the last days, not both');
	    $q['days'] = $days;
	}
	return $q;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$B = $this->client;
	switch($operation) {
	    case 'send':
		$m = $this->message($text, $value, false);
		$who = fn($a) => array_filter([ 'email' => $a['email'], 'name' => $a['name'] ], fn($v) => $v !== '');
		$body = [ 'sender' => $who($m['from']), 'to' => array_map($who, $m['to']) ];
		foreach([ 'cc', 'bcc' ] as $k)
		    if ($m[$k])
			$body[$k] = array_map($who, $m[$k]);
		if ($m['replyTo'])
		    $body['replyTo'] = $who($m['replyTo']);
		if ($m['subject'] !== '')
		    $body['subject'] = $m['subject'];
		if ($m['template'] !== null) {
		    if (!preg_match('/^\d{1,10}$/', $m['template']))
			throw new \RuntimeException('Template: Brevo\'s template ID, a number');
		    $body['templateId'] = (int)$m['template'];
		    if ($m['params'])
			$body['params'] = $m['params'];
		} else {
		    if (trim($m['html']) !== '')
			$body['htmlContent'] = $m['html'];
		    if (trim($m['text']) !== '')
			$body['textContent'] = $m['text'];
		}
		if ($m['attachments'])
		    $body['attachment'] = array_map(fn($a) => [ 'name' => $a['name'], 'content' => base64_encode($a['content']) ], $m['attachments']);
		if ($m['tags'])
		    $body['tags'] = $m['tags'];
		$d = $B->request('POST', '/smtp/email', [], $body);
		return self::sentResult($m, isset($d['messageIds']) ? (array)$d['messageIds'] : [ $d['messageId'] ?? '' ]);

	    case 'sent':
		$q = array_filter([ 'email' => $text('recipient'), 'messageId' => $text('messageId'), 'templateId' => $text('templateId') ], fn($v) => $v !== '');
		if (!$q)
		    throw new \RuntimeException('give a recipient, a message ID or a template ID (Brevo asks for one)');
		$q += $this->period($text);
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($B, $q) {
		    $d = $B->request('GET', '/smtp/emails', $q + [ 'limit' => $limit, 'offset' => $offset, 'sort' => 'desc' ]);
		    return [ (array)($d['transactionalEmails'] ?? []), isset($d['count']) ? (int)$d['count'] : null ];
		}, 500);
		return $this->listResult($items, 'email', $total, $more);

	    case 'events':
		$event = (string)$this->cfg('event', '');
		$q = array_filter([ 'email' => $text('recipient'), 'messageId' => $text('messageId'), 'templateId' => $text('templateId'), 'tags' => $text('tag'),
		    'event' => isset(self::EVENTS[$event]) ? $event : '' ], fn($v) => $v !== '') + $this->period($text);
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($B, $q) {
		    $d = $B->request('GET', '/smtp/statistics/events', $q + [ 'limit' => $limit, 'offset' => $offset, 'sort' => 'desc' ]);
		    return [ (array)($d['events'] ?? []), null ];
		}, 2500);
		return $this->listResult($items, 'event', $total, $more);

	    case 'report':
		$q = array_filter([ 'tag' => $text('tag') ], fn($v) => $v !== '') + $this->period($text);
		$d = $B->request('GET', '/smtp/statistics/aggregatedReport', $q);
		return [ $d, (int)($d['requests'] ?? 0) . ' sent, ' . (int)($d['delivered'] ?? 0) . ' delivered, ' . (int)($d['uniqueOpens'] ?? 0) . ' opened, '
		    . ((int)($d['hardBounces'] ?? 0) + (int)($d['softBounces'] ?? 0)) . ' bounced' ];

	    case 'templates':
		$active = (bool)$this->cfg('activeOnly', false);
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($B, $active) {
		    $d = $B->request('GET', '/smtp/templates', array_filter([ 'limit' => $limit, 'offset' => $offset, 'templateStatus' => $active ? 'true' : null ]));
		    return [ (array)($d['templates'] ?? []), isset($d['count']) ? (int)$d['count'] : null ];
		}, 1000);
		return $this->listResult($items, 'template', $total, $more);
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
