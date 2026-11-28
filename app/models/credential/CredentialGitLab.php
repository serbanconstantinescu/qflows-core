<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: a GitLab account, for the GitLab steps and event trigger (util\vendor\GitLab): gitlab.com or a self-managed GitLab (its address), an access token
    (personal, project or group; encrypted) and a default project (group/project). Test: who the token is (a project's or group's token: its bot user) and its scopes. Provides `gitlab`
*/

namespace models\credential;

class CredentialGitLab extends \models\Credential {
    public static $label = 'GitLab';
    public static $icon = 'bi-gitlab';
    public static $group = 'Development';
    public static $provides = [ 'gitlab' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'baseUrl',
		'type' => 'text',
		'label' => 'Address',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: gitlab.com. A self-managed GitLab: https://gitlab.example.com',
		'validate' => [ [ 'regex', 'pattern' => '^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._-]+)*/?$', 'skipEmpty' => true, 'message' => 'https://gitlab.example.com' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'gitlab.com' ],
	    ],
	    [
		'key' => 'token',
		'type' => 'password',
		'label' => 'Access token',
		'required' => true,
		'encrypt' => true,
		'help' => 'A personal, project or group access token (glpat-…) with the scope api (read_api for reading only), and a role that allows what the steps do (Developer, Maintainer)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'defaultProject',
		'type' => 'text',
		'label' => 'Default project',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Optional: group/project (or its ID), the steps\' when they name none, e.g. acme/website',
		'validate' => [ [ 'regex', 'pattern' => '^([0-9]{1,20}|[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+){1,20})$', 'skipEmpty' => true, 'message' => 'group/project, or its ID' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the certificate',
		'default' => false,
		'help' => 'A self-managed GitLab with a self-signed certificate',
		'ui' => [ 'width' => 6, 'advanced' => true ],
	    ],
	]);
    }

    public function client($aid = null, $timeout = 60) {
	return new \util\vendor\GitLab($this, $aid ?? $this->aid, $timeout);
    }

    public function test() {
	$g = $this->client(null, 20);
	$where = $g->isCloud() ? 'gitlab.com' : \util\Http::shown($g->base());
	try {
	    $me = $g->request('GET', '/user');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$scopes = '';
	try {
	    $scopes = implode(', ', array_map('strval', (array)($g->request('GET', '/personal_access_tokens/self')['scopes'] ?? [])));
	} catch (\RuntimeException $e) {
	    //an older GitLab: no scopes said
	}
	return "connected to {$where} as " . ($me['username'] ?? '?') . (!empty($me['bot']) ? ' (a bot: a project or group token)' : '') . ($scopes !== '' ? " (scopes: {$scopes})" : '');
    }
}
