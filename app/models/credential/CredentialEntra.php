<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: a Microsoft Entra ID tenant, for the Entra ID steps and change trigger (util\Entra, Microsoft Graph, app-only): the tenant's ID, an app
    registration's client ID and its client secret or certificate (the certificate's PEM and its private key, encrypted), the national cloud. What the steps may do is what the
    app's Graph application permissions (consented by an administrator) and its directory roles allow. Test: the token, then the organization's name (Organization.Read.All).
    Provides `entra`
*/

namespace models\credential;

class CredentialEntra extends \models\Credential {
    public static $label = 'Microsoft Entra ID';
    public static $icon = 'bi-person-badge';
    public static $group = 'Directories';
    public static $provides = [ 'entra' ];
    public static $testable = true;

    const AUTHS = [ 'secret' => 'A client secret', 'certificate' => 'A certificate' ];
    const CLOUDS = [ 'global' => 'Global (microsoft.com)', 'usgov' => 'US Government', 'china' => 'China (21Vianet)' ];

    public function getSchema() {
	$cert = [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'certificate' ];
	return array_merge(parent::getSchema(), [
	    [ 'key' => 'tenantId', 'type' => 'text', 'label' => 'Tenant ID', 'required' => true, 'default' => '', 'transform' => 'trim',
		'help' => 'Entra admin center > Overview: Tenant ID (or the tenant\'s domain, e.g. acme.onmicrosoft.com)',
		'validate' => [ [ 'regex', 'pattern' => '^([0-9a-fA-F-]{36}|[A-Za-z0-9.-]+\.[A-Za-z]{2,})$', 'skipEmpty' => true, 'message' => 'A GUID, or the tenant\'s domain' ] ], 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'clientId', 'type' => 'text', 'label' => 'Application (client) ID', 'required' => true, 'default' => '', 'transform' => 'trim',
		'help' => 'App registrations > the app > Overview', 'validate' => [ [ 'regex', 'pattern' => '^[0-9a-fA-F-]{36}$', 'skipEmpty' => true, 'message' => 'A GUID' ] ], 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'auth', 'type' => 'select', 'label' => 'Sign in with', 'required' => true, 'default' => 'secret', 'choices' => self::AUTHS, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'cloud', 'type' => 'select', 'label' => 'Cloud', 'required' => true, 'default' => 'global', 'choices' => self::CLOUDS, 'ui' => [ 'width' => 6, 'advanced' => true ] ],
	    [ 'key' => 'clientSecret', 'type' => 'password', 'label' => 'Client secret', 'encrypt' => true, 'requiredIf' => [ 'if' => 'auth', 'in' => [ 'secret' ] ],
		'help' => 'Certificates & secrets > New client secret: its Value (shown once)', 'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'secret' ] ] ],
	    [ 'key' => 'certificate', 'type' => 'textarea', 'label' => 'Certificate', 'requiredIf' => [ 'if' => 'auth', 'in' => [ 'certificate' ] ],
		'help' => 'The certificate uploaded to the app (Certificates & secrets > Certificates), PEM: -----BEGIN CERTIFICATE----- …', 'ui' => [ 'rows' => 4, 'showIf' => $cert ] ],
	    [ 'key' => 'privateKey', 'type' => 'textarea', 'label' => 'Private key', 'encrypt' => true, 'requiredIf' => [ 'if' => 'auth', 'in' => [ 'certificate' ] ],
		'help' => 'The certificate\'s private key, PEM (unencrypted)', 'ui' => [ 'rows' => 4, 'showIf' => $cert ] ],
	]);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (($this->auth ?? 'secret') === 'certificate') {
	    if (trim((string)$this->certificate) !== '' && @openssl_x509_read(trim((string)$this->certificate)) === false) {
		$this->errors('certificate', 'not a PEM certificate');
		$valid = false;
	    }
	    if (trim((string)$this->privateKey) !== '' && openssl_pkey_get_private(trim((string)$this->privateKey)) === false) {
		$this->errors('privateKey', 'not a PEM private key (unencrypted)');
		$valid = false;
	    }
	}
	return $valid;
    }

    public function client($aid = null, $timeout = 60) {
	return new \util\Entra($this, $aid ?? $this->aid, $timeout);
    }

    public function test() {
	$g = $this->client(null, 20);
	try {
	    $g->token(true);
	    $org = $g->request('GET', '/organization', [ '$select' => 'displayName,verifiedDomains' ], null, [ 'ok' => [ 403 ] ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$o = (array)($org['value'][0] ?? []);
	if (!$o)
	    return 'signed in; the organization\'s name cannot be read (the permission Organization.Read.All is not granted)';
	$domain = '';
	foreach((array)($o['verifiedDomains'] ?? []) as $d)
	    if (!empty($d['isDefault']))
		$domain = (string)$d['name'];
	return 'connected to ' . ($o['displayName'] ?? '?') . ($domain !== '' ? " ({$domain})" : '');
    }
}
