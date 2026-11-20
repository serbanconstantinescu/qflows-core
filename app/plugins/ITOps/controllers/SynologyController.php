<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: the public endpoint of the Synology notification triggers (/synology/<token>, NodeTriggerSynologyNotification): DSM's custom webhook
    POSTs a notification (JSON or a form; its message in a parameter) with the step's secret in the address (?key=) or as `Authorization: Bearer`. One run of the published
    version per notification of a category chosen, the notification in the step's variable (NodeTriggerSynologyNotification::notification) with received_at; another category:
    answered 200, no run. Refusals (unknown token, secret, not published, errors) as HookController's, logged for the workflow
*/

namespace plugins\ITOps\controllers;

class SynologyController extends \sys\APIController {

    const REFUSED = 'synology.refused';

    //called by DSM: no CSRF token, the URL's token and the secret are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::synology-notification', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$secret = (string)($cfg['secret'] ?? '');
	$key = is_string($req->query['key'] ?? null) ? $req->query['key'] : '';
	if ($key === '' || $secret === '' || !hash_equals($secret, $key))
	    $this->requireBearer($secret);

	//the body as sent (sys\http\Request); too large without a Content-Length (chunked): 413
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$this->requireRunnable($wf, $live);

	$data = json_decode($raw, true);
	if (!is_array($data) || array_is_list($data)) {
	    parse_str($raw, $form);
	    $data = $form && str_contains($raw, '=') ? $form : [];
	}
	$value = \plugins\ITOps\models\node\NodeTriggerSynologyNotification::notification(self::clean($data), mb_substr($raw, 0, 20000));
	$chosen = (array)($cfg['categories'] ?? []);
	if (!in_array($value['category'], $chosen, true)) {
	    $resp->sendJSON([ 'state' => 'ignored', 'category' => $value['category'] ]);
	    return;
	}
	$received = new \MongoDB\BSON\UTCDateTime();
	$value['received_at'] = $received->toDateTime()->format(DATE_ATOM);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'dsm';
	$exe = \models\wf\WfExecution::startFor($live, 'synology-notification', [ 'received_at' => $received, 'vars' => [ $name => $value ] ], $entry->node);
	$exe->log($entry->node, "Synology notification ({$value['category']}): " . mb_substr($value['title'], 0, 200));
	$exe->queue();
	$resp->sendJSON([ 'execution' => (string)$exe->_id, 'state' => 'accepted' ]);
    }
}
