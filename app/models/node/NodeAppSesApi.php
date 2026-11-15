<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Amazon SES API - any request of Amazon SES's API v2 (an Amazon SES credential, util\AmazonSes, signed): what the other Amazon SES steps do not
    have (configuration sets and their event destinations, contact lists, dedicated IPs, bulk email, the deliverability dashboard, …): the method, the path below /v2/email, the
    query and the JSON body. The result: SES's answer
*/

namespace models\node;

class NodeAppSesApi extends NodeAppEmailService {
    public static $name = 'ses-api';
    public static $label = 'Amazon SES API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'ses';
    public static $capability = 'amazonses';
    public static $credentialLabel = 'Amazon SES credential';

    const OPERATIONS = [ 'request' => 'A request' ];
    const WRITES = [ 'request' ];

    protected static function operationFields() {
	return self::apiFields('Below /v2/email, e.g. /configuration-sets or /contact-lists', '/configuration-sets');
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
	$path = '/v2/email' . preg_replace('~^/v2/email(?=/|$)~', '', $path);
	return [ $this->client->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
