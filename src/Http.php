<?php
declare(strict_types=1);
namespace Audit;
class Http {
    private $deadline;
    public function __construct() { $this->deadline = microtime(true) + 42; }
    public static function normalize(string $url): string {
        $url = trim($url);
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new \InvalidArgumentException('Enter a valid public HTTP or HTTPS URL (maximum 2,048 characters).'); }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) { $url = 'https://' . $url; }
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http','https'], true) || isset($p['user']) || isset($p['pass'])) { throw new \InvalidArgumentException('Only public HTTP and HTTPS URLs without passwords are supported.'); }
        $scheme = strtolower($p['scheme']); $host = strtolower(rtrim($p['host'], '.'));
        if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) { $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: ''; }
        if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/i', $host) || $host === '') { throw new \InvalidArgumentException('The hostname is invalid.'); }
        if (isset($p['port']) && $p['port'] !== ($scheme === 'https' ? 443 : 80)) { throw new \InvalidArgumentException('Only standard web ports (80 and 443) are supported.'); }
        return $scheme . '://' . $host . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
    public static function resolve(string $base, string $reference): string {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) { return $reference; }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (substr($reference, 0, 2) === '//') { return $p['scheme'] . ':' . $reference; }
        if ($reference === '') { return $base; }
        if ($reference[0] === '#') { return preg_replace('/#.*$/', '', $base) . $reference; }
        if ($reference[0] === '?') { return $origin . ($p['path'] ?? '/') . $reference; }
        $r = parse_url($reference);
        if ($r === false) { throw new \InvalidArgumentException('Invalid relative URL.'); }
        $path = $r['path'] ?? '';
        if (substr($path, 0, 1) !== '/') { $path = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/') . $path; }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') { array_pop($out); }
            elseif ($segment !== '.') { $out[] = $segment; }
        }
        $path = implode('/', $out);
        if ($path === '' || $path[0] !== '/') { $path = '/' . $path; }
        if (preg_match('~/(?:\.|\.\.)$~', $r['path'] ?? '') && substr($path, -1) !== '/') { $path .= '/'; }
        return $origin . $path . (isset($r['query']) ? '?' . $r['query'] : '') . (isset($r['fragment']) ? '#' . $r['fragment'] : '');
    }
    public static function publicIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        if (strpos($ip, ':') !== false) {
            // Only global unicast; reject mapped IPv4, transition and documentation ranges.
            $packed = inet_pton($ip);
            if ($packed === false || (ord($packed[0]) & 0xe0) !== 0x20) { return false; }
            $prefix = unpack('nfirst/nsecond', substr($packed, 0, 4));
            return $prefix['first'] !== 0x2002
                && !($prefix['first'] === 0x2001 && ($prefix['second'] < 0x0200 || $prefix['second'] === 0x0db8));
        }
        $n = unpack('N', inet_pton($ip))[1];
        foreach ([['100.64.0.0',10], ['192.0.0.0',24], ['192.0.2.0',24], ['198.18.0.0',15], ['198.51.100.0',24], ['203.0.113.0',24], ['224.0.0.0',4]] as $range) {
            $mask = (0xffffffff << (32 - $range[1])) & 0xffffffff;
            if (($n & $mask) === (unpack('N', inet_pton($range[0]))[1] & $mask)) { return false; }
        }
        return true;
    }
    private static function addresses(string $host): array {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) { $ips = [$host]; }
        else {
            if ($host === 'localhost' || strpos($host, '.') === false || preg_match('/\.(?:test|local|localhost|internal|lan)$/i', $host)) { throw new \RuntimeException('Private and local network addresses cannot be audited.'); }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $ips = [];
            foreach ($records ?: [] as $r) { if (isset($r['ip'])) { $ips[] = $r['ip']; } if (isset($r['ipv6'])) { $ips[] = $r['ipv6']; } }
            if (!$ips) { $ips = @gethostbynamel($host) ?: []; }
        }
        if (!$ips) { throw new \RuntimeException('The domain could not be resolved. Check the address and try again.'); }
        foreach ($ips as $ip) { if (!self::publicIp($ip)) { throw new \RuntimeException('Private and reserved network addresses cannot be audited.'); } }
        return $ips;
    }
    public function get(string $url, bool $follow = true): array {
        $chain = [];
        for ($hop = 0; $hop <= 5; $hop++) {
            $url = self::normalize($url);
            $host = parse_url($url, PHP_URL_HOST);
            $ips = self::addresses($host);
            $remaining = $this->deadline - microtime(true);
            if ($remaining <= 0) { throw new \RuntimeException('The audit time budget was reached. Please try again.'); }
            $body = ''; $headers = []; $headerBytes = 0; $tooLarge = false;
            $ch = curl_init($url);
            $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
            $ip = $ips[0]; $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT_MS => (int)(min((float)\app_config('fetch_timeout'), $remaining) * 1000),
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SeoAuditBot/1.0)',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml,text/plain;q=0.8', 'Accept-Language: en,*;q=0.8'],
                CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveIp],
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooLarge) {
                    if (strlen($body) + strlen($chunk) > (int)\app_config('max_response_bytes')) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers, &$headerBytes) {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 65536) { return 0; }
                    if (preg_match('~^HTTP/~i', $line)) { $headers = []; }
                    elseif (strpos($line, ':') !== false) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))][] = trim($value); }
                    return strlen($line);
                },
            ]);
            $ok = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($ok === false) { throw new \RuntimeException($tooLarge ? 'The response exceeded the 2 MB safety limit.' : (stripos($error, 'timed out') !== false ? 'The website took too long to respond.' : 'The website could not be fetched securely. It may be unavailable or blocking automated requests.')); }
            $chain[] = ['url' => $url, 'status' => $status];
            if ($follow && in_array($status, [301,302,303,307,308], true) && isset($headers['location'][0])) { $url = self::resolve($url, $headers['location'][0]); continue; }
            return ['url' => $url, 'status' => $status, 'body' => $body, 'headers' => $headers, 'type' => $headers['content-type'][0] ?? '', 'chain' => $chain];
        }
        throw new \RuntimeException('The website redirected too many times.');
    }
}
