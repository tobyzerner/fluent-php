<?php

namespace Tobyz\Fluent;

use Exception;

/**
 * Message bundles are single-language stores of translation resources. They are
 * responsible for formatting message values and attributes to strings.
 */
class Bundle
{
    public $locales;
    public $terms = [];
    public $messages = [];
    public $functions = [];
    public $useIsolating = true;
    public $transform;
    public $intls = [];

    public function __construct($locales, array $options = [])
    {
        $this->locales = is_array($locales) ? $locales : [$locales];

        $this->functions = $options['functions'] ?? [];
        $this->useIsolating = $options['useIsolating'] ?? true;
        $this->transform = $options['transform'] ?? function ($v) { return $v; };
    }

    /**
     * Check if a message is present in the bundle.
     */
    public function hasMessage(string $id): bool
    {
        return isset($this->messages[$id]);
    }

    public function getMessage(string $id): ?array
    {
        return $this->messages[$id] ?? null;
    }

    public function addResource(Resource $res, array $options = []): array
    {
        $allowOverrides = $options['allowOverrides'] ?? false;
        $errors = [];

        foreach ($res->body as $entry) {
            if ($entry['id'][0] === '-') {
                // Identifiers starting with a dash (-) define terms. Terms are private
                // and cannot be retrieved from FluentBundle.
                if ($allowOverrides === false && isset($this->terms[$entry['id']])) {
                    $errors[] = "Attempt to override an existing term: \"{$entry['id']}\"";
                    continue;
                }

                $this->terms[$entry['id']] = $entry;
            } else {
                if ($allowOverrides === false && $this->hasMessage($entry['id'])) {
                    $errors[] = "Attempt to override an existing message: \"{$entry['id']}\"";
                    continue;
                }

                $this->messages[$entry['id']] = $entry;
            }
        }

        return $errors;
    }

    public function formatPattern($pattern, array $args = [], array &$errors = null): string
    {
        // Resolve a simple pattern without creating a scope. No error handling is
        // required; by definition simple patterns don't have placeables.
        if (is_string($pattern)) {
            return ($this->transform)($pattern);
        }

        // Resolve a complex pattern.
        $scope = new Scope($this, $errors, $args);
        try {
            $value = Resolver::resolveComplexPattern($scope, $pattern);
            return is_string($value) ? $value : $value->toString($scope);
        } catch (Exception $e) {
            if ($scope->errors) {
                $scope->errors[] = $e;
                return (new Types\None)->toString($scope);
            }
            throw $e;
        }
    }
}
