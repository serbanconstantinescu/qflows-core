<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: Entra ID Access - what a user may use and how it signs in (NodeAppEntra). Operations:
	skus           the tenant's licenses: { skuId, sku (e.g. ENTERPRISEPACK), enabled, consumed, available }
	licenses       a user's licenses; assignLicense, removeLicense: by SKU part number (e.g. SPE_E3) or ID (the user needs a usage location); Directory / User.ReadWrite.All
	appRoles       the apps (and their roles) a user is assigned to
	assignApp      a user assigned to an app (an enterprise application: by display name, app ID or object ID) with a role (its value or display name; empty: the default access)
	removeApp      the user's assignment to that app removed
	methods        a user's sign-in methods (password, phone, Microsoft Authenticator, FIDO2, Windows Hello, email, software OATH, temporary access pass)
	resetMfa       the user's methods other than the password removed: it registers again at its next sign-in (UserAuthenticationMethod.ReadWrite.All)
	createTap      a temporary access pass (minutes valid, one use or not): to sign in once and register new methods; given back once
*/

namespace models\node;

class NodeAppEntraAccess extends NodeAppEntra {
    public static $name = 'entra-access';
    public static $label = 'Entra ID Access';
    public static $icon = 'bi-key';
    public static $defaultOperation = 'licenses';
    public static $defaultVariable = 'access';

    const OPERATIONS = [
	'skus' => 'List the tenant\'s licenses',
	'licenses' => 'List a user\'s licenses',
	'assignLicense' => 'Assign a license',
	'removeLicense' => 'Remove a license',
	'appRoles' => 'List a user\'s apps',
	'assignApp' => 'Assign to an app',
	'removeApp' => 'Remove from an app',
	'methods' => 'List the sign-in methods',
	'resetMfa' => 'Reset the sign-in methods (MFA)',
	'createTap' => 'Create a temporary access pass',
    ];
    const WRITES = [ 'assignLicense', 'removeLicense', 'assignApp', 'removeApp', 'resetMfa', 'createTap' ];
    const LISTS = [ 'skus', 'licenses', 'appRoles', 'methods' ];
    //the method types removed by resetMfa: [ @odata.type => path ]
    const METHODS = [
	'#microsoft.graph.phoneAuthenticationMethod' => 'phoneMethods',
	'#microsoft.graph.microsoftAuthenticatorAuthenticationMethod' => 'microsoftAuthenticatorMethods',
	'#microsoft.graph.fido2AuthenticationMethod' => 'fido2Methods',
	'#microsoft.graph.windowsHelloForBusinessAuthenticationMethod' => 'windowsHelloForBusinessMethods',
	'#microsoft.graph.emailAuthenticationMethod' => 'emailMethods',
	'#microsoft.graph.softwareOathAuthenticationMethod' => 'softwareOathMethods',
	'#microsoft.graph.temporaryAccessPassAuthenticationMethod' => 'temporaryAccessPassMethods',
	'#microsoft.graph.platformCredentialAuthenticationMethod' => 'platformCredentialMethods',
    ];
    const USER_OPS = [ 'licenses', 'assignLicense', 'removeLicense', 'appRoles', 'assignApp', 'removeApp', 'methods', 'resetMfa', 'createTap' ];

    protected static function operationFields() {
	return [
	    self::userField(self::USER_OPS),
	    self::field('sku', 'License', [ 'assignLicense', 'removeLicense' ], [ 'required' => true, 'help' => 'Its SKU part number (e.g. SPE_E3, ENTERPRISEPACK) or SKU ID (List the tenant\'s licenses)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('app', 'App', [ 'assignApp', 'removeApp' ], [ 'required' => true, 'help' => 'The enterprise application\'s display name, app ID or object ID', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('role', 'Role', [ 'assignApp' ], [ 'help' => 'Optional: the app role\'s value or display name; empty: the default access', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('minutes', 'Valid for (minutes)', [ 'createTap' ], [ 'type' => 'number', 'required' => true, 'default' => 60,
		'validate' => [ [ 'inRange', 'lower' => 10, 'upper' => 43200, 'skipEmpty' => true, 'message' => 'from 10 to 43200' ] ], 'ui' => [ 'step' => 1, 'width' => 6 ] ]),
	    self::field('oneUse', 'One use only', [ 'createTap' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //a SKU's ID of a setting: a GUID, or a part number of the tenant's SKUs
    private function skuId($value) {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['skuId'] ?? $value['sku'] ?? '') : $value));
	if (\util\Entra::isGuid($v))
	    return [ $v, $v ];
	foreach((array)($this->graph->request('GET', '/subscribedSkus', [ '$select' => 'skuId,skuPartNumber' ])['value'] ?? []) as $s)
	    if (strcasecmp((string)($s['skuPartNumber'] ?? ''), $v) === 0)
		return [ (string)$s['skuId'], (string)$s['skuPartNumber'] ];
	throw new \RuntimeException("License: the tenant has no license `{$v}`");
    }

    //an enterprise application (service principal) of a setting: its object ID, app ID or display name
    private function servicePrincipal($value) {
	$v = trim(\util\Expression::toText(is_array($value) ? ($value['id'] ?? $value['appId'] ?? '') : $value));
	if ($v === '')
	    throw new \RuntimeException('App: empty');
	$filter = \util\Entra::isGuid($v) ? 'appId eq ' . \util\Entra::quote($v) : 'displayName eq ' . \util\Entra::quote($v);
	$found = (array)($this->graph->request('GET', '/servicePrincipals', [ '$filter' => $filter, '$select' => 'id,appId,displayName,appRoles', '$top' => 5 ])['value'] ?? []);
	if (!$found && \util\Entra::isGuid($v)) {
	    $sp = $this->graph->request('GET', "/servicePrincipals/{$v}", [ '$select' => 'id,appId,displayName,appRoles' ], null, [ 'ok' => [ 404 ] ]);
	    if (isset($sp['id']))
		$found = [ $sp ];
	}
	if (count($found) !== 1)
	    throw new \RuntimeException('App: ' . ($found ? count($found) . " apps are named `{$v}`: give its app ID" : "no enterprise application `{$v}`"));
	return $found[0];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'skus') {
	    $all = array_map(fn($s) => [ 'skuId' => (string)($s['skuId'] ?? ''), 'sku' => (string)($s['skuPartNumber'] ?? ''), 'status' => (string)($s['capabilityStatus'] ?? ''),
		'enabled' => (int)($s['prepaidUnits']['enabled'] ?? 0), 'consumed' => (int)($s['consumedUnits'] ?? 0),
		'available' => max(0, (int)($s['prepaidUnits']['enabled'] ?? 0) - (int)($s['consumedUnits'] ?? 0)) ], (array)($this->graph->request('GET', '/subscribedSkus')['value'] ?? []));
	    return [ [ 'items' => $all, 'count' => count($all), 'truncated' => false ], \util\Format::plural(count($all), 'license') ];
	}
	$ref = self::userRef($value('user'));
	$path = "/users/{$ref}";
	switch($operation) {
	    case 'licenses':
		return $this->listOf("{$path}/licenseDetails", [], 'license', fn($l) => [ 'skuId' => (string)($l['skuId'] ?? ''), 'sku' => (string)($l['skuPartNumber'] ?? '') ]);
	    case 'assignLicense':
	    case 'removeLicense':
		[ $id, $sku ] = $this->skuId($value('sku'));
		$add = $operation === 'assignLicense';
		$this->graph->request('POST', "{$path}/assignLicense", [], [ 'addLicenses' => $add ? [ [ 'skuId' => $id, 'disabledPlans' => [] ] ] : [], 'removeLicenses' => $add ? [] : [ $id ] ]);
		return [ [ 'user' => rawurldecode($ref), 'sku' => $sku, 'skuId' => $id, 'assigned' => $add ], "{$sku} " . ($add ? 'assigned to ' : 'removed from ') . rawurldecode($ref) ];
	    case 'appRoles':
		return $this->listOf("{$path}/appRoleAssignments", [], 'app', fn($a) => [ 'id' => (string)($a['id'] ?? ''), 'app' => (string)($a['resourceDisplayName'] ?? ''),
		    'appObjectId' => (string)($a['resourceId'] ?? ''), 'roleId' => (string)($a['appRoleId'] ?? ''), 'created' => (string)($a['createdDateTime'] ?? '') ]);
	    case 'assignApp':
		$sp = $this->servicePrincipal($value('app'));
		$uid = $this->userId($value('user'));
		$role = $text('role');
		$roleId = '00000000-0000-0000-0000-000000000000';
		if ($role !== '') {
		    $match = array_values(array_filter((array)($sp['appRoles'] ?? []), fn($r) => in_array('User', (array)($r['allowedMemberTypes'] ?? []), true)
			&& (strcasecmp((string)($r['value'] ?? ''), $role) === 0 || strcasecmp((string)($r['displayName'] ?? ''), $role) === 0 || (string)($r['id'] ?? '') === $role)));
		    if (!$match)
			throw new \RuntimeException("Role: {$sp['displayName']} has no role `{$role}` for users");
		    $roleId = (string)$match[0]['id'];
		}
		$a = $this->graph->request('POST', "/servicePrincipals/{$sp['id']}/appRoleAssignedTo", [], [ 'principalId' => $uid, 'resourceId' => (string)$sp['id'], 'appRoleId' => $roleId ]);
		return [ [ 'id' => (string)($a['id'] ?? ''), 'app' => (string)$sp['displayName'], 'roleId' => $roleId, 'user' => $uid ], rawurldecode($ref) . " assigned to {$sp['displayName']}" ];
	    case 'removeApp':
		$sp = $this->servicePrincipal($value('app'));
		$uid = $this->userId($value('user'));
		$removed = 0;
		foreach((array)($this->graph->request('GET', "/users/{$uid}/appRoleAssignments")['value'] ?? []) as $a)
		    if ((string)($a['resourceId'] ?? '') === (string)$sp['id']) {
			$this->graph->request('DELETE', "/users/{$uid}/appRoleAssignments/" . rawurlencode((string)$a['id']));
			$removed++;
		    }
		return [ [ 'app' => (string)$sp['displayName'], 'user' => $uid, 'removed' => $removed ], rawurldecode($ref) . ": {$removed} assignment(s) to {$sp['displayName']} removed" ];
	    case 'methods':
		return $this->listOf("{$path}/authentication/methods", [], 'method', fn($m) => [ 'id' => (string)($m['id'] ?? ''), 'type' => substr((string)($m['@odata.type'] ?? ''), 17),
		    'name' => (string)($m['displayName'] ?? $m['phoneNumber'] ?? $m['emailAddress'] ?? $m['model'] ?? ''), 'created' => (string)($m['createdDateTime'] ?? '') ]);
	    case 'resetMfa':
		$removed = [];
		$errors = [];
		foreach((array)($this->graph->request('GET', "{$path}/authentication/methods")['value'] ?? []) as $m) {
		    $type = (string)($m['@odata.type'] ?? '');
		    if (!isset(self::METHODS[$type]))
			continue;
		    try {
			$this->graph->request('DELETE', "{$path}/authentication/" . self::METHODS[$type] . '/' . rawurlencode((string)$m['id']));
			$removed[] = substr($type, 17);
		    } catch (\RuntimeException $e) {
			//a phone method that is the default one is removed last by Graph's rules: said, the others go on
			$errors[] = substr($type, 17) . ': ' . $e->getMessage();
		    }
		}
		return [ [ 'user' => rawurldecode($ref), 'removed' => $removed, 'errors' => $errors ], rawurldecode($ref) . ': ' . \util\Format::plural(count($removed), 'method') . ' removed' . ($errors ? ', ' . count($errors) . ' not' : '') ];
	    case 'createTap':
		$t = $this->graph->request('POST', "{$path}/authentication/temporaryAccessPassMethods", [], [ 'lifetimeInMinutes' => max(10, min(43200, (int)$this->cfg('minutes', 60))),
		    'isUsableOnce' => (bool)$this->cfg('oneUse', true) ]);
		return [ [ 'user' => rawurldecode($ref), 'pass' => (string)($t['temporaryAccessPass'] ?? ''), 'start' => (string)($t['startDateTime'] ?? ''), 'minutes' => (int)($t['lifetimeInMinutes'] ?? 0),
		    'oneUse' => (bool)($t['isUsableOnce'] ?? true) ], rawurldecode($ref) . ': a temporary access pass for ' . (int)($t['lifetimeInMinutes'] ?? 0) . ' minutes' ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
