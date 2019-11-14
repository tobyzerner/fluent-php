<?php

namespace Tobyz\Fluent\Types;

use Tobyz\Fluent\Scope;

class None extends Type
{
    public function __construct(string $value = '???')
    {
        parent::__construct($value);
    }

    public function toString(Scope $scope)
    {
        return "{{$this->value}}";
    }
}
