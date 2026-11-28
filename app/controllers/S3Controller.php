<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: the public endpoint of the S3 object trigger receiving event notifications (/s3/<token>, NodeTriggerS3, Receive: S3 event notifications):
    Amazon SNS POSTs JSON (Content-Type text/plain), signed (util\vendor\Sns::verify), of the step's topic only:
	SubscriptionConfirmation  confirmed (its SubscribeURL), when the subscription is made
	Notification              its Message: S3's event ({ Records: [ … ] }; s3:TestEvent ignored): a run per object of the bucket, prefix and names (or one with them all);
	                          each SNS message once
	UnsubscribeConfirmation   nothing to do
    Anything else is answered 200 and starts nothing. Refusals (unknown token, signature, another topic, not published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerS3;

class S3Controller extends \sys\APIController {

    const REFUSED = 's3.refused';

    //called by SNS: no CSRF token, the URL's token, the signature and the topic are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::s3', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$m = json_decode($raw, true);
	if (!is_array($m))
	    $this->refuse(400, 'Not JSON');
	if (($cfg['receive'] ?? 'poll') !== 'sns')
	    $this->refuse(409, 'The step does not receive event notifications (Receive: S3 event notifications)');
	$why = \util\vendor\Sns::verify($m);
	if ($why !== '')
	    $this->refuse(401, "Unauthorized ({$why})");
	if ((string)($m['TopicArn'] ?? '') !== trim((string)($cfg['topicArn'] ?? '')))
	    $this->refuse(403, 'Another topic than the step\'s Topic ARN');
	$this->requireRunnable($wf, $live);

	$type = (string)$m['Type'];
	if ($type === 'SubscriptionConfirmation') {
	    try {
		\util\vendor\Sns::confirm($m);
	    } catch (\RuntimeException $e) {
		$this->refuse(502, $e->getMessage());
	    }
	    $resp->sendJSON([ 'state' => 'confirmed' ]);
	    return;
	}
	if ($type !== 'Notification') {
	    $resp->sendJSON([ 'state' => 'ignored', 'type' => $type ]);
	    return;
	}
	$event = json_decode((string)($m['Message'] ?? ''), true);
	if (!is_array($event) || ($event['Event'] ?? '') === 's3:TestEvent') {
	    $resp->sendJSON([ 'state' => 'ignored', 'event' => is_array($event) ? (string)($event['Event'] ?? '') : 'not S3\'s' ]);
	    return;
	}
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 's3');
	if (!$cred)
	    $this->refuse(409, 'The trigger\'s S3 credential is no longer available');
	$objects = NodeTriggerS3::objectsOfEvent($cfg, NodeTriggerS3::bucketOf($cfg, $cred), $event);
	if (!$objects) {
	    $resp->sendJSON([ 'state' => 'ignored', 'records' => count((array)($event['Records'] ?? [])) ]);
	    return;
	}
	if (NodeTriggerS3::seenMessage($wf, $entry->node, (string)($m['MessageId'] ?? ''))) {
	    $resp->sendJSON([ 'state' => 'duplicate' ]);
	    return;
	}
	$started = NodeTriggerS3::startRuns($live, $node, array_map(fn($o) => self::clean($o), $objects));
	$resp->sendJSON([ 'executions' => array_map(fn($x) => (string)$x->_id, $started), 'state' => 'accepted' ]);
    }
}
