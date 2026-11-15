<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Mailjet Contacts - the contacts and contact lists of a Mailjet account (a Mailjet credential, util\Mailjet). Operations (setting `operation`):
	get          a contact by email address or ID (GET /v3/REST/contact/{id}) with its properties (GET /v3/REST/contactdata/{id}: { properties })
	create       a contact (POST /v3/REST/contact): address, name, excluded from campaigns
	properties   a contact's properties set (PUT /v3/REST/contactdata/{id}: a JSON object, e.g. {"firstname": "Ana"}; the properties exist in Mailjet)
	subscribe    a contact added to a list and subscribed (POST /v3/REST/contactslist/{id}/managecontact, addforce; created when new), with properties
	unsubscribe  unsubscribed from a list (Action unsub); remove: taken out of it (Action remove)
	delete       a contact deleted for good (DELETE /v4/contacts/{id}; GDPR; the address's ID looked up first)
	list         the contacts, or a list's (GET /v3/REST/contact, ContactsList)
	lists        the contact lists (GET /v3/REST/contactslist)
*/

namespace models\node;

class NodeAppMailjetContacts extends NodeAppEmailService {
    public static $name = 'mailjet-contacts';
    public static $label = 'Mailjet Contacts';
    public static $icon = 'bi-person-lines-fill';
    public static $defaultOperation = 'get';
    public static $defaultVariable = 'contact';
    public static $capability = 'mailjet';
    public static $credentialLabel = 'Mailjet credential';

    const OPERATIONS = [
	'get' => 'Get a contact',
	'create' => 'Create a contact',
	'properties' => 'Set a contact\'s properties',
	'subscribe' => 'Add to a list (subscribed)',
	'unsubscribe' => 'Unsubscribe from a list',
	'remove' => 'Remove from a list',
	'delete' => 'Delete a contact',
	'list' => 'List contacts',
	'lists' => 'List contact lists',
    ];
    const WRITES = [ 'create', 'properties', 'subscribe', 'unsubscribe', 'remove', 'delete' ];
    const LISTS = [ 'list', 'lists' ];
    const ACTIONS = [ 'subscribe' => 'addforce', 'unsubscribe' => 'unsub', 'remove' => 'remove' ];

    protected static function operationFields() {
	return [
	    self::field('contact', 'Contact', [ 'get', 'create', 'properties', 'subscribe', 'unsubscribe', 'remove', 'delete' ], [ 'required' => true,
		'help' => 'Its email address, or its ID (create, lists: the address)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('contactName', 'Name', [ 'create', 'subscribe' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('excluded', 'Excluded from campaigns', [ 'create' ], [ 'type' => 'switch', 'default' => false ]),
	    self::field('properties', 'Properties', [ 'properties', 'subscribe' ], [ 'type' => 'textarea', 'help' => 'A JSON object of the contact\'s properties (defined in Mailjet), e.g. {"firstname": "{{ form.name }}"}',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('listId', 'List ID', [ 'subscribe', 'unsubscribe', 'remove', 'list' ], [ 'help' => 'List contacts: empty for all', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'subscribe', 'unsubscribe', 'remove' ] ],
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function listId(\Closure $text, $required = true) {
	$l = $text('listId');
	if ($l === '' && !$required)
	    return '';
	if (!preg_match('/^\d{1,12}$/', $l))
	    throw new \RuntimeException('List ID: a number');
	return $l;
    }

    //the contact's address (create, lists)
    protected function address(\Closure $text) {
	$a = self::addressesOf($text('contact'), 'Contact');
	if (count($a) !== 1)
	    throw new \RuntimeException('Contact: one email address');
	return $a[0]['email'];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$M = $this->client;
	switch($operation) {
	    case 'get':
		$id = \util\EmailApi::segment($text('contact'), 'Contact');
		$c = (array)($M->request('GET', "/v3/REST/contact/{$id}")['Data'][0] ?? []);
		if (!$c)
		    throw new \RuntimeException('no contact ' . $text('contact'));
		$data = (array)($M->request('GET', '/v3/REST/contactdata/' . (int)$c['ID'])['Data'][0]['Data'] ?? []);
		$c['properties'] = array_column($data, 'Value', 'Name');
		return [ $c, 'contact ' . ($c['Email'] ?? $c['ID']) ];

	    case 'create':
		$body = [ 'Email' => $this->address($text) ] + array_filter([ 'Name' => $text('contactName') ]) + ($this->cfg('excluded', false) ? [ 'IsExcludedFromCampaigns' => true ] : []);
		$c = (array)($M->request('POST', '/v3/REST/contact', [], $body)['Data'][0] ?? []);
		return [ $c, 'created ' . $body['Email'] . (isset($c['ID']) ? " ({$c['ID']})" : '') ];

	    case 'properties':
		$props = $this->jsonOf($value, 'properties', 'Properties');
		if (!$props)
		    throw new \RuntimeException('Properties: none given');
		$id = \util\EmailApi::segment($text('contact'), 'Contact');
		$data = array_map(fn($k, $v) => [ 'Name' => (string)$k, 'Value' => $v ], array_keys($props), array_values($props));
		$M->request('PUT', "/v3/REST/contactdata/{$id}", [], [ 'Data' => $data ]);
		return [ [ 'contact' => $text('contact'), 'properties' => $props ], 'set ' . \util\Format::plural(count($props), 'property', 'properties') . ' of ' . $text('contact') ];

	    case 'subscribe':
	    case 'unsubscribe':
	    case 'remove':
		$list = $this->listId($text);
		$body = [ 'Email' => $this->address($text), 'Action' => self::ACTIONS[$operation] ];
		if ($operation === 'subscribe') {
		    $body += array_filter([ 'Name' => $text('contactName') ]);
		    if ($props = $this->jsonOf($value, 'properties', 'Properties'))
			$body['Properties'] = $props;
		}
		$d = (array)($M->request('POST', "/v3/REST/contactslist/{$list}/managecontact", [], $body)['Data'][0] ?? []);
		return [ $d + [ 'Email' => $body['Email'], 'ListID' => (int)$list ], [ 'subscribe' => 'subscribed to', 'unsubscribe' => 'unsubscribed from', 'remove' => 'removed from' ][$operation]
		    . " list {$list}: {$body['Email']}" ];

	    case 'delete':
		$c = $text('contact');
		if (!preg_match('/^\d{1,15}$/', $c)) {
		    $found = (array)($M->request('GET', '/v3/REST/contact/' . \util\EmailApi::segment($c, 'Contact'))['Data'][0] ?? []);
		    if (!isset($found['ID']))
			throw new \RuntimeException("no contact {$c}");
		    $id = (int)$found['ID'];
		} else
		    $id = (int)$c;
		$M->request('DELETE', "/v4/contacts/{$id}");
		return [ [ 'contact' => $c, 'id' => $id, 'deleted' => true ], "deleted {$c}" ];

	    case 'list':
		$list = $this->listId($text, false);
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($M, $list) {
		    $d = $M->request('GET', '/v3/REST/contact', array_filter([ 'ContactsList' => $list, 'Limit' => $limit, 'Offset' => $offset ], fn($v) => $v !== ''));
		    return [ (array)($d['Data'] ?? []), isset($d['Total']) ? (int)$d['Total'] : null ];
		}, 1000);
		return $this->listResult($items, 'contact', $total, $more);

	    case 'lists':
		[ $items, $total, $more ] = $this->offsetPages(function($offset, $limit) use ($M) {
		    $d = $M->request('GET', '/v3/REST/contactslist', [ 'Limit' => $limit, 'Offset' => $offset ]);
		    return [ (array)($d['Data'] ?? []), isset($d['Total']) ? (int)$d['Total'] : null ];
		}, 1000);
		return $this->listResult($items, 'list', $total, $more);
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
