<?php

namespace sys;

class User extends \sys\BaseObject {

    public function __construct($config = []) {
	$defaults = [
	    'login.url' => '/site/login',
	];
	$config += $defaults;
	parent::__construct($config);

	\V::app()->hooks->addHook('on:start', [ $this, 'initialize' ], 0); //first
    }

    // Initializes the application component.
    //logins now survive browser restarts through persistent sessions (see Session::setPersistent)
    public function initialize() {
	//init some params
	if ($this->isGuest === null)
	    $this->isGuest = true;
	if ($this->name === null)
	    $this->name = 'guest';
	if ($this->id === null)
	    $this->id = '';

	//refresh userIP in session
	$this->ip_address = \V::app()->request->userIP;

	if (isset(\V::app()->csrf))
	    \V::app()->csrf->getToken(false); // do not refresh token if present


	//here we might have the user already logged-in, run after-login hook
	if (!$this->isGuest)
	    \V::app()->hooks->applyHook('on:after-user-login');
    }

    // Logs out the current user. Remove authentication-related session data.
    public function logout($destroySession=true) {
	if ($this->isGuest)
	    return;

	$_SESSION = [];

	if (\V::app()->var('app.mode') == 'web') {
	    $cookie_params = session_get_cookie_params();
	    $cookie = new \sys\http\Cookie([
		'name' => session_name(),
		'value' => '',
		'expire' => time() - 3600,
		'httpOnly' => $cookie_params['httponly'],
		'domain' => $cookie_params['domain'],
		'path' => $cookie_params['path'],
		'secure' => $cookie_params['secure'],
	    ]);
	    \V::app()->response->addCookie($cookie);
	}

	$this->initialize();

	if(session_status() == PHP_SESSION_ACTIVE) {
	    session_regenerate_id(true);
	    session_commit();
	}
    }

    //CLD: 2026-11-17T22:30:00+02:00: redirects to the sign-in page (login.url), else a 403; the page asked (its path and query, not for an ajax / pjax request) is kept as
    //the return URL (getReturnUrl() after the sign-in; only a path of this site: not `//host`). Response::redirect answers ajax and pjax requests in their own way (X-Redirect / X-Pjax-Redirect)
    public function loginRequired() {
	$request = \V::app()->request;
	$uri = (string)$request->requestUri;
	if (!$request->is('ajax') && !$request->is('pjax') && str_starts_with($uri, '/') && !str_starts_with($uri, '//') && !str_starts_with($uri, '/\\'))
	    $this->return_url = $uri;

    	$url = $this->_config['login.url'];
	if (!empty($url))
	    \V::app()->response->redirect($url);

	\V::app()->error(403, \V::t('Login Required'));
    }

    // Returns the URL that the user should be redirected to after successful login.
    // This property is usually used by the login action. If the login is successful,
    // the action should read this property and use it to redirect the user browser.
    public function getReturnUrl($defaultUrl = null) {
	if (empty($this->return_url))
	    return $defaultUrl;

	$return_url = $this->return_url;
	$this->return_url = null;
	return $return_url;
    }

    public function getLoginUrl() {
    	return $this->_config['login.url'];
    }


    public function setReturnUrl($url = null) {
	if (!empty($url))
	    $this->return_url = $url;
    }



    // The user identity information will be saved in session storage
    // $persistent (the "remember me" choice) replaces $duration; the session itself is persisted instead of an identity cookie
    public function login($identity, $persistent = false) {
	$saveReturnUrl = $this->return_url;

	//new session id on login (prevents session fixation)
	if (session_status() == PHP_SESSION_ACTIVE)
	    session_regenerate_id(true);
	\V::app()->session->setPersistent($persistent);
	//start of the session, for the account's session limits (before on:after-user-login, which checks them)
	\V::app()->session->markLogin();

	foreach($identity->data() as $key => $val) {
	    $this->__set($key, $val);
	}

	if($saveReturnUrl)
	    $this->return_url = $saveReturnUrl;

	$this->isGuest = ($this->id === null);

	if (isset(\V::app()->csrf))
	    \V::app()->csrf->getToken(true); //refresh token

	\V::app()->hooks->applyHook('on:after-user-login');

	return $this->isGuest;
    }

    //permissions
    public function can($itemName, $object = null) {
	if (isset(\V::app()->auth))
	    return \V::app()->auth->checkAccess($this->_id, $itemName, $object);
	return true;
    }

    public function avatar($size, $options = []) {
	switch($size) {
	    case 'lg':
		$size = '3rem';
		$radius = '1.5rem';
		break;
	    default:
	    case 'md':
		$size = '2rem';
		$radius = '1rem';
		break;
	    case 'sm':
		$size = '1.5rem';
		$radius = '0.75rem';
		break;
	}

	$class = $options['class'] ?? '';
	$fullName = \V::app()->html->escape($this->name);

	return "
	<svg role='img' xmlns='http://www.w3.org/2000/svg' xmlns:xlink='http://www.w3.org/1999/xlink' width='{$size}' height='{$size}' viewBox-xx='0 0 {$size} {$size}' version='1.1' style='vertical-align: top' class='{$class}'>
	    <title>{$fullName}</title>
	    <circle fill='var(--bs-orange)' cx='50%' cy='50%' r='{$radius}'/>
	    <text x='50%' y='50%' style='color:var(--text-muted);line-height:1;' alignment-baseline='middle' text-anchor='middle' font-size='{$radius}' font-weight='600' dy='.1em' dominant-baseline='middle' fill='#888c9b'>
		{$this->firstname[0]}{$this->lastname[0]}
	    </text>
	</svg>
	";
    }

    public function &__get($name) {
	$data = \V::app()->session->getState('user');
	$value = $data[$name] ?? null;
	return $value;
    }

    public function __set($name, $value = null) {
	$data = \V::app()->session->getState('user');
	if (empty($data))
	    $data = [];

	if($value === null)
	    unset($data[$name]);
	else
	    $data[$name] = $value;
	\V::app()->session->setState('user', $data);
    }

    public function __isset($name) {
	$data = \V::app()->session->getState('user');
	return isset($data[$name]);
    }

    public function __unset($name) {
	$data = \V::app()->session->getState('user');
	unset($data[$name]);
	\V::app()->session->setState('user', $data);
    }

}