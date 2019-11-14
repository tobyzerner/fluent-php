<?php

namespace Tobyz\Fluent\Types;

use Tobyz\Fluent\Scope;

abstract class Type
{
    public $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function valueOf()
    {
        return $this->value;
    }

    abstract public function toString(Scope $scope);
}

