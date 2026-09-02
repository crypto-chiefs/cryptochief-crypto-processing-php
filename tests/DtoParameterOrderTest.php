<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Dto\AvailableContract;
use CryptoChief\Processing\Dto\SweepHistoryQuery;
use CryptoChief\Processing\Dto\SweepOverride;
use CryptoChief\Processing\Dto\SweepPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Promoted constructor parameters are positional as well as named, so their ORDER is part
 * of the published API. A field added in the middle silently re-binds every positional
 * argument after it - a TypeError under `declare(strict_types=1)`, and quietly wrong data
 * without it.
 *
 * These tests pin the 0.6.0 prefix of each constructor this release touched. Every
 * parameter added in 0.7.0 belongs after them, so a caller who never opened the changelog
 * keeps the meaning they wrote.
 */
final class DtoParameterOrderTest extends TestCase
{
    /**
     * @param class-string $class
     * @return string[]
     */
    private static function paramNames(string $class): array
    {
        $ctor = (new ReflectionClass($class))->getConstructor();
        self::assertNotNull($ctor);

        return array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ctor->getParameters()
        );
    }

    /**
     * @param class-string $class
     * @param string[] $prefix
     */
    private static function assertStartsWithParams(string $class, array $prefix): void
    {
        self::assertSame(
            $prefix,
            array_slice(self::paramNames($class), 0, count($prefix)),
            $class . ': parameters added in 0.7.0 must go after the 0.6.0 ones'
        );
    }

    public function testSweepHistoryQueryKeepsThe060PositionalPrefix(): void
    {
        self::assertStartsWithParams(SweepHistoryQuery::class, ['mode', 'page', 'pageSize']);

        // Exactly what a 0.6.0 caller wrote. `2` is the page and `50` the page size; if
        // `status` and `search` had been inserted after `mode` this would bind 2 to
        // `status` and blow up on the int-to-?string mismatch.
        $q = new SweepHistoryQuery('auto', 2, 50);

        self::assertSame('auto', $q->mode);
        self::assertSame(2, $q->page);
        self::assertSame(50, $q->pageSize);
        self::assertNull($q->status);
        self::assertNull($q->search);

        self::assertSame(
            ['mode' => 'auto', 'page' => 2, 'page_size' => 50],
            $q->toWire()
        );
    }

    public function testSweepHistoryQueryStillTakesTheNewFiltersByName(): void
    {
        $q = new SweepHistoryQuery(status: 'failed', search: '0xdeadbeef', pageSize: 100);

        self::assertSame(
            ['page_size' => 100, 'status' => 'failed', 'search' => '0xdeadbeef'],
            $q->toWire()
        );
    }

    public function testAvailableContractKeepsThe060PositionalPrefix(): void
    {
        self::assertStartsWithParams(
            AvailableContract::class,
            ['network', 'coin', 'contract', 'type', 'decimals']
        );

        // A 0.6.0 caller's five positional arguments. With `chainFamily` and `isTest`
        // inserted before `type`, `'native'` would land on `chainFamily` and `18` on
        // `isTest` - an int against `?bool`, so a TypeError here and a wrong `decimals`
        // of 0 in any file without strict types.
        $c = new AvailableContract('ETH_MAINNET', 'ETH', '', 'native', 18);

        self::assertSame('ETH_MAINNET', $c->network);
        self::assertSame('ETH', $c->coin);
        self::assertSame('', $c->contract);
        self::assertSame('native', $c->type);
        self::assertSame(18, $c->decimals);
        self::assertNull($c->chainFamily);
        self::assertNull($c->isTest);
    }

    public function testSweepPolicyKeepsThe060PositionalPrefix(): void
    {
        self::assertStartsWithParams(
            SweepPolicy::class,
            ['typeWork', 'thresholdAmountUsd', 'feeMode', 'source']
        );

        $p = new SweepPolicy('threshold', '250', 'mix', 'wallet');

        self::assertSame('wallet', $p->source);
        self::assertNull($p->gasSource);
    }

    public function testSweepOverrideKeepsThe060PositionalPrefix(): void
    {
        self::assertStartsWithParams(
            SweepOverride::class,
            ['networkCode', 'typeWork', 'thresholdAmountUsd', 'feeMode', 'source', 'locked']
        );

        $o = new SweepOverride('', 'threshold', '250', 'mix', 'merchant', true);

        self::assertSame('merchant', $o->source);
        self::assertTrue($o->locked);
        self::assertNull($o->gasSource);
    }

    /**
     * Methods have the same problem as constructors. `updateSettings()` grew `gasSource`
     * in 0.7.0, and it has to sit behind `networkCode` rather than beside the other three
     * policy fields it belongs with.
     */
    public function testUpdateSettingsKeepsThe060PositionalPrefix(): void
    {
        $method = new \ReflectionMethod(
            \CryptoChief\Processing\Service\SweepsService::class,
            'updateSettings'
        );
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        self::assertSame(
            ['address', 'typeWork', 'thresholdAmountUsd', 'feeMode', 'networkCode'],
            array_slice($names, 0, 5)
        );
        self::assertSame('gasSource', $names[5] ?? null);
    }

    /**
     * Wire decoding is by name, so the reorder must not move a single byte of the request
     * or response mapping. Canonical JSON sorts its keys anyway, which is why moving a
     * parameter is safe at all.
     */
    public function testReorderDoesNotChangeWireDecoding(): void
    {
        $c = AvailableContract::fromWire([
            'network' => 'BSC_TESTNET',
            'coin' => 'BNB',
            'contract' => '',
            'chain_family' => 'EVM',
            'type' => 'native',
            'is_test' => true,
            'decimals' => 18,
        ]);

        self::assertSame('BSC_TESTNET', $c->network);
        self::assertSame('native', $c->type);
        self::assertSame(18, $c->decimals);
        self::assertSame('EVM', $c->chainFamily);
        self::assertTrue($c->isTest);

        $o = SweepOverride::fromWire([
            'network_code' => '',
            'type_work' => 'threshold',
            'threshold_amount_usd' => '250',
            'fee_mode' => null,
            'gas_source' => 'native',
            'source' => 'merchant',
            'locked' => false,
        ]);

        self::assertSame('threshold', $o->typeWork);
        self::assertNull($o->feeMode);
        self::assertSame('native', $o->gasSource);
        self::assertSame('merchant', $o->source);
        self::assertFalse($o->locked);
    }
}
