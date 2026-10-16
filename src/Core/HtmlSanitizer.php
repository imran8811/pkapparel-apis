<?php

declare(strict_types=1);

namespace App\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allow-list HTML cleaner for rich text (blog posts, legal pages, admin editors).
 * Keeps simple formatting, links, images and tables. Removes scripts, styles,
 * event handlers, classes and unsafe URLs. Unknown wrappers (div, span, section)
 * are unwrapped, keeping their content.
 */
final class HtmlSanitizer
{
    /** @var array<string, list<string>> tag => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'ul' => [], 'ol' => [], 'li' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'sub' => [], 'sup' => [], 'small' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'blockquote' => [], 'code' => [], 'pre' => [],
        'img' => ['src', 'alt', 'width', 'height', 'title'],
        'figure' => [], 'figcaption' => [],
        'table' => ['id'], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
    ];

    /** Removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'option',
        'textarea', 'svg', 'math', 'noscript', 'template', 'head', 'title', 'meta', 'link', 'base', 'frame',
        'frameset', 'canvas', 'audio', 'video', 'source', 'track',
    ];

    /** Headings are shifted so the page title stays the only <h1>. */
    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    /** Layout boxes that are removed; text directly inside them becomes a paragraph so lines don't run together. */
    private const BLOCK_WRAPPERS = [
        'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'address', 'center',
        'details', 'summary', 'dl', 'dt', 'dd', 'fieldset', 'hgroup',
    ];

    /** Elements that may hold paragraphs (where loose text is wrapped in <p>). */
    private const FLOW_CONTAINERS = ['li', 'td', 'th', 'blockquote', 'figure'];

    /** Text-level elements that belong inside a paragraph. */
    private const INLINE = ['a', 'strong', 'b', 'em', 'i', 'u', 'sub', 'sup', 'small', 'code', 'br'];

    /** @var (callable(string, string): ?string)|null */
    private $rewriteUrl = null;

    /**
     * @param (callable(string $url, string $attribute): ?string)|null $rewriteUrl
     *        Optional hook to rewrite link/image URLs; return null to drop the URL.
     */
    public function sanitize(string $html, ?callable $rewriteUrl = null): string
    {
        if (trim($html) === '') {
            return '';
        }
        $this->rewriteUrl = $rewriteUrl;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="pk-sanitize-root">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('pk-sanitize-root');
        if ($root === null) {
            return '';
        }
        $this->cleanChildren($root);
        $this->wrapInlineRuns($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $out));
    }

    private function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child); // comments, processing instructions, CDATA
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (isset(self::RENAME[$tag])) {
                $child = $this->rename($child, self::RENAME[$tag]);
                $tag = self::RENAME[$tag];
            }
            if (!isset(self::ALLOWED[$tag])) {
                $this->cleanChildren($child);
                if (in_array($tag, self::BLOCK_WRAPPERS, true) && $this->holdsParagraphs($node)) {
                    $this->wrapInlineRuns($child);
                }
                $this->unwrap($child);
                continue;
            }

            $this->cleanAttributes($child, $tag);
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'a' && !$child->hasAttribute('href')) {
                $this->cleanChildren($child);
                $this->unwrap($child);
                continue;
            }

            $this->cleanChildren($child);

            if ($tag === 'blockquote') {
                $this->wrapInlineRuns($child);
            }
            if (in_array($tag, ['li', 'td', 'th'], true)) {
                $this->unwrapSingleParagraph($child);
            }
            if ($tag === 'table') {
                $this->moveHeaderRowsToThead($child);
            }

            if (in_array($tag, ['p', 'h2', 'h3', 'h4', 'li', 'strong', 'b', 'em', 'i'], true) && $this->isEmpty($child)) {
                $node->removeChild($child);
            }
        }
    }

    /** True for the root and for elements where block content (paragraphs) is allowed. */
    private function holdsParagraphs(DOMNode $node): bool
    {
        if (!$node instanceof DOMElement) {
            return false;
        }
        $tag = strtolower($node->nodeName);

        return $node->getAttribute('id') === 'pk-sanitize-root'
            || in_array($tag, self::FLOW_CONTAINERS, true)
            || in_array($tag, self::BLOCK_WRAPPERS, true);
    }

    /** Wrap each run of loose text and text-level elements in a <p>; runs of only spaces or <br> are dropped. */
    private function wrapInlineRuns(DOMElement $container): void
    {
        $run = [];
        $flush = function () use (&$run, $container): void {
            $nodes = $run;
            $run = [];
            $meaningful = array_filter($nodes, static fn (DOMNode $n): bool => $n instanceof DOMText
                ? trim(str_replace("\u{00A0}", ' ', $n->textContent)) !== ''
                : strtolower($n->nodeName) !== 'br');
            if ($meaningful === []) {
                foreach ($nodes as $n) {
                    if (!$n instanceof DOMText) {
                        $container->removeChild($n);
                    }
                }

                return;
            }

            // Leading and trailing line breaks are noise once the text has its own paragraph.
            while ($nodes !== [] && $this->isBreakOrBlank($nodes[0])) {
                $container->removeChild(array_shift($nodes));
            }
            while ($nodes !== [] && $this->isBreakOrBlank($nodes[count($nodes) - 1])) {
                $container->removeChild(array_pop($nodes));
            }

            $paragraph = $container->ownerDocument->createElement('p');
            $container->insertBefore($paragraph, $nodes[0]);
            foreach ($nodes as $n) {
                $paragraph->appendChild($n);
            }
        };

        foreach (iterator_to_array($container->childNodes) as $child) {
            $inline = $child instanceof DOMText
                || ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::INLINE, true));
            if ($inline) {
                $run[] = $child;
            } elseif ($run !== []) {
                $flush();
            }
        }
        if ($run !== []) {
            $flush();
        }
    }

    private function isBreakOrBlank(DOMNode $node): bool
    {
        return $node instanceof DOMText
            ? trim(str_replace("\u{00A0}", ' ', $node->textContent)) === ''
            : strtolower($node->nodeName) === 'br';
    }

    /**
     * "<li><p>Text</p></li>" becomes "<li>Text</li>" (editors wrap every list item and table cell in a
     * paragraph, which adds extra spacing). Only when there is one paragraph next to nothing but lists.
     */
    private function unwrapSingleParagraph(DOMElement $element): void
    {
        $paragraphs = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);
                if ($tag === 'p') {
                    $paragraphs[] = $child;
                } elseif (!in_array($tag, ['ul', 'ol'], true)) {
                    return;
                }
            } elseif ($child instanceof DOMText && trim($child->textContent) !== '') {
                return;
            }
        }
        if (count($paragraphs) === 1) {
            $this->unwrap($paragraphs[0]);
        }
    }

    /** Leading rows made only of <th> cells become the table's <thead> (editors put every row in <tbody>). */
    private function moveHeaderRowsToThead(DOMElement $table): void
    {
        $body = null;
        foreach ($table->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'thead') {
                return;
            }
            if ($tag === 'tbody' || $tag === 'tr') {
                $body = $tag === 'tbody' ? $child : $table;
                break;
            }
        }
        if ($body === null) {
            return;
        }

        $rows = array_values(array_filter(
            iterator_to_array($body->childNodes),
            static fn (DOMNode $n): bool => $n instanceof DOMElement && strtolower($n->nodeName) === 'tr',
        ));
        $headerRows = [];
        foreach ($rows as $row) {
            $cells = array_filter(iterator_to_array($row->childNodes), static fn (DOMNode $n): bool => $n instanceof DOMElement);
            $allHeaders = $cells !== [] && array_filter($cells, static fn (DOMNode $c): bool => strtolower($c->nodeName) !== 'th') === [];
            if (!$allHeaders) {
                break;
            }
            $headerRows[] = $row;
        }
        if ($headerRows === [] || count($headerRows) === count($rows)) {
            return;
        }

        $thead = $table->ownerDocument->createElement('thead');
        $table->insertBefore($thead, $body === $table ? $headerRows[0] : $body);
        foreach ($headerRows as $row) {
            $thead->appendChild($row);
        }
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        foreach (['href', 'src'] as $urlAttribute) {
            if (!$element->hasAttribute($urlAttribute)) {
                continue;
            }
            $url = $this->safeUrl($element->getAttribute($urlAttribute), $urlAttribute);
            $url === null ? $element->removeAttribute($urlAttribute) : $element->setAttribute($urlAttribute, $url);
        }

        if ($tag === 'a') {
            if ($element->getAttribute('target') === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            } else {
                $element->removeAttribute('target');
                $element->removeAttribute('rel');
            }
        }
        if ($tag === 'img' && !$element->hasAttribute('alt')) {
            $element->setAttribute('alt', '');
        }
        // ids are kept only as simple anchors (for "jump to section" links)
        if ($element->hasAttribute('id') && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $element->getAttribute('id')) !== 1) {
            $element->removeAttribute('id');
        }
        foreach (['width', 'height', 'colspan', 'rowspan'] as $numeric) {
            if ($element->hasAttribute($numeric) && !ctype_digit($element->getAttribute($numeric))) {
                $element->removeAttribute($numeric);
            }
        }
        // colspan="1" / rowspan="1" are the default (editors add them to every cell)
        foreach (['colspan', 'rowspan'] as $span) {
            if ($element->getAttribute($span) === '1') {
                $element->removeAttribute($span);
            }
        }
    }

    private function safeUrl(string $url, string $attribute): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if ($this->rewriteUrl !== null) {
            $url = ($this->rewriteUrl)($url, $attribute);
            if ($url === null || $url === '') {
                return null;
            }
        }

        // Compare without whitespace/control characters, which browsers ignore inside schemes.
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $url));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $compact, $m) === 1) {
            $allowedSchemes = $attribute === 'src' ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'];

            return in_array($m[1], $allowedSchemes, true) ? $url : null;
        }

        return $url; // relative URL, fragment or query
    }

    private function rename(DOMElement $element, string $tag): DOMElement
    {
        $doc = $element->ownerDocument;
        $replacement = $doc->createElement($tag);
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $replacement->setAttribute($attribute->nodeName, (string) $attribute->nodeValue);
        }
        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }
        $element->parentNode?->replaceChild($replacement, $element);

        return $replacement;
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private function isEmpty(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return false;
            }
        }

        return trim(str_replace("\u{00A0}", ' ', $element->textContent)) === '';
    }
}
