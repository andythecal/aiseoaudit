<?php
declare(strict_types=1);
namespace Audit;
final class Auxiliary {
    public static function robots(string $text, string $path, string $agent = '*'): array {
        $groups = []; $agents = []; $rules = []; $sitemaps = [];
        $flush = function () use (&$groups, &$agents, &$rules) { if ($agents) { $groups[] = ['agents' => $agents, 'rules' => $rules]; } $agents = []; $rules = []; };
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) { continue; }
            $key = strtolower($m[1]); $value = trim($m[2]);
            if ($key === 'sitemap' && $value !== '') { $sitemaps[] = $value; continue; }
            if ($key === 'user-agent') { if ($rules) { $flush(); } $agents[] = strtolower($value); }
            elseif (in_array($key, ['allow','disallow'], true) && $agents) { $rules[] = ['allow' => $key === 'allow', 'path' => $value]; }
        }
        $flush(); $selected = []; $bestAgent = -1;
        foreach ($groups as $group) {
            $match = -1;
            foreach ($group['agents'] as $a) {
                if ($a === '*') { $match = max($match, 0); }
                elseif ($agent !== '*' && $a !== '' && stripos($agent, $a) !== false) { $match = max($match, strlen($a)); }
            }
            if ($match > $bestAgent) { $selected = []; $bestAgent = $match; }
            if ($match >= 0 && $match === $bestAgent) { $selected = array_merge($selected, $group['rules']); }
        }
        $allowed = true; $winning = ''; $length = -1;
        foreach ($selected as $rule) {
            if ($rule['path'] === '') { continue; }
            $pattern = str_replace('\*', '.*', preg_quote($rule['path'], '~'));
            if (substr($pattern, -2) === '\$') { $pattern = substr($pattern, 0, -2) . '$'; }
            if (preg_match('~^' . $pattern . '~', $path)) {
                $size = strlen(str_replace(['*','$'], '', $rule['path']));
                if ($size > $length || ($size === $length && $rule['allow'])) { $length = $size; $allowed = $rule['allow']; $winning = $rule['path']; }
            }
        }
        return ['allowed' => $allowed, 'rule' => $winning, 'groups' => count($groups), 'sitemaps' => array_values(array_unique($sitemaps))];
    }
    public static function isHtml(array $r): bool { return stripos($r['type'], 'html') !== false || preg_match('/<\s*(?:html|head|body|!doctype\s+html)[\s>]/i', $r['body']) === 1; }
    public static function sitemap(string $body): array {
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) { return ['valid' => false, 'count' => 0, 'first' => '', 'message' => 'DTD and entity declarations are not supported.']; }
        $old = libxml_use_internal_errors(true); $doc = new \DOMDocument();
        $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        $root = $ok && $doc->documentElement ? $doc->documentElement->localName : '';
        if (!in_array($root, ['urlset','sitemapindex'], true)) { return ['valid' => false, 'count' => 0, 'first' => '', 'message' => 'Expected well-formed XML with a urlset or sitemapindex root.']; }
        $xp = new \DOMXPath($doc); $child = $root === 'urlset' ? 'url' : 'sitemap';
        $nodes = $xp->query('/*/*[local-name()="' . $child . '"]/*[local-name()="loc"]');
        $valid = 0; $first = '';
        foreach ($nodes as $n) { $url = trim($n->textContent); if (filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true)) { $valid++; if ($first === '') { $first = $url; } } }
        return ['valid' => $valid === $nodes->length && $valid > 0, 'count' => $valid, 'first' => $first, 'message' => $root === 'sitemapindex' ? 'Sitemap index references ' . $valid . ' sitemap(s).' : 'Sitemap contains ' . $valid . ' URL(s).'];
    }
    public static function inspect(Http $http, string $url): array {
        $p = parse_url($url); $origin = $p['scheme'] . '://' . $p['host'];
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $robot = self::file($http, $origin . '/robots.txt');
        $parsed = self::robots($robot['body'], $path); $robot['statusLabel'] = 'warn';
        if ($robot['status'] >= 200 && $robot['status'] < 300) {
            if ($robot['html']) { $robot['statusLabel'] = 'fail'; $robot['notes'][] = 'Returned HTML instead of a plain-text robots file.'; }
            elseif (!$parsed['groups']) { $robot['notes'][] = 'No User-agent groups were found.'; }
            else {
                $robot['statusLabel'] = $parsed['allowed'] ? 'pass' : 'fail';
                $robot['notes'][] = $parsed['allowed'] ? 'The wildcard crawler rules allow this URL.' : 'This URL is blocked by Disallow: ' . $parsed['rule'];
                $google = self::robots($robot['body'], $path, 'googlebot');
                $robot['notes'][] = 'Googlebot: ' . ($google['allowed'] ? 'allowed' : 'blocked') . ' by the parsed rules.';
                if (!$google['allowed']) { $robot['statusLabel'] = 'fail'; }
                $robot['notes'][] = $parsed['sitemaps'] ? 'References ' . count($parsed['sitemaps']) . ' sitemap(s).' : 'No Sitemap directive found (optional).';
            }
        }
        $mapUrl = $origin . '/sitemap.xml';
        foreach ($parsed['sitemaps'] as $candidate) {
            try {
                $candidate = Http::normalize($candidate);
                if (parse_url($candidate, PHP_URL_HOST) === $p['host'] && parse_url($candidate, PHP_URL_SCHEME) === $p['scheme']) { $mapUrl = $candidate; break; }
            } catch (\Throwable $e) { }
        }
        $map = self::file($http, $mapUrl); $map['statusLabel'] = 'warn';
        if ($map['status'] >= 200 && $map['status'] < 300) {
            if ($map['html']) { $map['statusLabel'] = 'fail'; $map['notes'][] = 'Returned HTML instead of XML.'; }
            elseif ($map['body'] === '') { $map['statusLabel'] = 'fail'; $map['notes'][] = 'An empty sitemap cannot provide valid XML or discovery URLs.'; }
            else {
                $xml = self::sitemap($map['body']); $map['notes'][] = $xml['message'];
                $map['statusLabel'] = $xml['valid'] ? 'pass' : 'fail';
                if ($xml['first'] !== '' && parse_url($xml['first'], PHP_URL_HOST) !== $p['host']) { $map['notes'][] = 'The first entry belongs to another host; verify this is intentional.'; $map['statusLabel'] = 'warn'; }
            }
        }
        unset($robot['body'], $robot['html'], $map['body'], $map['html']);
        return [$robot, $map];
    }
    private static function file(Http $http, string $url): array {
        $result = ['url' => $url, 'fetched' => false, 'status' => 0, 'contentLength' => 0, 'contentType' => '', 'notes' => [], 'body' => '', 'html' => false];
        try {
            $r = $http->get($url);
            $result = array_merge($result, ['url' => $r['url'], 'fetched' => true, 'status' => $r['status'], 'body' => $r['body'], 'contentLength' => strlen($r['body']), 'contentType' => $r['type'], 'html' => self::isHtml($r)]);
            if ($r['status'] < 200 || $r['status'] >= 300) { $result['notes'][] = 'HTTP ' . $r['status'] . ': file could not be verified.'; }
            if ($r['body'] === '') { $result['notes'][] = 'The response was empty.'; }
        } catch (\Throwable $e) { $result['notes'][] = $e->getMessage(); }
        return $result;
    }
}
