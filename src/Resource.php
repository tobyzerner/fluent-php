<?php

namespace Tobyz\Fluent;

use Tobyz\Fluent\Error;

/**
 * Fluent Resource is a structure storing parsed localization entries.
 */
class Resource
{
    public $body;

    public function __construct($source)
    {
        $this->body = $this->parse($source);
    }

    private function parse($source)
    {
        $parser = new Parser($source);

        return $parser->parse();
    }
}
