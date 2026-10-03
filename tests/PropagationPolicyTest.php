<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Nyholm\Psr7\Uri;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException;
use Rasuvaeff\Yii3Telemetry\PropagationPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PropagationPolicy::class)]
final class PropagationPolicyTest
{
    public function allowsEverywhereByDefault(): void
    {
        $policy = new PropagationPolicy();

        Assert::true($policy->allows('anything.example'));
        Assert::true($policy->allows(''));
    }

    public function disabledNeverAllows(): void
    {
        $policy = new PropagationPolicy(enabled: false, hosts: ['api.internal']);

        Assert::false($policy->allows('api.internal'));
        Assert::false((new PropagationPolicy(enabled: false))->allows('x.example'));
    }

    public function acceptsAUriAndAString(): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['api.internal']);

        Assert::true($policy->allows(new Uri('https://api.internal:8443/x?y=1')));
        Assert::false($policy->allows(new Uri('https://other.example/x')));
        Assert::true($policy->allows('api.internal'));
    }

    public function exactHostMatchesOnlyThatHost(): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['api.internal']);

        Assert::true($policy->allows('api.internal'));
        Assert::false($policy->allows('x.api.internal'));
        Assert::false($policy->allows('api.internal.evil.example'));
        Assert::false($policy->allows('xapi.internal'));
    }

    public function wildcardMatchesSubdomainsButNotTheApex(): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['*.svc.cluster.local']);

        Assert::true($policy->allows('a.svc.cluster.local'));
        Assert::true($policy->allows('a.b.svc.cluster.local'));
        Assert::false($policy->allows('svc.cluster.local'));
        Assert::false($policy->allows('.svc.cluster.local'));
        Assert::false($policy->allows('asvc.cluster.local'));
        Assert::false($policy->allows('a.svc.cluster.local.evil.example'));
    }

    public function matchingIsCaseInsensitive(): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['API.Internal', '*.SVC.Local']);

        Assert::true($policy->allows('api.INTERNAL'));
        Assert::true($policy->allows('A.svc.LOCAL'));
    }

    public function anyEntryMayMatch(): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['a.example', '*.b.example']);

        Assert::true($policy->allows('a.example'));
        Assert::true($policy->allows('x.b.example'));
        Assert::false($policy->allows('c.example'));
    }

    public function entriesAfterAWildcardAreStillRegistered(): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['*.a.example', 'b.example', '*.c.example']);

        Assert::true($policy->allows('b.example'));
        Assert::true($policy->allows('x.c.example'));

        try {
            new PropagationPolicy(enabled: true, hosts: ['*.a.example', 'bad.*']);
        } catch (InvalidArgumentException) {
            return;
        }

        Assert::fail('expected an InvalidArgumentException');
    }

    public function emptyHostIsRejectedByAnAllowlist(): void
    {
        Assert::false((new PropagationPolicy(enabled: true, hosts: ['*.example']))->allows(''));
        Assert::false((new PropagationPolicy(enabled: true, hosts: ['a.example']))->allows(new Uri('/relative')));
    }

    #[DataProvider('invalidEntries')]
    public function rejectsInvalidEntries(mixed $entry): void
    {
        try {
            new PropagationPolicy(enabled: true, hosts: [$entry]);
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('ropagation host');

            return;
        }

        Assert::fail('expected an InvalidArgumentException');
    }

    public static function invalidEntries(): iterable
    {
        yield 'empty string' => [''];
        yield 'not a string' => [42];
        yield 'bare star' => ['*'];
        yield 'wildcard without suffix' => ['*.'];
        yield 'star in the middle' => ['api.*.internal'];
        yield 'trailing star' => ['api.*'];
        yield 'star without dot' => ['*example'];
        yield 'double wildcard' => ['*.*.example'];
    }

    #[Property(runs: 200)]
    public function wildcardMatchesAnySubdomainButNeverTheApex(string $label, string $apex): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: ['*.' . $apex]);

        Assert::true($policy->allows($label . '.' . $apex));
        Assert::true($policy->allows(strtoupper($label) . '.' . strtoupper($apex)));
        Assert::false($policy->allows($apex));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function wildcardMatchesAnySubdomainButNeverTheApexGenerators(): array
    {
        return [
            'label' => Gen::regex('[a-z0-9]{1,8}'),
            'apex' => Gen::regex('[a-z0-9]{1,8}\.[a-z]{2,6}'),
        ];
    }

    #[Property(runs: 200)]
    public function exactEntryMatchesItselfInAnyCaseAndNothingElse(string $host, string $other): void
    {
        $policy = new PropagationPolicy(enabled: true, hosts: [$host]);

        Assert::true($policy->allows($host));
        Assert::true($policy->allows(strtoupper($host)));
        Assert::same($policy->allows($other), strtolower($other) === $host);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function exactEntryMatchesItselfInAnyCaseAndNothingElseGenerators(): array
    {
        return [
            'host' => Gen::regex('[a-z0-9]{1,6}\.[a-z]{2,4}'),
            'other' => Gen::regex('[a-z0-9]{1,6}\.[a-z]{2,4}'),
        ];
    }
}
