<?php

namespace Tobyz\Fluent\Intl;

use InvalidArgumentException;
use IntlDateFormatter;
use ResourceBundle;

class DateTimeFormat
{
    private $options;
    private $locale;
    private $formatter;

    public function __construct($locales = null, array $options = [])
    {
        $this->options = $options;

        $locales = static::supportedLocalesOf($locales);

        if (count($locales) === 0) {
            throw new InvalidArgumentException('Unsupported locale');
        }

        $this->locale = $locales[0];

        $styles = [
            'full' => IntlDateFormatter::FULL,
            'long' => IntlDateFormatter::LONG,
            'medium' => IntlDateFormatter::MEDIUM,
            'short' => IntlDateFormatter::SHORT
        ];

        $datetype = isset($options['dateStyle']) && isset($styles[$options['dateStyle']]) ? $styles[$options['dateStyle']] : IntlDateFormatter::NONE;
        $timetype = isset($options['timeStyle']) && isset($styles[$options['timeStyle']]) ? $styles[$options['timeStyle']] : IntlDateFormatter::NONE;

        if ($datetype === IntlDateFormatter::NONE && $timetype === IntlDateFormatter::NONE) {
            $datetype = IntlDateFormatter::SHORT;
        }

        $this->formatter = new IntlDateFormatter($this->locale, $datetype, $timetype, $options['timeZone'] ?? null);
    }

    public function format($date)
    {
        return $this->formatter->format($date);
    }

    public static function supportedLocalesOf($locales): array
    {
        $locales = is_string($locales) ? [$locales] : $locales;
        $available = ResourceBundle::getLocales('');

        return array_filter($locales, function ($locale) use ($available) {
            return in_array($locale, $available);
        });
    }
}
