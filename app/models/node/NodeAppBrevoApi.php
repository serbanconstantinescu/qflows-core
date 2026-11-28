<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Brevo API - any request of Brevo's API v3 (a Brevo credential, util\vendor\Brevo): what the other Brevo steps do not have (campaigns, SMS, WhatsApp,
    senders and domains, webhooks, CRM deals, …): the method, the path below /v3, the query and the JSON body. The result: Brevo's answer
*/

namespace models\node;

class NodeAppBrevoApi extends NodeAppEmailService {
    public static $name = 'brevo-api';
    public static $label = 'Brevo API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'brevo';
    public static $capability = 'brevo';
    public static $credentialLabel = 'Brevo credential';

    const OPERATIONS = [ 'request' => 'A request' ];
    const WRITES = [ 'request' ];

    protected static function operationFields() {
	return self::apiFields('Below /v3, e.g. /senders or /webhooks', '/senders');
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
	$path = preg_replace('~^/v3(?=/)~', '', $path);
	return [ $this->client->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
