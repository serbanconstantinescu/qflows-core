<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: a Slack workspace, for the Slack steps and message trigger (util\vendor\Slack): the bot token of a Slack app made for it (api.slack.com/apps > Create New
    App > From a manifest: the manifest of the docs page Slack; Install to Workspace; OAuth & Permissions > Bot User OAuth Token, xoxb-…) and the app's signing secret
    (Basic Information > App Credentials), which the message trigger checks Slack's events with; both encrypted. The steps act as the app's bot user: what its scopes allow,
    in the channels it was invited to. Test: the workspace and the bot (auth.test). Provides `slack`
*/

namespace models\credential;

class CredentialSlack extends \models\Credential {
    public static $label = 'Slack';
    public static $icon = 'bi-slack';
    public static $group = 'Messaging';
    public static $provides = [ 'slack' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'botToken',
		'type' => 'password',
		'label' => 'Bot token',
		'required' => true,
		'encrypt' => true,
		'help' => 'The app\'s OAuth & Permissions > Bot User OAuth Token (xoxb-…), once the app is installed to the workspace',
		'validate' => [ [ 'regex', 'pattern' => '^\s*xoxb-[A-Za-z0-9-]+\s*$', 'skipEmpty' => true, 'message' => 'A bot token starts with xoxb-' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'signingSecret',
		'type' => 'password',
		'label' => 'Signing secret',
		'encrypt' => true,
		'help' => 'The app\'s Basic Information > App Credentials > Signing Secret: the Slack message trigger needs it to receive events',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\vendor\Slack($this, $timeout);
    }

    //the bot of the token: auth.test's { user_id, user, team, team_id, url, bot_id }
    public function identity($timeout = 20) {
	return $this->client($timeout)->call('auth.test');
    }

    public function test() {
	try {
	    $me = $this->identity();
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to ' . ($me['team'] ?? '?') . (!empty($me['url']) ? ' (' . rtrim($me['url'], '/') . ')' : '') . ' as ' . ($me['user'] ?? '?')
	    . (trim((string)$this->signingSecret) === '' ? '; no signing secret: the message trigger can only poll' : '');
    }
}
