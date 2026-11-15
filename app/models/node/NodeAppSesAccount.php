<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Amazon SES Account - the SES account of a region (an Amazon SES credential, util\AmazonSes: API v2). Operations (setting `operation`):
	account      the sending quota and its use in 24 h, the sending rate, production access or the sandbox, sending enabled, the enforcement status (GET /v2/email/account)
	identities   the identities (addresses and domains) and their verification (GET /v2/email/identities)
	identity     one, with its DKIM state and policies (GET /v2/email/identities/{identity})
	verify       an address or a domain added (POST /v2/email/identities): SES sends the address a link; a domain's DKIM records (CNAMEs) to publish come back
	suppressed   the account's suppression list (GET /v2/email/suppression/addresses): bounces and complaints, by reason, since a date
	check        whether an address is suppressed (GET …/addresses/{address}): { suppressed, reason, lastUpdate }
	suppress     an address added to it (PUT …/addresses: BOUNCE or COMPLAINT); unsuppress: taken out of it (DELETE …/addresses/{address})
*/

namespace models\node;

class NodeAppSesAccount extends NodeAppEmailService {
    public static $name = 'ses-account';
    public static $label = 'Amazon SES Account';
    public static $icon = 'bi-shield-check';
    public static $defaultOperation = 'account';
    public static $defaultVariable = 'ses';
    public static $capability = 'amazonses';
    public static $credentialLabel = 'Amazon SES credential';

    const OPERATIONS = [
	'account' => 'Read the sending quota',
	'identities' => 'List identities',
	'identity' => 'Get an identity',
	'verify' => 'Verify an address or a domain',
	'suppressed' => 'List suppressed addresses',
	'check' => 'Check an address',
	'suppress' => 'Suppress an address',
	'unsuppress' => 'Remove an address from the suppression list',
    ];
    const WRITES = [ 'verify', 'suppress', 'unsuppress' ];
    const LISTS = [ 'identities', 'suppressed' ];
    const REASONS = [ '' => 'Both', 'BOUNCE' => 'Bounces', 'COMPLAINT' => 'Complaints' ];

    protected static function operationFields() {
	return [
	    self::field('identity', 'Identity', [ 'identity', 'verify' ], [ 'required' => true, 'help' => 'An address or a domain, e.g. noreply@acme.com or acme.com', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('address', 'Address', [ 'check', 'suppress', 'unsuppress' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('reason', 'Reason', [ 'suppressed', 'suppress' ], [ 'type' => 'select', 'default' => '', 'choices' => self::REASONS, 'help' => 'Suppress: Bounces when Both', 'ui' => [ 'width' => 6 ] ]),
	    self::field('since', 'Since', [ 'suppressed' ], [ 'help' => 'A date (and time); empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function address(\Closure $text) {
	$a = self::addressesOf($text('address'), 'Address');
	if (count($a) !== 1)
	    throw new \RuntimeException('Address: one email address');
	return $a[0]['email'];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->client;
	switch($operation) {
	    case 'account':
		$d = $S->request('GET', '/v2/email/account');
		$q = (array)($d['SendQuota'] ?? []);
		return [ $d, (!empty($d['ProductionAccessEnabled']) ? 'production' : 'sandbox') . ', ' . (int)($q['SentLast24Hours'] ?? 0) . ' of ' . (int)($q['Max24HourSend'] ?? 0)
		    . ' sent in 24 h, ' . (float)($q['MaxSendRate'] ?? 0) . ' a second' . (isset($d['SendingEnabled']) && !$d['SendingEnabled'] ? ', sending paused' : '') ];

	    case 'identities':
		[ $items, $more ] = $this->tokenPages(function($token, $limit) use ($S) {
		    $d = $S->request('GET', '/v2/email/identities', array_filter([ 'PageSize' => max(1, min(1000, $limit)), 'NextToken' => $token ]));
		    return [ (array)($d['EmailIdentities'] ?? []), $d['NextToken'] ?? null ];
		}, 1000);
		return $this->listResult($items, 'identity', null, $more);

	    case 'identity':
		$id = $text('identity');
		$d = $S->request('GET', '/v2/email/identities/' . \util\EmailApi::segment($id, 'Identity'));
		return [ [ 'identity' => $id ] + $d, "{$id}: " . ($d['VerificationStatus'] ?? (!empty($d['VerifiedForSendingStatus']) ? 'verified' : 'not verified')) ];

	    case 'verify':
		$id = strtolower($text('identity'));
		if (!filter_var($id, FILTER_VALIDATE_EMAIL) && !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $id))
		    throw new \RuntimeException('Identity: an address or a domain');
		$d = $S->request('POST', '/v2/email/identities', [], [ 'EmailIdentity' => $id ]);
		$tokens = (array)($d['DkimAttributes']['Tokens'] ?? []);
		//a domain's DKIM records to publish: <token>._domainkey.<domain> CNAME <token>.dkim.amazonses.com
		$d['dkimRecords'] = array_map(fn($t) => [ 'name' => "{$t}._domainkey.{$id}", 'type' => 'CNAME', 'value' => "{$t}.dkim.amazonses.com" ], $tokens);
		return [ [ 'identity' => $id ] + $d, str_contains($id, '@') ? "verification sent to {$id}" : "{$id} added: publish " . \util\Format::plural(count($tokens), 'DKIM record') ];

	    case 'suppressed':
		$reason = (string)$this->cfg('reason', '');
		$since = $text('since');
		$q = [];
		if ($since !== '') {
		    $ts = strtotime($since);
		    if ($ts === false)
			throw new \RuntimeException('Since: not a date: `' . mb_substr($since, 0, 32) . '`');
		    $q['StartDate'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
		}
		if (isset(self::REASONS[$reason]) && $reason !== '')
		    $q['Reasons'] = $reason;
		[ $items, $more ] = $this->tokenPages(function($token, $limit) use ($S, $q) {
		    $d = $S->request('GET', '/v2/email/suppression/addresses', $q + array_filter([ 'PageSize' => max(1, min(1000, $limit)), 'NextToken' => $token ]));
		    return [ (array)($d['SuppressedDestinationSummaries'] ?? []), $d['NextToken'] ?? null ];
		}, 1000);
		return $this->listResult($items, 'address', null, $more);

	    case 'check':
		$a = $this->address($text);
		try {
		    $d = (array)($S->request('GET', '/v2/email/suppression/addresses/' . rawurlencode($a))['SuppressedDestination'] ?? []);
		} catch (\RuntimeException $e) {
		    if (!str_starts_with($e->getMessage(), 'HTTP status 404'))
			throw $e;
		    return [ [ 'address' => $a, 'suppressed' => false, 'reason' => null, 'lastUpdate' => null ], "{$a}: not suppressed" ];
		}
		return [ [ 'address' => $a, 'suppressed' => true, 'reason' => $d['Reason'] ?? null, 'lastUpdate' => $d['LastUpdateTime'] ?? null ],
		    "{$a}: suppressed (" . ($d['Reason'] ?? '?') . ')' ];

	    case 'suppress':
		$a = $this->address($text);
		$reason = (string)$this->cfg('reason', '') === 'COMPLAINT' ? 'COMPLAINT' : 'BOUNCE';
		$S->request('PUT', '/v2/email/suppression/addresses', [], [ 'EmailAddress' => $a, 'Reason' => $reason ]);
		return [ [ 'address' => $a, 'suppressed' => true, 'reason' => $reason ], "{$a} suppressed ({$reason})" ];

	    case 'unsuppress':
		$a = $this->address($text);
		$S->request('DELETE', '/v2/email/suppression/addresses/' . rawurlencode($a));
		return [ [ 'address' => $a, 'suppressed' => false ], "{$a} removed from the suppression list" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
