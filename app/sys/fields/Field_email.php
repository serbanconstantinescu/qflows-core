<?php

namespace sys\fields;

class Field_email extends Field_text {

    public $icon = 'bi bi-at';
    public $name = 'Email';
    public $ops = [
	//the conditions' operators (util\Conditions): one vocabulary for every type (eq ne gt gte lt lte contains startsWith in empty notEmpty), labels per type
	'eval' => self::EVAL_TEXT,
	'set' => [ 'set' => 'Set' ],
    ];

    //CLD: 2026-11-17T22:30:00+02:00: no cast() of its own: Field_text's, so the field's `transform` applies (the users' emails: 'tolower')

    public function bindTo(&$object) {
	parent::bindTo($object);

	//add email validation
	//\V::app()->log->debug('Field_email::bindTo: adding email validation to object:' . $object->getObjectName());
	if (empty($object->validates[$this->key]))
	    $object->validates[$this->key] = [];
	$object->validates[$this->key][] = [
	    'email',
	    'message' => 'invalid email address',
	    'skipEmpty' => empty($this->{'prop:required'})
	];
    }
}