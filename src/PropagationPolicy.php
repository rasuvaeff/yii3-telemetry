<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Http\Message\UriInterface;

/**
 * Decides whether the trace context (`traceparent`) may be injected into an
 * outgoing request to a given host.
 *
 * - `enabled: false` — never inject;
 * - `enabled: true` with no hosts — inject everywhere;
 * - `enabled: true` with hosts — inject only when the request host matches an
 *   entry: an exact host (`api.internal`) or a leading wildcard
 *   (`*.svc.cluster.local`, which matches subdomains only, never the apex).
 *
 * Matching is case-insensitive. The span itself is recorded regardless.
 *
 * @api
 */
final readonly class PropagationPolicy
{
    private const string WILDCARD_PREFIX = '*.';

    /** @var list<string> lower-case exact hosts */
    private array $exact;

    /** @var list<string> lower-case suffixes, each with the leading dot (`.svc.local`) */
    private array $suffixes;

    /**
     * @param list<mixed> $hosts exact hosts (`api.internal`) or `*.suffix` wildcards; every entry is validated
     *
     * @throws Exception\InvalidArgumentException on an empty entry, or a `*` that is not a leading `*.`
     */
    public function __construct(
        private bool $enabled = true,
        array $hosts = [],
    ) {
        $exact = [];
        $suffixes = [];

        foreach ($hosts as $host) {
            if (!\is_string($host) || $host === '') {
                throw new Exception\InvalidArgumentException('Propagation host must be a non-empty string');
            }

            $host = strtolower($host);

            if (str_starts_with($host, self::WILDCARD_PREFIX)) {
                $suffix = substr($host, 1);

                if ($suffix === '.' || str_contains($suffix, '*')) {
                    throw new Exception\InvalidArgumentException(\sprintf('Invalid propagation host pattern "%s"', $host));
                }

                $suffixes[] = $suffix;

                continue;
            }

            if (str_contains($host, '*')) {
                throw new Exception\InvalidArgumentException(
                    \sprintf('Invalid propagation host pattern "%s": "*" is allowed only as a leading "*."', $host),
                );
            }

            $exact[] = $host;
        }

        $this->exact = $exact;
        $this->suffixes = $suffixes;
    }

    public function allows(UriInterface|string $host): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->exact === [] && $this->suffixes === []) {
            return true;
        }

        $host = strtolower($host instanceof UriInterface ? $host->getHost() : $host);

        if (\in_array($host, $this->exact, strict: true)) {
            return true;
        }

        foreach ($this->suffixes as $suffix) {
            if (\strlen($host) > \strlen($suffix) && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
