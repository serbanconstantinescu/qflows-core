<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: Okta Access - what a person may use and how they sign in (NodeAppOkta). Operations:
	apps          the apps assigned to a person (their links: { app, appId, label, url })
	assignApp     a person assigned to an app (by its label or ID), optionally with profile attributes for it (JSON)
	removeApp     the assignment removed (the app's account deprovisioned, as the app does)
	factors       the person's enrolled factors (Okta Verify, SMS, email, WebAuthn, …)
	resetFactors  all of them reset: they enroll again at the next sign-in; resetFactor: one (by its ID or type)
*/

namespace models\node;

class NodeAppOktaAccess extends NodeAppOkta {
    public static $name = 'okta-access';
    public static $label = 'Okta Access';
    public static $icon = 'bi-key';
    public static $defaultOperation = 'apps';
    public static $defaultVariable = 'access';

    const OPERATIONS = [
	'apps' => 'List a person\'s apps',
	'assignApp' => 'Assign to an app',
	'removeApp' => 'Remove from an app',
	'factors' => 'List the factors',
	'resetFactors' => 'Reset all factors (MFA)',
	'resetFactor' => 'Reset a factor',
    ];
    const WRITES = [ 'assignApp', 'removeApp', 'resetFactors', 'resetFactor' ];
    const LISTS = [ 'apps', 'factors' ];

    protected static function operationFields() {
	return [
	    self::userField(array_keys(self::OPERATIONS)),
	    self::field('app', 'App', [ 'assignApp', 'removeApp' ], [ 'required' => true, 'help' => 'Its label (as the admin console shows it) or ID', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('appProfile', 'App attributes', [ 'assignApp' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object of the app\'s profile attributes for them', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('factor', 'Factor', [ 'resetFactor' ], [ 'required' => true, 'help' => 'Its ID, or its type (e.g. push, sms, email, webauthn, token:software:totp)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    //an app's ID of a setting: its ID (0oa…), or its label (exactly one active app)
    private function appId($value) {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['id'] ?? $value['appId'] ?? '') : $value));
	if (preg_match('/^0oa[A-Za-z0-9]{10,30}$/', $v))
	    return $v;
	$found = array_values(array_filter((array)$this->okta->request('GET', '/apps', [ 'q' => $v, 'limit' => 50, 'filter' => 'status eq "ACTIVE"' ]), fn($a) => strcasecmp((string)($a['label'] ?? ''), $v) === 0));
	if (count($found) !== 1)
	    throw new \RuntimeException('App: ' . ($found ? count($found) . " apps are labelled `{$v}`: give its ID" : "no active app `{$v}`"));
	return (string)$found[0]['id'];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$uid = $this->userId($value('user'));
	switch($operation) {
	    case 'apps':
		return $this->listOf("/users/{$uid}/appLinks", [], 'app', fn($a) => [ 'app' => (string)($a['appName'] ?? ''), 'appId' => (string)($a['appInstanceId'] ?? ''), 'label' => (string)($a['label'] ?? ''),
		    'url' => (string)($a['linkUrl'] ?? '') ]);
	    case 'assignApp':
		$app = $this->appId($value('app'));
		$profile = $this->jsonOf('appProfile', 'App attributes');
		$a = $this->okta->request('POST', "/apps/{$app}/users", [], [ 'id' => $uid ] + ($profile ? [ 'profile' => $profile ] : []));
		return [ [ 'app' => $app, 'user' => $uid, 'status' => (string)($a['status'] ?? ''), 'scope' => (string)($a['scope'] ?? '') ], "{$uid} assigned to {$app}" ];
	    case 'removeApp':
		$app = $this->appId($value('app'));
		$this->okta->request('DELETE', "/apps/{$app}/users/{$uid}");
		return [ [ 'app' => $app, 'user' => $uid, 'removed' => true ], "{$uid} removed from {$app}" ];
	    case 'factors':
		$all = array_map(fn($f) => [ 'id' => (string)($f['id'] ?? ''), 'type' => (string)($f['factorType'] ?? ''), 'provider' => (string)($f['provider'] ?? ''), 'status' => (string)($f['status'] ?? ''),
		    'name' => (string)($f['profile']['name'] ?? $f['profile']['phoneNumber'] ?? $f['profile']['email'] ?? $f['profile']['credentialId'] ?? ''), 'created' => (string)($f['created'] ?? '') ],
		    (array)$this->okta->request('GET', "/users/{$uid}/factors"));
		return [ [ 'items' => $all, 'count' => count($all), 'truncated' => false ], \util\Format::plural(count($all), 'factor') ];
	    case 'resetFactors':
		$n = count((array)$this->okta->request('GET', "/users/{$uid}/factors"));
		$this->okta->request('POST', "/users/{$uid}/lifecycle/reset_factors");
		return [ [ 'user' => $uid, 'reset' => $n ], "{$uid}: " . \util\Format::plural($n, 'factor') . ' reset' ];
	    case 'resetFactor':
		$f = $text('factor');
		$id = $f;
		if (!preg_match('/^[A-Za-z0-9]{15,30}$/', $f)) {
		    $match = array_values(array_filter((array)$this->okta->request('GET', "/users/{$uid}/factors"), fn($x) => strcasecmp((string)($x['factorType'] ?? ''), $f) === 0));
		    if (!$match)
			throw new \RuntimeException("Factor: {$uid} has no factor `{$f}`");
		    $id = (string)$match[0]['id'];
		}
		$this->okta->request('DELETE', "/users/{$uid}/factors/" . rawurlencode($id));
		return [ [ 'user' => $uid, 'factor' => $id, 'reset' => true ], "{$uid}: factor {$f} reset" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
