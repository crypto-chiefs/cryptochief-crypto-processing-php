<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Dto\Asset;
use CryptoChief\Processing\Dto\AssetsPolicy;
use CryptoChief\Processing\Dto\EstimatePayoutRequest;
use CryptoChief\Processing\Dto\EstimatePayoutResponse;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\PayoutFeeInfo;
use CryptoChief\Processing\Sign;
use PHPUnit\Framework\TestCase;

final class BaseDtoTest extends TestCase
{
    public function testToWireConvertsCamelCaseAndDropsNulls(): void
    {
        $req = new EstimatePayoutRequest(
            network: 'ETH_SEPOLIA',
            coin: 'ETH',
            amount: '0.0001',
            toAddress: '0xAbC',
            fromAddresses: ['0x111'],
        );
        $wire = $req->toWire();
        self::assertSame('ETH_SEPOLIA', $wire['network']);
        self::assertSame('0xAbC', $wire['to_address']);
        self::assertSame(['0x111'], $wire['from_addresses']);
        self::assertArrayNotHasKey('auto_convert', $wire);
        self::assertArrayNotHasKey('memo', $wire);
    }

    public function testToWireRecursesNestedDtoAndArrays(): void
    {
        $req = new EstimatePayoutRequest(
            network: 'ETH_SEPOLIA',
            coin: 'ETH',
            amount: '0.0001',
            toAddress: '0xAbC',
            autoConvert: true,
            autoConvertPolicy: new AssetsPolicy(
                allow: [new Asset(network: 'ETH_MAINNET', coin: 'USDT')],
            ),
        );
        $wire = $req->toWire();
        self::assertTrue($wire['auto_convert']);
        self::assertSame(
            ['allow' => [['network' => 'ETH_MAINNET', 'coin' => 'USDT']]],
            $wire['auto_convert_policy'],
        );
    }

    public function testFromWireCoercesNestedDto(): void
    {
        $resp = EstimatePayoutResponse::fromWire([
            'network' => 'ETH_SEPOLIA',
            'amount_to_receive' => '0.00099',
            'fee_info' => [
                'fee_mode' => 'service',
                'estimated_fiat' => '0.05',
            ],
        ]);
        self::assertSame('ETH_SEPOLIA', $resp->network);
        self::assertSame('0.00099', $resp->amountToReceive);
        self::assertInstanceOf(PayoutFeeInfo::class, $resp->feeInfo);
        self::assertSame('service', $resp->feeInfo->feeMode);
    }

    public function testFromWireToleratesUnknownKeys(): void
    {
        // Forward-compat with new server fields.
        $resp = EstimatePayoutResponse::fromWire([
            'network' => 'ETH_SEPOLIA',
            'amount_to_receive' => '0.0099',
            'brand_new_field_added_later' => 'foo',
        ]);
        self::assertSame('ETH_SEPOLIA', $resp->network);
        self::assertSame('0.0099', $resp->amountToReceive);
    }

    public function testHistoryQueryEmpty(): void
    {
        $q = new HistoryQuery();
        self::assertSame([], $q->toWire());
    }

    public function testHistoryQueryPartial(): void
    {
        $q = new HistoryQuery(page: 2, pageSize: 50, status: 'paid');
        self::assertSame(['page' => 2, 'page_size' => 50, 'status' => 'paid'], $q->toWire());
    }

    public function testDtoCanonicalizes(): void
    {
        $req = new EstimatePayoutRequest(
            network: 'ETH_SEPOLIA',
            coin: 'ETH',
            amount: '0.0001',
            toAddress: '0xAbC',
            fromAddresses: ['0x111', '0x222'],
        );
        // Should produce the same canonical JSON as the bare array vector in SignTest.
        self::assertSame(
            '{"amount":"0.0001","coin":"ETH","from_addresses":["0x111","0x222"],'
            . '"network":"ETH_SEPOLIA","to_address":"0xAbC"}',
            Sign::canonicalJson($req)
        );
    }
}
