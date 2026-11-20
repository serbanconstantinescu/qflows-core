<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology notification - starts the workflow when DSM sends a notification (Control Panel > Notification > Webhooks, a custom webhook:
    POST, JSON, its message in a body parameter, to the step's address /synology/<token>?key=<secret>; SynologyController::receive). Both are made when the step is added;
    the step shows how to set the webhook up (`setup`, read-only). DSM's messages are text: each is given a category from its words (English DSM messages; categorize()):
	storage, disk, backup, security, power, update, test (DSM's test message), other
    and `problem` (its words say something failed or is wrong). Only the categories chosen start runs (the others answered 200 all the same). The variable: { text, title
    (its first line), category, problem, fields (the body's other parameters), received_at }
*/

namespace plugins\ITOps\models\node;

class NodeTriggerSynologyNotification extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'synology-notification';
    public static $label = 'Synology notification';
    public static $icon = 'bi-bell';
    public static $inputs = 0;
    public static $outputs = 1;

    const CATEGORIES = [
	'storage' => 'Storage (volumes, pools, space)',
	'disk' => 'Drives (health, S.M.A.R.T.)',
	'backup' => 'Backup (Hyper Backup, USB Copy, replication)',
	'security' => 'Security (sign-ins, blocked addresses, certificates)',
	'power' => 'Power (start, shutdown, UPS, fans, temperature)',
	'update' => 'Updates (DSM, packages)',
	'other' => 'Other',
	'test' => 'DSM\'s test message',
    ];
    //the words of each category, in this order (the first that matches)
    const WORDS = [
	'test' => '/\btest (message|notification)\b/i',
	'backup' => '/hyper ?backup|\bbackup\b|usb copy|snapshot replication|active backup|integrity check|\brestor(e|ation)\b|\bbacked up\b/i',
	'disk' => '/\bdrives?\b|\bdisks?\b|\bhdd\b|\bssd\b|\bnvme\b|s\.m\.a\.r\.t|\bsmart\b|bad sectors?|hot ?spare|lifespan|\bm\.2\b/i',
	'storage' => '/\bvolumes?\b|storage pool|\braid\b|\bshr\b|degraded|crashed|free space|running out of|capacity|\bquota\b|file system|\bscrub/i',
	'security' => '/sign(ed)?[- ]?in|log(ged)?[- ]?in|\blogin\b|blocked|auto block|password|security advisor|antivirus|malware|certificate|2-step|two-factor|brute/i',
	'update' => '/\bupdates?\b|\bupgrade|new version|available for download/i',
	'power' => '/\bboot(ed)?\b|shut ?down|restart|reboot|\bpower\b|\bups\b|\bfans?\b|temperature|overheat/i',
    ];
    const PROBLEM = '/fail|error|degrad|crash|abnormal|warning|critical|blocked|unable|cannot|could not|\blost\b|interrupted|overheat|bad sector|\bfull\b|running out|not respond|disconnected|stopped unexpectedly/i';

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	$secret = bin2hex(random_bytes(24));
	$url = \V::app()->createAbsoluteUrl('/synology/' . $token) . '?key=' . $secret;
	return [ 'token' => $token, 'url' => $url, 'secret' => $secret, 'setup' => self::setup($url) ];
    }

    //brought in by an import (util\Transfer): the address and setup made again from the step's token and secret (the address holds both)
    public static function forTransfer(array $config) {
	if (!preg_match('/^[a-f0-9]{48}$/', (string)($config['secret'] ?? '')))
	    $config['secret'] = bin2hex(random_bytes(24));
	$url = \V::app()->createAbsoluteUrl('/synology/' . (string)($config['token'] ?? '')) . '?key=' . $config['secret'];
	return [ 'url' => $url, 'setup' => self::setup($url) ] + $config;
    }

    //how to set the webhook up in DSM (7.x)
    public static function setup($url) {
	return "In DSM: Control Panel > Notification > Webhooks > Add\n"
	    . "  Provider: Custom\n"
	    . "  Rule: the events to send (All, or Custom)\n"
	    . "  Webhook URL: {$url}\n"
	    . "Next:\n"
	    . "  HTTP method: POST\n"
	    . "  Content type: application/json\n"
	    . "  HTTP body: a parameter named text, its type Message content\n"
	    . "Apply, then Send test message (the category test: starts a run only when chosen below)";
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (!preg_match('/^[a-f0-9]{48}$/', (string)$this->cfg('secret', ''))) {
	    $this->errors('config', 'missing secret (remove the step and add it again)');
	    $valid = false;
	}
	if (!array_intersect(array_keys(self::CATEGORIES), (array)$this->cfg('categories', []))) {
	    $this->errors('config', 'Synology notification: choose at least one category');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Webhook URL',
		'perm:update' => 'nobody',
		'help' => 'Where DSM sends its notifications (with the secret key in it); active once the workflow is published',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'setup',
		'type' => 'textarea',
		'label' => 'DSM setup',
		'perm:update' => 'nobody',
		'help' => 'The webhook to add in DSM, with this address',
		'ui' => [ 'rows' => 10, 'spellcheck' => false ],
	    ],
	    [
		'key' => 'secret',
		'type' => 'text',
		'label' => 'Secret',
		'perm:update' => 'nobody',
		'help' => 'In the address (?key=), or sent as Authorization: Bearer <secret>',
		'ui' => [ 'type' => 'password', 'advanced' => true ],
	    ],
	    [
		'key' => 'categories',
		'type' => 'select',
		'multiple' => true,
		'label' => 'Notifications',
		'required' => true,
		'default' => array_values(array_diff(array_keys(self::CATEGORIES), [ 'test' ])),
		'choices' => self::CATEGORIES,
		'help' => 'Their category, from the message\'s words (English DSM messages; others: Other)',
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Notification variable',
		'required' => true,
		'default' => 'dsm',
		'help' => 'e.g. {{ dsm.text }}, {{ dsm.category }}, {{ dsm.problem }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'dsm') : 'dsm';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the notification DSM sent', 'fields' => [
	    'text' => [ 'type' => 'string', 'info' => 'the message, as DSM wrote it' ], 'title' => [ 'type' => 'string', 'info' => 'its first line' ],
	    'category' => [ 'type' => 'string', 'info' => implode(', ', array_keys(self::CATEGORIES)) ],
	    'problem' => [ 'type' => 'boolean', 'info' => 'its words say something failed or is wrong' ],
	    'fields' => [ 'type' => 'object', 'info' => 'the body\'s other parameters' ], 'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601 (added by qflows)' ],
	] ] ] : [];
    }

    //[ category, problem ] of a message
    public static function categorize($text) {
	$category = 'other';
	foreach(self::WORDS as $c => $re)
	    if (preg_match($re, (string)$text)) {
		$category = $c;
		break;
	    }
	return [ $category, $category !== 'test' && (bool)preg_match(self::PROBLEM, (string)$text) ];
    }

    //the notification of a body: its text (text, message, content, msg, or the first text value), the other fields
    public static function notification(array $body, $raw) {
	$text = null;
	foreach([ 'text', 'message', 'content', 'msg', 'body' ] as $k)
	    if (isset($body[$k]) && is_string($body[$k])) {
		$text = $body[$k];
		unset($body[$k]);
		break;
	    }
	if ($text === null)
	    foreach($body as $k => $v)
		if (is_string($v) && trim($v) !== '') {
		    $text = $v;
		    unset($body[$k]);
		    break;
		}
	$text = trim((string)($text ?? (!$body ? $raw : '')));
	$text = mb_substr($text, 0, 20000);
	[ $category, $problem ] = self::categorize($text);
	return [ 'text' => $text, 'title' => mb_substr(trim((string)strtok($text, "\n")), 0, 300), 'category' => $category, 'problem' => $problem, 'fields' => $body ];
    }
}
