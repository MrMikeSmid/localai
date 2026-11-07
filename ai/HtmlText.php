<?php
declare(strict_types=1);
namespace Rv\Ai;
defined('RV_AI') or exit;

/** Zet HTML veilig om naar zichtbare platte tekst. Er wordt niets uitgevoerd en er worden geen externe bronnen geladen. */
final class HtmlText
{
    private const DROP = ['script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'svg', 'canvas', 'audio', 'video', 'link', 'meta', 'head', 'form', 'input', 'button', 'select', 'textarea'];
    private const BLOCK = ['p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'ul', 'ol', 'li', 'table', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'br', 'hr', 'figure', 'figcaption', 'dl', 'dt', 'dd'];

    /** @return array{title:?string,text:string} */
    public static function convert(string $html): array
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $title = null;
        $t = $dom->getElementsByTagName('title')->item(0);
        if ($t) $title = self::clean($t->textContent);
        if ($title === '') $title = null;
        if ($title !== null) $title = mb_substr($title, 0, 200);

        $xp = new \DOMXPath($dom);
        // verborgen elementen zijn een bekende plek voor prompt-injectie: weglaten
        foreach ($xp->query('//*[@hidden or contains(translate(@style," ",""),"display:none") or contains(translate(@style," ",""),"visibility:hidden") or contains(translate(@style," ",""),"font-size:0")]') ?: [] as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
        foreach (self::DROP as $tag) {
            $list = $dom->getElementsByTagName($tag);
            for ($i = $list->length - 1; $i >= 0; $i--) {
                $n = $list->item($i);
                if ($n && $n->parentNode) $n->parentNode->removeChild($n);
            }
        }
        foreach ($xp->query('//comment()') ?: [] as $c) if ($c->parentNode) $c->parentNode->removeChild($c);

        $root = $dom->getElementsByTagName('main')->item(0) ?? $dom->getElementsByTagName('article')->item(0) ?? $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        $out = '';
        if ($root) self::walk($root, $out);
        return ['title' => $title, 'text' => self::clean($out, true)];
    }

    private static function walk(\DOMNode $n, string &$out): void
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMText) { $out .= $c->nodeValue; continue; }
            if (!($c instanceof \DOMElement)) continue;
            $blk = in_array(strtolower($c->nodeName), self::BLOCK, true);
            if ($blk) $out .= "\n";
            self::walk($c, $out);
            if ($blk) $out .= "\n";
        }
    }

    /** Normaliseert witruimte en verwijdert onzichtbare/stuurtekens (o.a. bidi- en zero-width-tekens). */
    public static function clean(string $s, bool $keepNewlines = false): string
    {
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u', '', $s) ?? $s;
        $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $s) ?? $s;
        $s = str_replace("\xc2\xa0", ' ', $s);
        if (!$keepNewlines) return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        $s = preg_replace('/[ \t\r\f\v]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/ ?\n ?/u', "\n", $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
        return trim($s);
    }
}
