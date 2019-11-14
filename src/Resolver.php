<?php

namespace Tobyz\Fluent;

use Exception;
use RangeException;
use RuntimeException;
use DateTime;

/**
 * @overview
 *
 * The role of the Fluent resolver is to format a `Pattern` to an instance of
 * `FluentType`. For performance reasons, primitive strings are considered such
 * instances, too.
 *
 * Translations can contain references to other messages or variables,
 * conditional logic in form of select expressions, traits which describe their
 * grammatical features, and can use Fluent builtins which make use of the
 * `Intl` formatters to format numbers and dates into the bundle's languages.
 * See the documentation of the Fluent syntax for more information.
 *
 * In case of errors the resolver will try to salvage as much of the
 * translation as possible. In rare situations where the resolver didn't know
 * how to recover from an error it will return an instance of `FluentNone`.
 *
 * All expressions resolve to an instance of `FluentType`. The caller should
 * use the `toString` method to convert the instance to a native value.
 *
 * Functions in this file pass around an instance of the `Scope` class, which
 * stores the data required for successful resolution and error recovery.
 */

class Resolver
{
    // Prevent expansion of too long placeables.
    const MAX_PLACEABLE_LENGTH = 2500;

    // Unicode bidi isolation characters.
    const FSI = "\u{2068}";
    const PDI = "\u{2069}";

    /**
     * Helper: match a variant key to the given selector.
     */
    private static function match(Scope $scope, $selector, $key)
    {
        if (is_string($key) && $key === $selector) {
            // Both are strings.
            return true;
        }

        // XXX Consider comparing options too, e.g. minimumFractionDigits.
        if ($key instanceof Types\Number
            && $selector instanceof Types\Number
            && $key->value === $selector->value) {
            return true;
        }

        if ($selector instanceof Types\Number && is_string($key)) {
            $category = $scope
                ->memoizeIntlObject(Intl\PluralRules::class, $selector->opts)
                ->select($selector->value);
            if ($key === $category) {
              return true;
            }
        }

        return false;
    }

    /**
     * Helper: resolve the default variant from a list of variants.
     */
    private static function getDefault(Scope $scope, array $variants, string $star)
    {
        if (isset($variants[$star])) {
            return static::resolvePattern($scope, $variants[$star]['value']);
        }

        $scope->reportError(new RangeException("No default"));
        return new Types\None();
    }

    /**
     * Helper: resolve arguments to a call expression.
     */
    private static function getArguments(Scope $scope, array $args)
    {
        $positional = [];
        $named = [];

        foreach ($args as $arg) {
            if ($arg['type'] === "narg") {
                $named[$arg['name']] = static::resolveExpression($scope, $arg['value']);
            } else {
                $positional[] = static::resolveExpression($scope, $arg);
            }
        }

        return [$positional, $named];
    }

    /**
     * Resolve an expression to a Fluent type.
     */
    private static function resolveExpression(Scope $scope, array $expr)
    {
        switch ($expr['type']) {
            case "str":
                return $expr['value'];
            case "num":
                return new Types\Number($expr['value'], [
                    'minimumFractionDigits' => $expr['precision'],
                ]);
            case "var":
                return static::variableReference($scope, $expr);
            case "mesg":
                return static::messageReference($scope, $expr);
            case "term":
                return static::termReference($scope, $expr);
            case "func":
                return static::functionReference($scope, $expr);
            case "select":
                return static::selectExpression($scope, $expr);
            default:
                return new Types\None();
        }
    }

    /**
     * Resolve a reference to a variable.
     */
    private static function variableReference(Scope $scope, array $expr)
    {
        $name = $expr['name'];

        if (! isset($scope->args[$name])) {
            if ($scope->insideTermReference === false) {
                $scope->reportError(new RuntimeException("Unknown variable: $name"));
            }
            return new Types\None($name);
        }

        $arg = $scope->args[$name];

        // Return early if the argument already is an instance of FluentType.
        if ($arg instanceof Types\Type || is_string($arg)) {
            return $arg;
        }

        // Convert the argument to a Fluent type.
        if (is_numeric($arg)) {
            return new Types\Number($arg);
        }
        if ($arg instanceof DateTime) {
            return new Types\DateTime($arg);
        }

        $scope->reportError(
            new RuntimeException("Variable type not supported: $name")
        );
        
        return new Types\None("$name");
    }

    /**
     * Resolve a reference to another message.
     */
    private static function messageReference(Scope $scope, array $expr)
    {
        $name = $expr['name'];
        $attr = $expr['attr'] ?? null;

        $message = $scope->bundle->getMessage($name);
        if (! $message) {
            $scope->reportError(new RuntimeException("Unknown message: $name"));
            return new Types\None($name);
        }

        if ($attr) {
            $attribute = $message['attributes'][$attr] ?? null;
            if ($attribute) {
                return static::resolvePattern($scope, $attribute);
            }
            $scope->reportError(new RuntimeException("Unknown attribute: $attr"));
            return new Types\None("$name.$attr");
        }

        if ($message['value']) {
            return static::resolvePattern($scope, $message['value']);
        }

        $scope->reportError(new RuntimeException("No value: $name"));
        return new Types\None($name);
    }

    /**
     * Resolve a call to a Term with key-value arguments.
     */
    private static function termReference(Scope $scope, array $expr)
    {
        $name = $expr['name'];
        $attr = $expr['attr'] ?? null;
        $args = $expr['args'] ?? null;

        $id = "-$name";
        $term = $scope->bundle->terms[$id];
        if (! $term) {
            $scope->reportError(new RuntimeException("Unknown term: $id"));
            return new Types\None($id);
        }

        // Every TermReference has its own variables.
        [, $params] = static::getArguments($scope, $args);
        $local = $scope->cloneForTermReference($params);

        if ($attr) {
            $attribute = $term['attributes'][$attr] ?? null;
            if ($attribute) {
                return static::resolvePattern($local, $attribute);
            }
            $scope->reportError(new RuntimeException("Unknown attribute: $attr"));
            return new Types\None("$id.$attr");
        }

        return static::resolvePattern($local, $term['value']);
    }

    /**
     * Resolve a call to a Function with positional and key-value arguments.
     */
    private static function functionReference(Scope $scope, array $expr)
    {
        $name = $expr['name'];
        $args = $expr['args'] ?? null;

        // Some functions are built-in. Others may be provided by the runtime via
        // the `FluentBundle` constructor.
        $func = $scope->bundle->functions[$name] ?? (method_exists(Builtins::class, $name) ? [Builtins::class, $name] : null);
        if (! $func) {
            $scope->reportError(new RuntimeException("Unknown function: $name()"));
            return new Types\None("$name()");
        }

        if (! is_callable($func)) {
            $scope->reportError(new RuntimeException("Function $name() is not callable"));
            return new Types\None("$name()");
        }

        try {
            return $func(...static::getArguments($scope, $args));
        } catch (Exception $e) {
            $scope->reportError($e);
            return new Types\None("$name()");
        }
    }

    /**
     * Resolve a select expression to the member object.
     */
    private static function selectExpression(Scope $scope, array $expr)
    {
        $selector = $expr['selector'];
        $variants = $expr['variants'];
        $star = $expr['star'];

        $sel = static::resolveExpression($scope, $selector);
        if ($sel instanceof Types\None) {
            return static::getDefault($scope, $variants, $star);
        }

        // Match the selector against keys of each variant, in order.
        foreach ($variants as $variant) {
            $key = static::resolveExpression($scope, $variant['key']);
            if (static::match($scope, $sel, $key)) {
                return static::resolvePattern($scope, $variant['value']);
            }
        }

        return static::getDefault($scope, $variants, $star);
    }

    public static function resolveComplexPattern(Scope $scope, $ptn)
    {
        if (in_array($ptn, $scope->dirty)) {
            $scope->reportError(new RangeException("Cyclic reference"));
            return new Types\None();
        }

        // Tag the pattern as dirty for the purpose of the current resolution.
        $scope->dirty[] = $ptn;
        $result = [];

        // Wrap interpolations with Directional Isolate Formatting characters
        // only when the pattern has more than one element.
        $useIsolating = $scope->bundle->useIsolating && count($ptn) > 1;

        foreach ($ptn as $elem) {
            if (is_string($elem)) {
                $result[] = ($scope->bundle->transform)($elem);
                continue;
            }

            $value = static::resolveExpression($scope, $elem);
            $part = is_string($value) ? $value : $value->toString($scope);

            if ($useIsolating) {
                $result[] = static::FSI;
            }

            if (strlen($part) > static::MAX_PLACEABLE_LENGTH) {
                $scope->dirty = array_filter($scope->dirty, function ($val) use ($ptn) {
                    return $val !== $ptn;
                });

                // This is a fatal error which causes the resolver to instantly bail out
                // on this pattern. The length check protects against excessive memory
                // usage, and throwing protects against eating up the CPU when long
                // placeables are deeply nested.
                throw new RangeException(
                    "Too many characters in placeable " .
                    "(".strlen($part).", max allowed is ".static::MAX_PLACEABLE_LENGTH
                );
            }

            $result[] = $part;

            if ($useIsolating) {
                $result[] = static::PDI;
            }
        }

        $scope->dirty = array_filter($scope->dirty, function ($val) use ($ptn) {
            return $val !== $ptn;
        });

        return implode('', $result);
    }

    /**
     * Resolve a simple or a complex Pattern to a FluentString (which is really the
     * string primitive).
     */
    private static function resolvePattern(Scope $scope, $node) {
        // Resolve a simple pattern.
        if (is_string($node)) {
            return ($scope->bundle->transform)($node);
        }

        return static::resolveComplexPattern($scope, $node);
    }
}
