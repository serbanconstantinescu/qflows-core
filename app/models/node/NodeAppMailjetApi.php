<?php

/*
    CLD: 2026-11-15T16:00:00+02:00: Mailjet API - any request of Mailjet's APIs (a Mailjet credential, util\Mailjet): what the other Mailjet steps do not have (senders, campaigns,
    statistics, parse routes, webhooks - eventcallbackurl -, …): the method, the path with its version (/v3/REST/…, /v3.1/…, /v4/…), the query and the JSON body. The result:
    Mailjet's answer
*/

namespace models\node;

class NodeAppMailjetApi extends NodeAppEmailService {
    public static $name = 'mailjet-api';
    public static $label = 'Mailjet API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'mailjet';
    public static $capability = 'mailjet';
    public static $credentialLabel = 'Mailjet credential';

    const OPERATIONS = [ 'request' => 'A request' ];
    const WRITES = [ 'request' ];

    protected static function operationFields() {
	return self::apiFields('With its version, e.g. /v3/REST/sender or /v3/REST/statcounters', '/v3/REST/…');
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
	if (!preg_match('~^/v[34](\.1)?/~', $path))
	    throw new \RuntimeException('Path: with the API\'s version, e.g. /v3/REST/sender');
	return [ $this->client->request($method, $path, $query, $body), "{$method} {$path}" ];
    }
}
