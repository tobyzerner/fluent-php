<?php

namespace Tobyz\Fluent\Intl;

use InvalidArgumentException;

class PluralRules
{
    private $locale;
    private $type;

    public function __construct($locales, array $options = [])
    {
        if (! isset($options['type']) || $options['type'] === 'cardinal') {
            $this->type = 'cardinal';
		} elseif ($options['type'] === 'ordinal') {
            $this->type = 'ordinal';
		} else {
            throw new InvalidArgumentException('Unsupported "type" option: '.$options['type']);
		}

        $locales = static::supportedLocalesOf($locales);

        if (count($locales) === 0) {
            throw new InvalidArgumentException('Unsupported locale');
        }

        $this->locale = $locales[0];
    }

    public function select($number): string
    {
        if (substr($this->locale, 0, 2) === 'en') {
            $s = explode('.', $number);
            $v0 = ! isset($s[1]);
		    return ($number == 1 && $v0) ? 'one' : 'other';
        }

        // TODO
    }

    public function resolvedOptions(): array
    {
        return [
            'locale' => $this->locale,
            'type' => $this->type
        ];
    }

    public static function supportedLocalesOf($locales): array
    {
        $locales = is_string($locales) ? [$locales] : $locales;

        return array_filter($locales, function ($locale) {
            return substr($locale, 0, 2) === 'en';
        });
    }
}
