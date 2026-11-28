<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Amazon SES Email - email sent through Amazon SES (an Amazon SES credential, util\vendor\AmazonSes: API v2). Operations (setting `operation`):
	send       a message (POST /v2/email/outbound-emails): the message of NodeAppEmailService; text / HTML without attachments as Simple content, with attachments or a designed
	           message (its images inline) as a raw MIME message (util\EmailSend::compose, as the Send email step's), or an SES template by its name with its data (Template;
	           SES sends no attachments with it); the credential's configuration set; the tags as message tags (name=value, or a name: value true)
	templates  the email templates of the region (GET /v2/email/templates)
	template   one, with its subject and parts (GET /v2/email/templates/{name})
    A send's result: { messageIds, to, count }. In the SES sandbox (a new account) only verified addresses receive (Amazon SES Account: account)
*/

namespace models\node;

class NodeAppSesEmail extends NodeAppEmailService {
    public static $name = 'ses-email';
    public static $label = 'Amazon SES Email';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'email';
    public static $capability = 'amazonses';
    public static $credentialLabel = 'Amazon SES credential';

    const OPERATIONS = [
	'send' => 'Send an email',
	'templates' => 'List templates',
	'template' => 'Get a template',
    ];
    const WRITES = [ 'send' ];
    const LISTS = [ 'templates' ];
    const SEND_OPS = [ 'send' ];
    //the largest raw message SES v2 takes (40 MB, base64 included)
    const MAX_RAW = 40 * 1024 * 1024;
    const TAG = '/^[A-Za-z0-9_-]{1,256}$/';

    protected static function operationFields() {
	return array_merge(self::messageFields('Its name (SES email templates of the region)', 'A JSON object of the template\'s data ({{name}} in SES), e.g. {"name": "{{ form.name }}"}'), [
	    self::field('templateName', 'Template name', [ 'template' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	]);
    }

    protected static function resultOf($operation) {
	return $operation === 'send' ? self::sentDef() : null;
    }

    //an address as SES takes it: "Name" <a@x> (the name encoded when not ASCII)
    public static function sesAddress(array $a) {
	return \util\EmailSend::displayName($a['name']) . ($a['name'] !== '' ? "<{$a['email']}>" : $a['email']);
    }

    //the tags of the message: name=value, or a name (value true); SES takes letters, digits, _ and -
    protected static function tagsOf(array $tags) {
	$out = [];
	foreach($tags as $t) {
	    [ $n, $v ] = array_pad(array_map('trim', explode('=', $t, 2)), 2, 'true');
	    if (!preg_match(self::TAG, $n) || !preg_match(self::TAG, $v))
		throw new \RuntimeException("Tags: `{$t}`: SES takes letters, digits, _ and - (name=value)");
	    $out[] = [ 'Name' => $n, 'Value' => $v ];
	}
	return $out;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->client;
	switch($operation) {
	    case 'send':
		$m = $this->message($text, $value, true);
		$body = [ 'FromEmailAddress' => self::sesAddress($m['from']), 'Destination' => array_filter([
		    'ToAddresses' => array_map(self::sesAddress(...), $m['to']),
		    'CcAddresses' => array_map(self::sesAddress(...), $m['cc']),
		    'BccAddresses' => array_map(self::sesAddress(...), $m['bcc']),
		]) ];
		if ($m['replyTo'])
		    $body['ReplyToAddresses'] = [ self::sesAddress($m['replyTo']) ];
		$utf = fn($t) => [ 'Data' => $t, 'Charset' => 'UTF-8' ];
		if ($m['template'] !== null) {
		    if ($m['attachments'])
			throw new \RuntimeException('Attachments: SES sends none with a template');
		    $body['Content'] = [ 'Template' => [ 'TemplateName' => $m['template'], 'TemplateData' => \util\JSON::encode($m['params'] ?: (object)[]) ] ];
		} elseif ($m['attachments'] || $m['images']) {
		    //a raw message: the Send email step's MIME, with its attachments
		    $html = trim($m['html']) !== '' ? $m['html'] : '<pre style="font-family: inherit; white-space: pre-wrap;">' . htmlspecialchars($m['text'], ENT_QUOTES | ENT_HTML5) . '</pre>';
		    [ $headers, $mime ] = \util\EmailSend::compose([ 'from' => $m['from']['email'], 'from_name' => $m['from']['name'], 'to' => array_column($m['to'], 'email'),
			'cc' => array_column($m['cc'], 'email'), 'reply_to' => $m['replyTo']['email'] ?? '', 'subject' => $m['subject'], 'html' => $html,
			'text' => trim($m['text']) !== '' ? $m['text'] : '', 'images' => $m['images'], 'attachments' => $m['attachments'] ]);
		    $raw = base64_encode($headers . "\r\n\r\n" . $mime);
		    if (strlen($raw) > self::MAX_RAW)
			throw new \RuntimeException('the message is larger than ' . (self::MAX_RAW >> 20) . ' MB (SES\'s limit)');
		    $body['Content'] = [ 'Raw' => [ 'Data' => $raw ] ];
		} else {
		    $b = [];
		    if (trim($m['text']) !== '')
			$b['Text'] = $utf($m['text']);
		    if (trim($m['html']) !== '')
			$b['Html'] = $utf($m['html']);
		    $body['Content'] = [ 'Simple' => [ 'Subject' => $utf($m['subject']), 'Body' => $b ] ];
		}
		if ($m['tags'])
		    $body['EmailTags'] = self::tagsOf($m['tags']);
		if (($set = trim((string)($this->cred->configurationSet ?? ''))) !== '')
		    $body['ConfigurationSetName'] = $set;
		$d = $S->request('POST', '/v2/email/outbound-emails', [], $body);
		return self::sentResult($m, [ $d['MessageId'] ?? '' ]);

	    case 'templates':
		[ $items, $more ] = $this->tokenPages(function($token, $limit) use ($S) {
		    $d = $S->request('GET', '/v2/email/templates', array_filter([ 'PageSize' => max(1, min(100, $limit)), 'NextToken' => $token ]));
		    return [ (array)($d['TemplatesMetadata'] ?? []), $d['NextToken'] ?? null ];
		}, 100);
		return $this->listResult($items, 'template', null, $more);

	    case 'template':
		$d = $S->request('GET', '/v2/email/templates/' . \util\EmailApi::segment($text('templateName'), 'Template name'));
		return [ $d, 'template ' . ($d['TemplateName'] ?? $text('templateName')) ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
