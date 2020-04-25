<?php

namespace Tobyz\Fluent;

use DOMDocument;
use DOMDocumentFragment;
use DOMElement;
use DOMXpath;

class Overlay
{
    // Match the opening angle bracket (<) in HTML tags, and HTML entities like
    // &amp;, &#0038;, &#x0026;.
    const RE_OVERLAY = '/<|&#?\w+;/';

    /**
     * Elements allowed in translations even if they are not present in the source
     * HTML. They are text-level elements as defined by the HTML5 spec:
     * https://www.w3.org/TR/html5/text-level-semantics.html with the exception of:
     *
     *   - a - because we don't allow href on it anyways,
     *   - ruby, rt, rp - because we don't allow nested elements to be inserted.
     */
    const TEXT_LEVEL_ELEMENTS = [
        "em", "strong", "small", "s", "cite", "q", "dfn", "abbr", "data",
        "time", "code", "var", "samp", "kbd", "sub", "sup", "i", "b", "u",
        "mark", "bdi", "bdo", "span", "br", "wbr"
    ];

    const LOCALIZABLE_ATTRIBUTES = [
        'global' => ["title", "aria-label", "aria-valuetext"],
        'a' => ["download"],
        'area' => ["download", "alt"],
        // value is special-cased in isAttrNameLocalizable
        'input' => ["alt", "placeholder"],
        'menuitem' => ["label"],
        'menu' => ["label"],
        'optgroup' => ["label"],
        'option' => ["label"],
        'track' => ["label"],
        'img' => ["alt"],
        'textarea' => ["placeholder"],
        'th' => ["abbr"]
    ];

    /**
     * Translate an element.
     *
     * Translate the element's text content and attributes. Some HTML markup is
     * allowed in the translation. The element's children with the data-l10n-name
     * attribute will be treated as arguments to the translation. If the
     * translation defines the same children, their attributes and text contents
     * will be used for translating the matching source child.
     *
     * @param   {Element} element
     * @param   {Object} translation
     * @private
     */
    public static function translateHtml(string $html, array $translation)
    {
        $value = $translation['value'];

        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $element = $doc->documentElement;

        if (is_string($value)) {
            if (! preg_match(static::RE_OVERLAY, $value)) {
                // If the translation doesn't contain any markup skip the overlay logic.
                $element->textContent = $value;
            } else {
                // Else parse the translation's HTML using an inert template element,
                // sanitize it and replace the element's content.
                $template = new DOMDocument;
                $template->loadHTML('<?xml encoding="utf-8" ?>'.$value, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $imported = $doc->importNode($template->documentElement, true);
                static::overlayChildNodes($imported, $element);
            }
        }

        // Even if the translation doesn't define any localizable attributes, run
        // overlayAttributes to remove any localizable attributes set by previous
        // translations.
        static::overlayAttributes($translation, $element);

        return $doc->saveHTML($doc->documentElement);
    }

    /**
     * Replace child nodes of an element with child nodes of another element.
     *
     * The contents of the target element will be cleared and fully replaced with
     * sanitized contents of the source element.
     *
     * @param {DocumentFragment} fromFragment - The source of children to overlay.
     * @param {Element} toElement - The target of the overlay.
     * @private
     */
    private static function overlayChildNodes(DOMElement $fromElement, DOMElement $toElement)
    {
        for ($i = 0; $i < $fromElement->childNodes->length; $i++) {
            $childNode = $fromElement->childNodes->item($i);

            if ($childNode->nodeType === XML_TEXT_NODE) {
                // Keep the translated text node.
                continue;
            }
    
            if ($childNode->hasAttribute('data-l10n-name')) {
                $sanitized = static::getNodeForNamedElement($toElement, $childNode);
                $fromElement->replaceChild($sanitized, $childNode);
                continue;
            }
    
            if (static::isElementAllowed($childNode)) {
                $sanitized = static::createSanitizedElement($childNode);
                $fromElement->replaceChild($sanitized, $childNode);
                continue;
            }
    
            trigger_error(
                'An element of forbidden type "'.$childNode->localName.'" was found in '.
                "the translation. Only safe text-level elements and elements with ".
                "data-l10n-name are allowed.",
                E_USER_WARNING
            );
    
            // If all else fails, replace the element with its text content.
            $fromElement->replaceChild(
                static::createTextNodeFromTextContent($childNode), $childNode);
        }
    
        $toElement->textContent = "";

        foreach ($fromElement->childNodes as $node) {
            $toElement->appendChild($node->cloneNode(true));
        }
    }

    /**
     * Transplant localizable attributes of an element to another element.
     *
     * Any localizable attributes already set on the target element will be
     * cleared.
     *
     * @param   {Element|Object} fromElement - The source of child nodes to overlay.
     * @param   {Element} toElement - The target of the overlay.
     * @private
     */
    private static function overlayAttributes($fromElement, DOMElement $toElement) 
    {
        $explicitlyAllowed = $toElement->hasAttribute("data-l10n-attrs")
            ? explode(',', $toElement->getAttribute("data-l10n-attrs"))
            : null;

        if ($fromElement instanceof DOMElement) {
            $fromAttributes = [];
            foreach ($fromElement->attributes as $name => $attribute) {
                $fromAttributes[$name] = $attribute->value;
            }
        } else {
            $fromAttributes = $fromElement['attributes'] ?? [];
        }

        // Remove existing localizable attributes if they
        // will not be used in the new translation.
        foreach ($toElement->attributes as $attribute) {
            if (
                static::isAttrNameLocalizable($attribute->name, $toElement, $explicitlyAllowed)
                && ! isset($fromAttributes[$attribute->name])
            ) {
                $toElement->removeAttribute($attribute->name);
            }
        }

        // fromElement might be a {value, attributes} object as returned by
        // Localization.messageFromBundle. In which case attributes may be null to
        // save GC cycles.
        if (empty($fromAttributes)) {
            return;
        }

        // Set localizable attributes.
        foreach ($fromAttributes as $name => $value) {
            if (
                static::isAttrNameLocalizable($name, $toElement, $explicitlyAllowed)
                && $toElement->getAttribute($name) !== $value
            ) {
                $toElement->setAttribute($name, $value);
            }
        }
    }

    /**
     * Sanitize a child element created by the translation.
     *
     * Try to find a corresponding child in sourceElement and use it as the base
     * for the sanitization. This will preserve functional attribtues defined on
     * the child element in the source HTML.
     *
     * @param   {Element} sourceElement - The source for data-l10n-name lookups.
     * @param   {Element} translatedChild - The translated child to be sanitized.
     * @returns {Element}
     * @private
     */
    private static function getNodeForNamedElement(DOMElement $sourceElement, DOMElement $translatedChild) 
    {
        $xpath = new DOMXpath($sourceElement->ownerDocument);

        $childName = $translatedChild->getAttribute('data-l10n-name');
        $sourceChild = $xpath->query('//*[@data-l10n-name="'.$childName.'"]', $sourceElement)->item(0);

        if (! $sourceChild) {
            trigger_error(
                'An element named "'.$childName.'" wasn\'t found in the source.',
                E_USER_WARNING
            );
            return static::createTextNodeFromTextContent($translatedChild);
        }

        if ($sourceChild->localName !== $translatedChild->localName) {
            trigger_error(
                'An element named "'.$childName.'" was found in the translation '.
                'but its type '.$translatedChild->localName.' didn\'t match the '.
                'element found in the source ('.$sourceChild->localName.').',
                E_USER_WARNING
            );
            return static::createTextNodeFromTextContent($translatedChild);
        }

        // Remove it from sourceElement so that the translation cannot use
        // the same reference name again.
        $sourceElement->removeChild($sourceChild);
        // We can't currently guarantee that a translation won't remove
        // sourceChild from the element completely, which could break the app if
        // it relies on an event handler attached to the sourceChild. Let's make
        // this limitation explicit for now by breaking the identitiy of the
        // sourceChild by cloning it. This will destroy all event handlers
        // attached to sourceChild via addEventListener and via on<name>
        // properties.
        $clone = $sourceChild->cloneNode(false);
        return static::shallowPopulateUsing($translatedChild, $clone);
    }

    /**
     * Sanitize an allowed element.
     *
     * Text-level elements allowed in translations may only use safe attributes
     * and will have any nested markup stripped to text content.
     *
     * @param   {Element} element - The element to be sanitized.
     * @returns {Element}
     * @private
     */
    private static function createSanitizedElement(DOMElement $element) 
    {
        // Start with an empty element of the same type to remove nested children
        // and non-localizable attributes defined by the translation.
        $clone = $element->ownerDocument->createElement($element->localName);
        return static::shallowPopulateUsing($element, $clone);
    }

    /**
     * Convert an element to a text node.
     *
     * @param   {Element} element - The element to be sanitized.
     * @returns {Node}
     * @private
     */
    private static function createTextNodeFromTextContent(DOMElement $element) 
    {
        return $element->ownerDocument->createTextNode($element->textContent);
    }

    /**
     * Check if element is allowed in the translation.
     *
     * This method is used by the sanitizer when the translation markup contains
     * an element which is not present in the source code.
     *
     * @param   {Element} element
     * @returns {boolean}
     * @private
     */
    private static function isElementAllowed(DOMElement $element)
    {
        return in_array($element->localName, static::TEXT_LEVEL_ELEMENTS);
    }

    /**
     * Check if attribute is allowed for the given element.
     *
     * This method is used by the sanitizer when the translation markup contains
     * DOM attributes, or when the translation has traits which map to DOM
     * attributes.
     *
     * `explicitlyAllowed` can be passed as a list of attributes explicitly
     * allowed on this element.
     *
     * @param   {string}         name
     * @param   {Element}        element
     * @param   {Array}          explicitlyAllowed
     * @returns {boolean}
     * @private
     */
    private static function isAttrNameLocalizable(string $name, DOMElement $element, array $explicitlyAllowed = null) 
    {
        if ($explicitlyAllowed && in_array($name, $explicitlyAllowed)) {
            return true;
        }

        $attrName = strtolower($name);
        $elemName = $element->localName;

        // Is it a globally safe attribute?
        if (in_array($attrName, static::LOCALIZABLE_ATTRIBUTES['global'])) {
            return true;
        }

        // Are there no allowed attributes for this element?
        if (! isset(static::LOCALIZABLE_ATTRIBUTES[$elemName])) {
            return false;
        }

        // Is it allowed on this element?
        if (in_array($attrName, static::LOCALIZABLE_ATTRIBUTES[$elemName])) {
            return true;
        }

        // Special case for value on HTML inputs with type button, reset, submit
        if ($elemName === "input" && $attrName === "value") {
            $type = strtolower($element->getAttribute('type'));
            if ($type === "submit" || $type === "button" || $type === "reset") {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper to set textContent and localizable attributes on an element.
     *
     * @param   {Element} fromElement
     * @param   {Element} toElement
     * @returns {Element}
     * @private
     */
    private static function shallowPopulateUsing(DOMElement $fromElement, DOMElement $toElement)
    {
        $toElement->textContent = $fromElement->textContent;
        static::overlayAttributes($fromElement, $toElement);
        return $toElement;
    }
}
