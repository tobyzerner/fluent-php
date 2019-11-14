<?php

namespace Tobyz\Fluent;

use IntlChar;
use Tobyz\Fluent\Error;

class Parser
{
    // This regex is used to iterate through the beginnings of messages and terms.
    // With the /m flag, the ^ matches at the beginning of every line.
    const RE_MESSAGE_START = '/^(-?[a-zA-Z][\w-]*) *= */m'; // global

    // Both Attributes and Variants are parsed in while loops. These regexes are
    // used to break out of them.
    const RE_ATTRIBUTE_START = '/\.([a-zA-Z][\w-]*) *= */A'; // sticky
    const RE_VARIANT_START = '/\*?\[/A'; // sticky

    const RE_NUMBER_LITERAL = '/(-?[0-9]+(?:\.([0-9]+))?)/A'; // sticky
    const RE_IDENTIFIER = '/([a-zA-Z][\w-]*)/A'; // sticky
    const RE_REFERENCE = '/([$-])?([a-zA-Z][\w-]*)(?:\.([a-zA-Z][\w-]*))?/A'; // sticky
    const RE_FUNCTION_NAME = '/^[A-Z][A-Z0-9_-]*$/A';

    // A "run" is a sequence of text or string literal characters which don't
    // require any special handling. For TextElements such special characters are: {
    // (starts a placeable), and line breaks which require additional logic to check
    // if the next line is indented. For StringLiterals they are: \ (starts an
    // escape sequence), " (ends the literal), and line breaks which are not allowed
    // in StringLiterals. Note that string runs may be empty; text runs may not.
    const RE_TEXT_RUN = '/([^{}\n\r]+)/A'; // sticky
    const RE_STRING_RUN = '/([^\\"\n\r]*)/A'; // sticky

    // Escape sequences.
    const RE_STRING_ESCAPE = '/\\([\\"])/A'; // sticky
    const RE_UNICODE_ESCAPE = '/\\u([a-fA-F0-9]{4})|\\U([a-fA-F0-9]{6})/A'; // sticky

    // Used for trimming TextElements and indents.
    const RE_LEADING_NEWLINES = '/^\n+/';
    const RE_TRAILING_SPACES = '/ +$/';
    // Used in makeIndent to strip spaces from blank lines and normalize CRLF to LF.
    const RE_BLANK_LINES = '/ *\r?\n/'; // global
    // Used in makeIndent to measure the indentation.
    const RE_INDENT = '/( *)$/';

    // Common tokens.
    const TOKEN_BRACE_OPEN = '/{\s*/A'; // sticky
    const TOKEN_BRACE_CLOSE = '/\s*}/A'; // sticky
    const TOKEN_BRACKET_OPEN = '/\[\s*/A'; // sticky
    const TOKEN_BRACKET_CLOSE = '/\s*] */A'; // sticky
    const TOKEN_PAREN_OPEN = '/\s*\(\s*/A'; // sticky
    const TOKEN_ARROW = '/\s*->\s*/A'; // sticky
    const TOKEN_COLON = '/\s*:\s*/A'; // sticky
    // Note the optional comma. As a deviation from the Fluent EBNF, the parser
    // doesn't enforce commas between call arguments.
    const TOKEN_COMMA = '/\s*,?\s*/A'; // sticky
    const TOKEN_BLANK = '/\s+/A'; // sticky

    // Maximum number of placeables in a single Pattern to protect against Quadratic
    // Blowup attacks. See https://msdn.microsoft.com/en-us/magazine/ee335713.aspx.
    const MAX_PLACEABLES = 100;

    private $source;
    private $cursor = 0;

    public function __construct($source)
    {
        $this->source = $source;
    }

    public function parse()
    {
        $resource = [];

        while (true) {
            if (! preg_match(static::RE_MESSAGE_START, $this->source, $matches, PREG_OFFSET_CAPTURE, $this->cursor)) {
                break;
            }

            $this->cursor = $matches[0][1] + strlen($matches[0][0]);
            $resource[] = $this->parseMessage($matches[1][0]);
        }

        return $resource;
    }

    private function test($re)
    {
        $test = preg_match($re, $this->source, $matches, PREG_OFFSET_CAPTURE, $this->cursor);

        return $test ? $matches[0][1] + strlen($matches[0][0]) : false;
    }

    /**
     * Advance the cursor by the char if it matches. May be used as a predicate
     * (was the match found?) or, if errorClass is passed, as an assertion.
     */
    private function consumeChar($char, $errorClass = null) {
        if ($this->source[$this->cursor] === $char) {
            $this->cursor++;
            return true;
        }

        if ($errorClass) {
            throw new $errorClass("Expected $char");
        }

        return false;
    }

    /**
     * Advance the cursor by the token if it matches. May be used as a predicate
     * (was the match found?) or, if errorClass is passed, as an assertion.
     */
    private function consumeToken($re, $errorClass = null) {
        if ($lastIndex = $this->test($re)) {
            $this->cursor = $lastIndex;
            return true;
        }

        if ($errorClass) {
            throw new $errorClass("Expected $re");
        }

        return false;
    }

    /**
     * Execute a regex, advance the cursor, and return all capture groups.
     */
    private function match($re)
    {
        if (! preg_match($re, $this->source, $matches, PREG_OFFSET_CAPTURE, $this->cursor)) {
            throw new FluentException("Expected $re");
        }

        $this->cursor = $matches[0][1] + strlen($matches[0][0]);

        return array_map(function ($match) {
            return $match[0];
        }, $matches);
    }

    /**
     * Execute a regex, advance the cursor, and return the capture group.
     */
    private function match1($re)
    {
        return $this->match($re)[1];
    }

    private function parseMessage($id)
    {
        $value = $this->parsePattern();
        $attributes = $this->parseAttributes();

        if ($value === null && count($attributes) === 0) {
            throw new FluentException('Expected message value or attributes');
        }

        return compact('id', 'value', 'attributes');
    }

    private function parseAttributes()
    {
        $attrs = [];

        while ($this->test(static::RE_ATTRIBUTE_START)) {
            $name = $this->match1(static::RE_ATTRIBUTE_START);
            $value = $this->parsePattern();
            if ($value === null) {
                throw new FluentException("Expected attribute value");
            }
            $attrs[$name] = $value;
        }

        return $attrs;
    }

    private function parsePattern()
    {
        // First try to parse any simple text on the same line as the id.
        if ($this->test(static::RE_TEXT_RUN)) {
            $first = $this->match1(static::RE_TEXT_RUN);
        }

        // If there's a placeable on the first line, parse a complex pattern.
        if ($this->source[$this->cursor] === "{" || $this->source[$this->cursor] === "}") {
            // Re-use the text parsed above, if possible.
            return $this->parsePatternElements(isset($first) ? [$first] : [], INF);
        }

        // RE_TEXT_VALUE stops at newlines. Only continue parsing the pattern if
        // what comes after the newline is indented.
        $indent = $this->parseIndent();
        if ($indent) {
            if (isset($first)) {
                // If there's text on the first line, the blank block is part of the
                // translation content in its entirety.
                return $this->parsePatternElements([$first, $indent], $indent['length']);
            }

            // Otherwise, we're dealing with a block pattern, i.e. a pattern which
            // starts on a new line. Discard the leading newlines but keep the
            // inline indent; it will be used by the dedentation logic.
            $indent['value'] = $this->trim($indent['value'], static::RE_LEADING_NEWLINES);

            return $this->parsePatternElements([$indent], $indent['length']);
        }

        if (isset($first)) {
            // It was just a simple inline text after all.
            return $this->trim($first, static::RE_TRAILING_SPACES);
        }

        return null;
    }

    /**
     * Parse a complex pattern as an array of elements.
     */
    private function parsePatternElements($elements, $commonIndent)
    {
        $placeableCount = 0;

        while (true) {
            if ($this->test(static::RE_TEXT_RUN)) {
                $elements[] = $this->match1(static::RE_TEXT_RUN);
                continue;
            }

            if ($this->source[$this->cursor] === "{") {
                if (++$placeableCount > static::MAX_PLACEABLES) {
                    throw new FluentException("Too many placeables");
                }

                $elements[] = $this->parsePlaceable();
                continue;
            }

            if ($this->source[$this->cursor] === "}") {
                throw new FluentException("Unbalanced closing brace");
            }

            $indent = $this->parseIndent();
            if ($indent) {
                $elements[] = $indent;
                $commonIndent = min($commonIndent, $indent['length']);
                continue;
            }

            break;
        }

        $lastIndex = count($elements) - 1;
        // Trim the trailing spaces in the last element if it's a TextElement.
        if (is_string($elements[$lastIndex])) {
            $elements[$lastIndex] = $this->trim($elements[$lastIndex], static::RE_TRAILING_SPACES);
        }

        $baked = [];
        foreach ($elements as $element) {
            if (isset($element['type']) && $element['type'] === "indent") {
                // Dedent indented lines by the maximum common indent.
                $element = substr($element['value'], 0, strlen($element['value']) - $commonIndent);
            }
            if ($element) {
                $baked[] = $element;
            }
        }

        return $baked;
    }

    private function parsePlaceable()
    {
        $this->consumeToken(static::TOKEN_BRACE_OPEN, FluentException::class);

        $selector = $this->parseInlineExpression();
        if ($this->consumeToken(static::TOKEN_BRACE_CLOSE)) {
            return $selector;
        }

        if ($this->consumeToken(static::TOKEN_ARROW)) {
            $variants = $this->parseVariants();
            $this->consumeToken(static::TOKEN_BRACE_CLOSE, FluentException::class);
            return array_merge(['type' => 'select', 'selector' => $selector], $variants);
        }

        throw new FluentException("Unclosed placeable");
    }

    private function parseInlineExpression()
    {
        if ($this->source[$this->cursor] === "{") {
            // It's a nested placeable.
            return $this->parsePlaceable();
        }

        if ($this->test(static::RE_REFERENCE)) {
            [, $sigil, $name, $attr] = array_pad($this->match(static::RE_REFERENCE), 4, null);

            if ($sigil === "$") {
                return ['type' => 'var', 'name' => $name];
            }

            if ($this->consumeToken(static::TOKEN_PAREN_OPEN)) {
                $args = $this->parseArguments();

                if ($sigil === "-") {
                    // A parameterized term: -term(...).
                    return ['type' => 'term', 'name' => $name, 'attr' => $attr, 'args' => $args];
                }

                if (preg_match(static::RE_FUNCTION_NAME, $name)) {
                    return ['type' => 'func', 'name' => $name, 'args' => $args];
                }

                throw new FluentException("Function names must be all upper-case");
            }

            if ($sigil === "-") {
                // A non-parameterized term: -term.
                return ['type' => 'term', 'name' => $name, 'attr' => $attr, 'args' => []];
            }

            return ['type' => 'mesg', 'name' => $name, 'attr' => $attr];
        }

        return $this->parseLiteral();
    }

    private function parseArguments()
    {
        $args = [];
        while (true) {
            if (strlen($this->source) - 1 < $this->cursor) {
                throw new FluentException("Unclosed argument list");
            }

            if ($this->source[$this->cursor] === ")") { // End of the argument list.
                $this->cursor++;
                return $args;
            }

            $args[] = $this->parseArgument();
            // Commas between arguments are treated as whitespace.
            $this->consumeToken(static::TOKEN_COMMA);
        }
    }

    private function parseArgument()
    {
        $expr = $this->parseInlineExpression();
        if ($expr['type'] !== "mesg") {
            return $expr;
        } 

        if ($this->consumeToken(static::TOKEN_COLON)) {
            // The reference is the beginning of a named argument.
            return ['type' => "narg", 'name' => $expr['name'], 'value' => $this->parseLiteral()];
        }

        // It's a regular message reference.
        return $expr;
    }

    private function parseVariants()
    {
        $variants = [];
        $count = 0;

        while ($this->test(static::RE_VARIANT_START)) {
            if ($this->consumeChar("*")) {
                $star = $count;
            }

            $key = $this->parseVariantKey();
            $value = $this->parsePattern();
            if ($value === null) {
                throw new FluentException("Expected variant value");
            }
            $variants[$count++] = compact('key', 'value');
        }

        if ($count === 0) {
            return null;
        }

        if (! isset($star)) {
            throw new FluentException("Expected default variant");
        }

        return compact('variants', 'star');
    }

    private function parseVariantKey()
    {
        $this->consumeToken(static::TOKEN_BRACKET_OPEN, FluentException::class);
        $key = $this->test(static::RE_NUMBER_LITERAL)
            ? $this->parseNumberLiteral()
            : ['type' => 'str', 'value' => $this->match1(static::RE_IDENTIFIER)];
        $this->consumeToken(static::TOKEN_BRACKET_CLOSE, FluentException::class);
        return $key;
    }

    private function parseLiteral()
    {
        if ($this->test(static::RE_NUMBER_LITERAL)) {
            return $this->parseNumberLiteral();
        }

        if ($this->source[$this->cursor] === "\"") {
            return $this->parseStringLiteral();
        }

        throw new FluentException("Invalid expression");
    }

    private function parseNumberLiteral()
    {
        [, $value, $fraction] = array_pad($this->match(static::RE_NUMBER_LITERAL), 3, '');
        $precision = strlen($fraction);
        return ['type' => "num", 'value' => (float) $value, 'precision' => $precision];
    }

    private function parseStringLiteral()
    {
        $this->consumeChar("\"", FluentException::class);
        $value = "";
        while (true) {
            $value .= $this->match1(static::RE_STRING_RUN);

            if ($this->source[$this->cursor] === "\\") {
                $value .= $this->parseEscapeSequence();
                continue;
            }

            if ($this->consumeChar("\"")) {
                return ['type' => "str", 'value' => $value];
            }

            // We've reached an EOL of EOF.
            throw new FluentException("Unclosed string literal");
        }
    }

    /**
     * Unescape known escape sequences.
     */
    private function parseEscapeSequence()
    {
        if ($this->test(static::RE_STRING_ESCAPE)) {
            return $this->match1(static::RE_STRING_ESCAPE);
        }

        if ($this->test(static::RE_UNICODE_ESCAPE)) {
            [, $codepoint4, $codepoint6] = array_pad($this->match(static::RE_UNICODE_ESCAPE), 3, null);
            $codepoint = intval($codepoint4 ?: $codepoint6, 16);
            return $codepoint <= 0xD7FF || 0xE000 <= $codepoint
                // It's a Unicode scalar value.
                ? IntlChar::chr($codepoint)
                // Lonely surrogates can cause trouble when the parsing result is
                // saved using UTF-8. Use U+FFFD REPLACEMENT CHARACTER instead.
                : "�";
        }

        throw new FluentException("Unknown escape sequence");
    }

    /**
     * Parse blank space. Return it if it looks like indent before a pattern
     * line. Skip it othwerwise.
     */
    private function parseIndent()
    {
        $start = $this->cursor;
        $this->consumeToken(static::TOKEN_BLANK);

        if (strlen($this->source) - 1 < $this->cursor) {
            return false;
        }

        // Check the first non-blank character after the indent.
        switch ($this->source[$this->cursor]) {
            case ".":
            case "[":
            case "*":
            case "}":
                // A special character. End the Pattern.
                return false;
            case "{":
                // Placeables don't require indentation (in EBNF: block-placeable).
                // Continue the Pattern.
                return $this->makeIndent(substr($this->source, $start, $this->cursor - $start));
        }

        // If the first character on the line is not one of the special characters
        // listed above, it's a regular text character. Check if there's at least
        // one space of indent before it.
        if ($this->source[$this->cursor - 1] === ' ') {
            // It's an indented text character (in EBNF: indented-char). Continue
            // the Pattern.
            return $this->makeIndent(substr($this->source, $start, $this->cursor - $start));
        }

        // A not-indented text character is likely the identifier of the next
        // message. End the Pattern.
        return false;
    }

    /**
     * Trim blanks in text according to the given regex.
     */
    private function trim($text, $re)
    {
        return preg_replace($re, '', $text);
    }

    private function makeIndent($blank)
    {
        $type = 'indent';
        $value = preg_replace(static::RE_BLANK_LINES, "\n", $blank);

        preg_match(static::RE_INDENT, $blank, $matches);
        $length = strlen($matches[1]);

        return compact('type', 'value', 'length');
    }
}
