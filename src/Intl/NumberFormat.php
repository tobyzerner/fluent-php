<?php

namespace Tobyz\Fluent\Intl;

use InvalidArgumentException;
use NumberFormatter;
use ResourceBundle;

class NumberFormat
{
    private $options;
    private $locale;
    private $style;
    private $formatter;

    public function __construct($locales = null, array $options = [])
    {
        $this->options = $options;

        $styles = [
            'decimal' => NumberFormatter::DECIMAL,
            'currency' => NumberFormatter::CURRENCY,
            'percent' => NumberFormatter::PERCENT,
        ];

        $style = $options['style'] ?? 'decimal';

        if (! isset($styles[$style])) {
            throw new InvalidArgumentException('Unsupported "style" option: '.$options['style']);
        }

        $this->style = $styles[$style];

        $locales = static::supportedLocalesOf($locales);

        if (count($locales) === 0) {
            throw new InvalidArgumentException('Unsupported locale');
        }

        $this->locale = $locales[0];

        $this->formatter = new NumberFormatter($this->locale, $this->style);

        if (isset($options['useGrouping'])) {
            $this->formatter->setAttribute(NumberFormatter::GROUPING_USED, $options['useGrouping']);
        }

        if (isset($options['currencyDisplay'])) {
            throw new InvalidArgumentException('"currencyDisplay" option is not supported');
        }

        if (isset($options['minimumIntegerDigits'])) {
            $this->formatter->setAttribute(NumberFormatter::MIN_INTEGER_DIGITS, $options['minimumIntegerDigits']);
        }

        if (isset($options['minimumFractionDigits'])) {
            $this->formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $options['minimumFractionDigits']);
        }

        if (isset($options['maximumFractionDigits'])) {
            $this->formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $options['maximumFractionDigits']);
        }

        if (isset($options['minimumSignificantDigits'])) {
            $this->formatter->setAttribute(NumberFormatter::MIN_SIGNIFICANT_DIGITS, $options['minimumSignificantDigits']);
        }

        if (isset($options['maximumSignificantDigits'])) {
            $this->formatter->setAttribute(NumberFormatter::MAX_SIGNIFICANT_DIGITS, $options['maximumSignificantDigits']);
        }
    }

    public function format($number)
    {
        if ($this->style === NumberFormatter::CURRENCY) {
            return $this->formatter->formatCurrency($number, $this->options['currency'] ?? null);
        }

        return $this->formatter->format($number);
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
