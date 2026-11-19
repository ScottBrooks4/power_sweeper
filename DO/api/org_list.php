<?php

function orgListPath(): string
{
    return dirname(__DIR__) . '/data/orgs.xml';
}

function orgRows(): array
{
    static $rows = null;
    if (is_array($rows)) {
        return $rows;
    }
    $rows = [];
    $path = orgListPath();
    if (!is_readable($path)) {
        return $rows;
    }
    $doc = new DOMDocument();
    if ($doc->load($path) === false) {
        return $rows;
    }
    $walk = function (DOMNode $node, string $parentName) use (&$walk, &$rows): void {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || !preg_match('/^L\d+$/', $child->tagName)) {
                continue;
            }
            $level = $child->tagName;
            $short = $child->getAttribute('short');
            $name = $level . ' - ' . $short;
            $rows[] = [
                'id' => $child->getAttribute('id'),
                'parent' => $parentName,
                'level' => $level,
                'name' => $name,
                'short' => $short,
                'fr' => $child->getAttribute('fr'),
            ];
            $walk($child, $name);
        }
    };
    $root = $doc->documentElement;
    if ($root instanceof DOMElement) {
        $walk($root, '');
    }
    return $rows;
}
