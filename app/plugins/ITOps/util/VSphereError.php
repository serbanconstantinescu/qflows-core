<?php

/*
    an error answer of vCenter (plugins\ITOps\util\VSphere): its type (REST error_type, e.g. ALREADY_IN_DESIRED_STATE; VI/JSON fault, e.g. InvalidPowerState)
    and HTTP status, so a step can take some as done already
*/

namespace plugins\ITOps\util;

class VSphereError extends \RuntimeException {
    public $type;
    public $status;

    public function __construct($message, $type, $status) {
	parent::__construct($message);
	$this->type = (string)$type;
	$this->status = (int)$status;
    }
}
