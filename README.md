# Fluent PHP [![Build Status][travisimage]][travislink]

[travisimage]: https://travis-ci.org/tobyz/fluent-php.svg?branch=master
[travislink]: https://travis-ci.org/tobyz/fluent-php

This library is a PHP implementation of [Project Fluent][], a localization
framework designed to unleash the expressive power of the natural language.

It is a simple port of the official JavaScript [@fluent/bundle][] package with a very similar API and source code architecture. It also includes an overlay utility based on [@fluent/dom][] to allow HTML strings to be translated.

[Project Fluent]: https://projectfluent.org
[@fluent/bundle]: https://github.com/projectfluent/fluent.js/tree/master/fluent-bundle
[@fluent/dom]: https://github.com/projectfluent/fluent.js/tree/master/fluent-dom


## Installation

    composer install tobyz/fluent-php


## Usage

```php
use Tobyz\Fluent\Bundle;
use Tobyz\Fluent\Resource;
use Tobyz\Fluent\Overlay;

$resource = new Resource('
-brand-name = Foo 3000
welcome = Welcome, {$name}, to {-brand-name}!
');

$bundle = new Bundle('en-US');
$errors = $bundle->addResource($resource);
if (count($errors)) {
    // Syntax errors are per-message and don't break the whole resource
}

$welcome = $bundle->getMessage('welcome');
if ($welcome) {
    $bundle->formatPattern($welcome['value'], ['name' => 'Anna']);
    // → "Welcome, Anna, to Foo 3000!"
}

// Overlay translations and attributes onto HTML
// See https://github.com/projectfluent/fluent.js/wiki/DOM-Overlays
Overlay::translateHtml(
    '<p><img data-l10n-name="world" src="world.png"></p>',
    [
        'value' => 'Hello, <img data-l10n-name="world" alt="world">!', 
        'attributes' => ['title' => 'Hello']
    ]
);
// → <p title="Hello">Hello, <img data-l10n-name="world" alt="world" src="world.png">!</p>
```


## Caveats

* There is no PHP implementation of `Intl.PluralRules`. For now a basic "polyfill" has been included but it only contains the rule for English cardinals.

* PHP includes the [NumberFormatter][] and [IntlDateFormatter][] classes, but these lack some of the functionality of `Intl.NumberFormat` and `Intl.DateTimeFormat`, and thus the full API for the [built-in functions][] cannot be supported. Specifically:

    * In `NUMBER`, the `currencyDisplay` option is not supported.

    * In `DATETIME`, no options are supported except for `timeZone`. However, the [experimental] `dateStyle` and `timeStyle` options are supported.

* The library currently has no tests.

[NumberFormatter]: https://www.php.net/manual/en/class.numberformatter.php
[IntlDateFormatter]: https://www.php.net/manual/en/class.intldateformatter.php
[built-in functions]: https://projectfluent.org/fluent/guide/functions.html
[experimental]: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/DateTimeFormat#Parameters


## Contributing

Fluent PHP is open-source, licensed under the Apache License, Version 2.0. Feel free to send pull requests or create issues if you come across problems or have great ideas.
