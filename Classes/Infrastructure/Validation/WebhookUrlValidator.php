<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Infrastructure\Validation;

/**
 * Shared SSRF validation for webhook URLs.
 *
 * Ensures the URL uses HTTPS (or HTTP only when explicitly allowed),
 * does not resolve to localhost, and does not target private IP ranges.
 */
final class WebhookUrlValidator
{
    /**
     * @param array<string, mixed> $options
     */
    public static function isValid(string $url, array $options = []): bool
    {
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'], $parsed['scheme'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if ('https' !== $scheme && !('http' === $scheme && (bool) ($options['allowInsecure'] ?? false))) {
            return false;
        }

        $host = strtolower($parsed['host']);
        // strip RFC-3986 IPv6-literal brackets so FILTER_VALIDATE_IP /
        // gethostbynamel() see the bare address
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
        if (in_array($host, $blockedHosts, true)) {
            return false;
        }

        // If the host is already an IP literal, the previous gethostbyname()
        // round-tripped it unchanged and silently bypassed the private-IP
        // check (172.16.0.1, 169.254.169.254, ::1, fe80::, …). Validate the
        // literal up front before any DNS lookup.
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return !self::isPrivateIp($host);
        }

        // For hostnames: walk ALL A-records, not just the first one
        // gethostbyname() returns. A single private record in a round-robin
        // pool defeats the gate; checking every record also reduces (but
        // does not eliminate) the DNS-rebinding TOCTOU window — callers
        // should additionally pin to the validated IP via CURLOPT_RESOLVE
        // and disable Guzzle redirect-following or re-validate on redirect.
        $ipv4List = gethostbynamel($host);
        if (false === $ipv4List || [] === $ipv4List) {
            // Unresolvable host — refuse rather than allow the request and
            // let curl fail later; would otherwise leak DNS-probe behaviour.
            return false;
        }
        foreach ($ipv4List as $ipv4) {
            if (self::isPrivateIp($ipv4)) {
                return false;
            }
        }

        $dnsRecords = dns_get_record($host, DNS_AAAA);
        if (is_array($dnsRecords)) {
            foreach ($dnsRecords as $record) {
                $ipv6 = $record['ipv6'] ?? '';
                if ('' !== $ipv6 && self::isPrivateIp($ipv6)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
