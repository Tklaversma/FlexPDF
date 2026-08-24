<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

/**
 * Whether, and from where, a document may fetch an image over the network.
 *
 * **Off by default, and an empty allowlist with the feature on is a
 * configuration error rather than allow-all.** A URL in a document is
 * author-controlled, so fetching one is an SSRF sink: the request leaves from
 * the operator's network, with the operator's routing, and the document
 * chooses where it goes. Every control below exists because of a way that goes
 * wrong, and the whole of it is refused rather than best-effort.
 *
 * `https` only, an exact host match, a refusal of every private, loopback,
 * link-local and reserved address, a connection to the address that was
 * checked rather than to the name, no redirect following, a size cap enforced
 * while reading rather than from a header, the render's own wall clock, and
 * the bytes sniffed rather than the type header believed.
 *
 * Only `<img src>` is ever fetched. A stylesheet is a second document and a
 * font is glyph data copied into the output; both stay local.
 */
final readonly class RemoteImages
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        public bool $enabled = false,
        public array $allowedHosts = [],
        public int $maxBytes = 2_000_000,
        public float $timeout = 5.0,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $defaults = new self();

        /** @var list<string> $hosts */
        $hosts = array_values(
            array_filter(
                array_map(
                    static fn(mixed $host): string => strtolower(trim((string) $host)),
                    (array) ($config['allowed_hosts'] ?? []),
                ),
                static fn(string $host): bool => $host !== '',
            ),
        );

        return new self(
            enabled     : (bool) ($config['enabled'] ?? $defaults->enabled),
            allowedHosts: $hosts,
            maxBytes    : (int) ($config['max_bytes'] ?? $defaults->maxBytes),
            timeout     : (float) ($config['timeout'] ?? $defaults->timeout),
        );
    }

    /**
     * The bytes of a remote image, or null where the candidate is refused.
     *
     * Every refusal returns null rather than throwing: a document naming an
     * image the operator will not fetch is a document with a missing image,
     * which the engine already draws as a placeholder.
     */
    public function fetch(string $url, ?Deadline $deadline = null): ?string
    {
        if (!$this->enabled || $this->allowedHosts === []) {
            return null;
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
        ) {
            return null;
        }

        $host = strtolower($parts['host']);

        // An exact match. A suffix comparison would let `evil-example.com`
        // through an allowlist that names `example.com`.
        if (!in_array($host, $this->allowedHosts, true)) {
            return null;
        }

        $address = $this->resolve($host);

        if ($address === null) {
            return null;
        }

        return $this->read($url, $host, $address, $deadline);
    }

    /**
     * The one address this host resolves to, refused unless it is public.
     *
     * The check and the connection have to agree, so the address is resolved
     * once here and connected to below: resolving twice is a rebinding
     * window, and handing the name to a stream wrapper is resolving twice.
     */
    private function resolve(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublic($host) ? $host : null;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return null;
        }

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && self::isPublic($address)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Whether an address is one the operator's network would not mind a
     * document reaching.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE` and `NO_RES_RANGE` cover RFC 1918, loopback,
     * link-local (169.254.0.0/16, which is where a cloud metadata service
     * lives) and the reserved blocks, for IPv4 and IPv6 alike. An
     * IPv4-mapped IPv6 address is unwrapped first, because the flags do not
     * see through the mapping.
     */
    private static function isPublic(string $address): bool
    {
        if (str_starts_with(strtolower($address), '::ffff:')) {
            $address = substr($address, 7);
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * One request, to the address already checked, with the body capped while
     * it is read.
     *
     * A redirect is not followed: it is a second URL the allowlist never saw.
     * `Content-Length` is the server's claim and is not trusted; the read
     * stops at `maxBytes` whatever the header said.
     */
    private function read(string $url, string $host, string $address, ?Deadline $deadline): ?string
    {
        $budget = $deadline === null ? $this->timeout : min($this->timeout, $deadline->remaining());

        if ($budget <= 0.0) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 0,
                'max_redirects'   => 0,
                'timeout'         => $budget,
                'ignore_errors'   => true,
                'header'          => "Host: {$host}\r\nAccept: image/*\r\nConnection: close\r\n",
            ],
            'ssl'  => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'peer_name'         => $host,
                'allow_self_signed' => false,
            ],
        ]);

        // The connection goes to the address that was checked; the name
        // travels in the Host header and in the certificate check above.
        $target = str_contains($address, ':')
            ? str_replace('://' . $host, '://[' . $address . ']', $url)
            : str_replace('://' . $host, '://' . $address, $url);

        $handle = @fopen($target, 'rb', false, $context);

        if ($handle === false) {
            return null;
        }

        $status = self::statusOf($http_response_header ?? []);
        $bytes  = '';

        while ($status === 200 && !feof($handle) && strlen($bytes) <= $this->maxBytes) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                break;
            }

            $bytes .= $chunk;
        }

        fclose($handle);

        if ($status !== 200 || $bytes === '' || strlen($bytes) > $this->maxBytes) {
            return null;
        }

        return self::looksLikeImage($bytes) ? $bytes : null;
    }

    /** @param list<string> $headers */
    private static function statusOf(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * The bytes rather than the header, because the header is the server's
     * claim and the decoder is what has to survive them.
     */
    private static function looksLikeImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            || str_starts_with($bytes, "\xff\xd8\xff")
            || str_starts_with($bytes, 'GIF87a')
            || str_starts_with($bytes, 'GIF89a')
            || (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP'))
            || preg_match('/^\s*(<\?xml|<svg)/i', substr($bytes, 0, 64)) === 1;
    }
}
