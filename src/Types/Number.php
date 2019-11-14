<?php

namespace Tobyz\Fluent\Types;

use Exception;
use Tobyz\Fluent\Scope;
use Tobyz\Fluent\Intl;

class Number extends Type
{
    public $opts;

    public function __construct($value, array $opts = [])
    {
        parent::__construct($value);

        $this->opts = $opts;
    }

    public function toString(Scope $scope)
    {
        try {
            $nf = $scope->memoizeIntlObject(Intl\NumberFormat::class, $this->opts);
            return $nf->format($this->value);
        } catch (Exception $e) {
            $scope->reportError($e);
            return $this->value;
        }
    }
}
