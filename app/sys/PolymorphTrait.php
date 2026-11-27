<?php

namespace sys;

trait PolymorphTrait {

    public static function create($data = [], $options = []) {
	$variant = $data['__variant'] ?? $options['object'] ?? $options['objectName'] ?? null;
	if (empty($variant))
	    throw new \ErrorException("empty variant");
	//CLD: 2026-11-27T10:00:00+02:00: a document of the family without __variant (a list of the family's base, e.g. FileEntry) is of the family's default variant, when it has one
	if (empty($data['__variant']) && \V::app()->om->isDefined($variant) && \V::app()->om->hasVariants($variant))
	    $variant = \V::app()->om->defaultVariant($variant) ?? $variant;

	//a stored variant that is not registered (e.g. its plugin is disabled) is created as the class create() was
	//called on (the family's base) instead of failing: lists of the family still load
	if (!\V::app()->om->isDefined($variant)) {
	    \V::app()->log->warn("Polymorph: variant `{$variant}` is not registered; created as " . static::class);
	    return new static([ 'data' => $data, 'options' => $options ]);
	}

	$class = \V::app()->om->getObjectClass($variant);
	//__variant only for a variant: the family's base object (e.g. an empty Credential for permission checks) has none;
	//the object's name is the variant's (its permissions / schema are resolved from it)
	if (\V::app()->om->isVariant($variant))
	    $data['__variant'] = $variant;
	$options['object'] = $variant;
	$options['objectName'] = $variant;
	return new $class(compact('data', 'options'));
    }
}
