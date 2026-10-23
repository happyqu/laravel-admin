<?php

namespace Encore\Admin\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * Small HTML helper used where a full CSS selector engine would be unnecessary.
 *
 * Only the selectors used by the admin UI are supported: #id, .class, tag,
 * tag.class, tag#id and comma separated lists of those forms.
 */
class Html
{
    public static function document(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    public static function title(string $html): string
    {
        $document = self::document($html);
        $node = $document->getElementsByTagName('title')->item(0);

        return $node ? trim($node->textContent) : '';
    }

    public static function first(string $html, string $selector): ?DOMElement
    {
        $document = self::document($html);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query(self::toXPath($selector));

        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    public static function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    public static function outerHtml(DOMNode $node): string
    {
        return $node->ownerDocument->saveHTML($node);
    }

    public static function toXPath(string $selector): string
    {
        $parts = [];

        foreach (explode(',', $selector) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $parts[] = self::simpleSelectorToXPath($part);
        }

        if ($parts === []) {
            throw new InvalidArgumentException("Invalid selector [{$selector}].");
        }

        return implode(' | ', $parts);
    }

    private static function simpleSelectorToXPath(string $selector): string
    {
        if (!preg_match('/^(?<tag>[a-zA-Z][a-zA-Z0-9_-]*)?(?<id>#[a-zA-Z_][a-zA-Z0-9_-]*)?(?<class>\.[a-zA-Z_][a-zA-Z0-9_-]*)?$/', $selector, $matches)) {
            throw new InvalidArgumentException("Unsupported selector [{$selector}]. Use #id, .class, tag, tag.class or a comma separated list.");
        }

        $tag = $matches['tag'] ?: '*';
        $predicates = [];

        if (!empty($matches['id'])) {
            $predicates[] = '@id='.self::xpathLiteral(substr($matches['id'], 1));
        }

        if (!empty($matches['class'])) {
            $class = substr($matches['class'], 1);
            $predicates[] = "contains(concat(' ', normalize-space(@class), ' '), ".self::xpathLiteral(' '.$class.' ').")";
        }

        return './/'.$tag.($predicates ? '['.implode(' and ', $predicates).']' : '');
    }

    private static function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (!str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        $parts = explode("'", $value);
        $literals = [];

        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $literals[] = "'{$part}'";
            }
            if ($index < count($parts) - 1) {
                $literals[] = '"\'"';
            }
        }

        return 'concat('.implode(', ', $literals).')';
    }
}
