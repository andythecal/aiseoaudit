<?php
declare(strict_types=1);
namespace Audit;
final class Html {
    public $dom;
    public $xpath;
    public function __construct(string $html) {
        $this->dom = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        // Convert encoding before parsing; never load DTDs or external entities.
        if (!mb_check_encoding($html, 'UTF-8')) { $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252'); }
        $this->dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        $this->xpath = new \DOMXPath($this->dom);
    }
    public function nodes(string $query): array { return iterator_to_array($this->xpath->query($query)); }
    public static function clean(string $text): string { return trim(preg_replace('/[\s\x{00a0}\x{FEFF}]+/u', ' ', $text) ?? ''); }
    public function text(string $query): string { $nodes = $this->nodes($query); return $nodes ? self::clean($nodes[0]->textContent) : ''; }
    public function attr(string $query, string $attribute): string { $nodes = $this->nodes($query); return $nodes ? trim($nodes[0]->getAttribute($attribute)) : ''; }
    public function meta(string $name, string $attribute = 'name'): string {
        return $this->attr('//head/meta[translate(@' . $attribute . ', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $name . '"]', 'content');
    }
    public function canonical(): string { return $this->attr('//head/link[contains(concat(" ",normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))," ")," canonical ")]', 'href'); }
    public function wordCount(): int {
        $pieces = [];
        foreach ($this->nodes('//p|//h1|//h2|//h3|//h4|//h5|//h6') as $node) { $text = self::clean($node->textContent); if ($text !== '') { $pieces[] = $text; } }
        $text = self::clean(implode(' ', $pieces));
        return $text === '' ? 0 : count(array_filter(explode(' ', $text), 'strlen'));
    }
    public function signature(): array {
        $body = $this->text('//body');
        return ['title' => $this->text('//head/title'), 'h1' => $this->text('//h1'), 'words' => $body === '' ? 0 : count(explode(' ', $body))];
    }
}
