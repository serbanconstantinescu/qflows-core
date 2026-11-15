<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Brevo Contacts - the contacts and lists of a Brevo account (a Brevo credential, util\Brevo). Operations (setting `operation`):
	get       a contact by email address or ID (GET /contacts/{identifier}): its attributes, lists, blocklisting
	upsert    create a contact, or update it when the address is known (POST /contacts, updateEnabled): attributes (a JSON object, e.g. {"FIRSTNAME": "Ana"}), lists to add it to
	update    a contact by address or ID (PUT /contacts/{identifier}): attributes, lists to add it to / remove it from, blocklisted for email or not
	delete    a contact (DELETE /contacts/{identifier})
	list      the contacts (GET /contacts: changed since a date) or a list's (GET /contacts/lists/{id}/contacts)
	lists     the contact lists (GET /contacts/lists)
	add       addresses added to a list (POST /contacts/lists/{id}/contacts/add): { success, failure }
	remove    addresses removed from a list (POST /contacts/lists/{id}/contacts/remove)
*/

namespace models\node;

class NodeAppBrevoContacts extends NodeAppEmailService {
    public static $name = 'brevo-contacts';
    public static $label = 'Brevo Contacts';
    public static $icon = 'bi-person-lines-fill';
    public static $defaultOperation = 'get';
    public static $defaultVariable = 'contact';
    public static $capability = 'brevo';
    public static $credentialLabel = 'Brevo credential';

    const OPERATIONS = [
	'get' => 'Get a contact',
	'upsert' => 'Create or update a contact',
	'update' => 'Update a contact',
	'delete' => 'Delete a contact',
	'list' => 'List contacts',
	'lists' => 'List contact lists',
	'add' => 'Add contacts to a list',
	'remove' => 'Remove contacts from a list',
    ];
    const WRITES = [ 'upsert', 'update', 'delete', 'add', 'remove' ];
    const LISTS = [ 'list', 'lists' ];
    const BLOCKLIST = [ '' => 'Unchanged', 'yes' => 'Blocklisted (no email)', 'no' => 'Not blocklisted' ];

    protected static function operationFields() {
	return [
	    self::field('contact', 'Contact', [ 'get', 'upsert', 'update', 'delete' ], [ 'required' => true, 'help' => 'Its email address (upsert: the address), or its ID',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('attributes', 'Attributes', [ 'upsert', 'update' ], [ 'type' => 'textarea', 'help' => 'A JSON object of Brevo\'s attributes, e.g. {"FIRSTNAME": "{{ form.name }}", "SMS": "+40722123456"}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('listIds', 'Add to lists', [ 'upsert', 'update' ], [ 'help' => 'List IDs separated by commas', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('unlinkListIds', 'Remove from lists', [ 'update' ], [ 'help' => 'List IDs separated by commas', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('blocklisted', 'Email blocklisted', [ 'update' ], [ 'type' => 'select', 'default' => '', 'choices' => self::BLOCKLIST, 'ui' => [ 'width' => 6 ] ]),
	    self::field('listId', 'List ID', [ 'list', 'add', 'remove' ], [ 'help' => 'List contacts: empty for all the contacts', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'add', 'remove' ] ],
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('emails', 'Addresses', [ 'add', 'remove' ], [ 'required' => true, 'help' => 'Separated by commas, or a list', 'ui' => [ 'expression' => true ] ]),
	    self::field('modifiedSince', 'Changed since', [ 'list' ], [ 'help' => 'A date (and time)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    //the IDs of a setting (numbers separated by commas, or a list)
    protected function idsOf(\Closure $value, $key, $label) {
	$v = $value($key);
	$parts = is_array($v) ? $v : preg_split('/[\s,;]+/', \util\Expression::toText($v));
	$ids = [];
	foreach($parts as $p) {
	    $p = trim(is_array($p) ? (string)($p['id'] ?? '') : \util\Expression::toText($p));
	    if ($p === '')
		continue;
	    if (!preg_match('/^\d{1,10}$/', $p))
		throw new \RuntimeException("{$label}: not a list ID: `" . mb_substr($p, 0, 30) . '`');
	    $ids[] = (int)$p;
	}
	return array_values(array_unique($ids));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$B = $this->client;
	$contact = fn() => \util\EmailApi::segment($text('contact'), 'Contact');
	switch($operation) {
	    case 'get':
		$d = $B->request('GET', '/contacts/' . $contact());
		return [ $d, 'contact ' . ($d['email'] ?? $d['id'] ?? '') ];

	    case 'upsert':
		$email = self::addressesOf($text('contact'), 'Contact');
		if (count($email) !== 1)
		    throw new \RuntimeException('Contact: one email address');
		$body = array_filter([ 'email' => $email[0]['email'], 'attributes' => $this->jsonOf($value, 'attributes', 'Attributes'),
		    'listIds' => $this->idsOf($value, 'listIds', 'Add to lists') ]) + [ 'updateEnabled' => true ];
		$d = $B->request('POST', '/contacts', [], $body);
		return [ [ 'email' => $email[0]['email'], 'id' => $d['id'] ?? null, 'created' => isset($d['id']) ], (isset($d['id']) ? 'created ' : 'updated ') . $email[0]['email'] ];

	    case 'update':
		$body = array_filter([ 'attributes' => $this->jsonOf($value, 'attributes', 'Attributes'), 'listIds' => $this->idsOf($value, 'listIds', 'Add to lists'),
		    'unlinkListIds' => $this->idsOf($value, 'unlinkListIds', 'Remove from lists') ]);
		$block = (string)$this->cfg('blocklisted', '');
		if ($block === 'yes' || $block === 'no')
		    $body['emailBlacklisted'] = $block === 'yes';
		if (!$body)
		    throw new \RuntimeException('nothing to change: give attributes, lists or the blocklisting');
		$B->request('PUT', '/contacts/' . $contact(), [], $body);
		return [ [ 'contact' => $text('contact'), 'updated' => array_keys($body) ], 'updated ' . $text('contact') ];

	    case 'delete':
		$B->request('DELETE', '/contacts/' . $contact());
		return [ [ 'contact' => $text('contact'), 'deleted' => true ], 'deleted ' . $text('contact') ];

	    case 'list':
		$list = $text('listId');
		if ($list !== '' && !preg_match('/^\d{1,10}$/', $list))
		    throw new \RuntimeException('List ID: a number');
		$since = $text('modifiedSince');
		$q = [];
		if ($since !== '') {
		    $ts = strtotime($since);
		    if ($ts === false)
			throw new \RuntimeException('Changed since: not a date: `' . mb_substr($since, 0, 32) . '`');
		    $q['modifiedSince'] = gmdate('Y-m-d\TH:i:s.000\Z', $ts);
		}
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($B, $q, $list) {
		    $d = $B->request('GET', $list === '' ? '/contacts' : "/contacts/lists/{$list}/contacts", $q + [ 'limit' => $limit, 'offset' => $offset ]);
		    return [ (array)($d['contacts'] ?? []), isset($d['count']) ? (int)$d['count'] : null ];
		}, 500);
		return $this->listResult($items, 'contact', $total, $more);

	    case 'lists':
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($B) {
		    $d = $B->request('GET', '/contacts/lists', [ 'limit' => $limit, 'offset' => $offset ]);
		    return [ (array)($d['lists'] ?? []), isset($d['count']) ? (int)$d['count'] : null ];
		}, 50);
		return $this->listResult($items, 'list', $total, $more);

	    case 'add':
	    case 'remove':
		$list = $text('listId');
		if (!preg_match('/^\d{1,10}$/', $list))
		    throw new \RuntimeException('List ID: a number');
		$emails = array_column(self::addressesOf($value('emails'), 'Addresses'), 'email');
		if (!$emails)
		    throw new \RuntimeException('Addresses: none given');
		if (count($emails) > 150)
		    throw new \RuntimeException('Addresses: at most 150 at a time');
		$d = $B->request('POST', "/contacts/lists/{$list}/contacts/{$operation}", [], [ 'emails' => $emails ]);
		$r = [ 'success' => array_values((array)($d['contacts']['success'] ?? [])), 'failure' => array_values((array)($d['contacts']['failure'] ?? [])) ];
		return [ $r, ($operation === 'add' ? 'added to' : 'removed from') . " list {$list}: " . count($r['success']) . ($r['failure'] ? ', ' . count($r['failure']) . ' failed' : '') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
