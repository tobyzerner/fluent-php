<?php

namespace Tobyz\Fluent;


class Scope
{
    /** The bundle for which the given resolution is happening. */
    public $bundle;

    /** The list of errors collected while resolving. */
    public $errors;

    /** A dict of developer-provided variables. */
    public $args;

    /** The Set of patterns already encountered during this resolution.
     * Used to detect and prevent cyclic resolutions. */
    public $dirty = [];

    /** A dict of parameters passed to a TermReference. */
    public $params;

    /** The running count of placeables resolved so far. Used to detect the
     * Billion Laughs and Quadratic Blowup attacks. */
    public $placeables = 0;

    public function __construct(
        Bundle $bundle,
        array &$errors = null,
        array $args = []
    ) {
        $this->bundle = $bundle;
        $this->errors = &$errors;
        $this->args = $args;
    }

    public function cloneForTermReference(array $args)
    {
        return new static($this->bundle, $this->errors, $args, true, $this->dirty);
    }

    public function reportError($error)
    {
        if (! $this->errors) {
            throw $error;
        }
        $this->errors[] = $error;
    }

    public function memoizeIntlObject(string $class, array $opts = [])
    {
        $cache = $this->bundle->intls[$class] ?? null;
        if (! $cache) {
            $cache = [];
            $this->bundle->intls[$class] = $cache;
        }

        $id = json_encode($opts);
        if (! isset($cache[$id])) {
            $cache[$id] = new $class($this->bundle->locales, $opts);
        }

        return $cache[$id];
    }
}
