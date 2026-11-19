<?php
//CLD: 2026-11-19T12:00:00+02:00: the end of a Connect started in a popup (app.credentialDialog): the result goes to the dialog on BroadcastChannel 'credential::oauth'
//(same origin; not window.opener: the providers' sign-in pages send Cross-Origin-Opener-Policy, which cuts the popup from its opener), then the popup closes; the text
//stays when the browser does not let it close
$this->pageTitle = $result['ok'] ? 'Connected' : 'Not connected';

echo \util\VString::insert('<div class="container py-5 text-center"><h5>{:title}</h5><p class="text-body-secondary">{:message}</p><p class="small">You can close this window.</p></div>', [
    'title' => \V::app()->html->escape($this->pageTitle),
    'message' => \V::app()->html->escape($result['message']),
]);

\V::app()->cs->addPageScript("
+function() {
    const result = " . \util\JSON::encode([ 'type' => 'credential::oauth' ] + $result) . ";
    if ('BroadcastChannel' in window) {
	const channel = new BroadcastChannel('credential::oauth');
	channel.postMessage(result);
	channel.close();
    }
    window.close();
}();
");
