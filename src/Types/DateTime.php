<?php

namespace Tobyz\Fluent\Types;

use Exception;
use Tobyz\Fluent\Scope;
use Tobyz\Fluent\Intl;

class DateTime extends Type
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
            $dtf = $scope->memoizeIntlObject(Intl\DateTimeFormat::class, $this->opts);
            return $dtf->format($this->value);
        } catch (Exception $e) {
            $scope->reportError($e);
            return (new \DateTime($this->value))->format(\DateTime::ISO8601);
        }
    }
}
