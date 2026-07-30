<?php

namespace App\Scrapers\Concerns;

/**
 * Flattens a Contentful rich-text document (a nested node tree) to plain
 * text. Used for legal/terms fields that Contentful-backed sources (Ualá)
 * store as rich text rather than a plain string.
 */
trait ExtractsContentfulRichText
{
    /**
     * @param  array<string, mixed>|null  $document
     */
    protected function contentfulRichTextToPlainText(?array $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $text = $this->flattenRichTextNode($document);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function flattenRichTextNode(array $node): string
    {
        $value = is_string($node['value'] ?? null) ? $node['value'] : '';
        $children = $node['content'] ?? [];

        if (! is_array($children)) {
            return $value;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $childText = $this->flattenRichTextNode($child);
                $value .= $value !== '' && $childText !== '' ? ' '.$childText : $childText;
            }
        }

        return trim($value);
    }
}
