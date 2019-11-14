<?php

namespace Tobyz\Fluent;

use InvalidArgumentException;

/**
 * @overview
 *
 * The FTL resolver ships with a number of functions built-in.
 *
 * Each function take two arguments:
 *   - args - an array of positional args
 *   - opts - an object of key-value args
 *
 * Arguments to functions are guaranteed to already be instances of
 * `FluentType`.  Functions must return `FluentType` objects as well.
 */

class Builtins
{
    private static function merge(array $argopts, array $opts): array
    {
        return array_merge($argopts, static::values($opts));
    }

    private static function values(array $opts): array
    {
        $unwrapped = [];
        foreach ($opts as $name => $opt) {
            $unwrapped[$name] = is_string($opt) ? $opt : $opt->valueOf();
        }
        return $unwrapped;
    }

    public static function NUMBER(array $args, array $opts)
    {
        $arg = $args[0];

        if ($arg instanceof Types\None) {
            return new Types\None('NUMBER('.$arg->valueOf().')');
        }

        $value = is_string($arg) ? $arg : $arg->valueOf();
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid argument to NUMBER");
        }

        return new Types\Number($value, static::merge($arg->opts, $opts));
    }

    public static function DATETIME(array $args, array $opts)
    {
        $arg = $args[0];

        if ($arg instanceof Types\None) {
            return new Types\None('DATETIME('.$arg->valueOf().')');
        }

        $value = is_string($arg) ? $arg : $arg->valueOf();
        if (! is_numeric($value) && ! $value instanceof \DateTime) {
            throw new InvalidArgumentException("Invalid argument to DATETIME");
        }

        return new Types\DateTime($value, static::merge($arg->opts, $opts));
    }
}
