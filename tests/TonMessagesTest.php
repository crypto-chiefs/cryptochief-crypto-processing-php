<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use Brick\Math\BigInteger;
use CryptoChief\Processing\Ton\Messages;
use Olifanton\Interop\Boc\Cell;
use PHPUnit\Framework\TestCase;

/**
 * BoC body shape checks. Each test serializes a body, parses it back with olifanton's
 * decoder, and asserts the op code + key fields.
 */
final class TonMessagesTest extends TestCase
{
    private const USDT_JETTON_MASTER = 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';
    private const SOME_WALLET        = 'EQCD39VS5jcptHL8vMjEXrzGaRcCVYto7HUn4bpAOg8xqB2N';
    private const ANOTHER_WALLET     = 'EQBvI0aFLnw2QbZgjMPCLRdtRHxhUyinQudg6sdiohIwg5jL';

    public function testJettonTransferBodyShape(): void
    {
        $body = Messages::buildJettonTransferBody(
            destination: self::SOME_WALLET,
            responseDest: self::ANOTHER_WALLET,
            amount: '1000000', // 1 USDT (6 decimals)
            forwardTon: '1',
            queryId: 42,
        );

        $slice = Cell::oneFromBoc(bin2hex($body))->beginParse();

        $op = $slice->loadUint(32);
        $queryId = $slice->loadUint(64);
        $amount = $slice->loadCoins();
        $destination = $slice->loadAddress();
        $responseDest = $slice->loadAddress();
        $hasCustomPayload = $slice->loadBit();
        $forwardTon = $slice->loadCoins();
        $hasForwardPayload = $slice->loadBit();

        self::assertSame(0x0F8A7EA5, (int) $op->toInt(), 'op should be TEP-74 transfer');
        self::assertSame(42, (int) $queryId->toInt());
        self::assertSame('1000000', $amount->__toString());
        self::assertNotNull($destination);
        self::assertNotNull($responseDest);
        self::assertFalse($hasCustomPayload);
        self::assertSame('1', $forwardTon->__toString());
        self::assertFalse($hasForwardPayload);
    }

    public function testJettonTransferWithMemoEncodesForwardPayload(): void
    {
        $body = Messages::buildJettonTransferBody(
            destination: self::SOME_WALLET,
            responseDest: self::ANOTHER_WALLET,
            amount: BigInteger::of('123456'),
            forwardTon: '1',
            forwardPayload: Messages::buildTextCommentCell('thanks for the coffee'),
        );

        $slice = Cell::oneFromBoc(bin2hex($body))->beginParse();
        // skip op (32) + query_id (64) + amount (coins) + 2 addresses + maybe(custom) + forward_ton (coins)
        $slice->loadUint(32);
        $slice->loadUint(64);
        $slice->loadCoins();
        $slice->loadAddress();
        $slice->loadAddress();
        $slice->loadBit();
        $slice->loadCoins();
        $hasForwardPayload = $slice->loadBit();
        self::assertTrue($hasForwardPayload, 'memo path must set the forward_payload Maybe bit');

        $payloadSlice = $slice->loadRef()->beginParse();
        $payloadOp = $payloadSlice->loadUint(32);
        self::assertSame(0x00000000, (int) $payloadOp->toInt(), 'forward payload should be a text-comment cell');
    }

    public function testNftTransferBodyShape(): void
    {
        $body = Messages::buildNftTransferBody(
            newOwner: self::SOME_WALLET,
            responseDest: self::ANOTHER_WALLET,
            forwardTon: '0',
            queryId: 7,
        );
        $slice = Cell::oneFromBoc(bin2hex($body))->beginParse();
        $op = $slice->loadUint(32);
        $queryId = $slice->loadUint(64);
        self::assertSame(0x5FCC3D14, (int) $op->toInt(), 'op should be TEP-62 transfer');
        self::assertSame(7, (int) $queryId->toInt());
    }

    public function testTextCommentBody(): void
    {
        $body = Messages::buildTextCommentBody('hello from cryptochief-php');
        $slice = Cell::oneFromBoc(bin2hex($body))->beginParse();
        $op = $slice->loadUint(32);
        self::assertSame(0x00000000, (int) $op->toInt());
    }

    public function testAddressParsingAcceptsEqForm(): void
    {
        $addr = Messages::parseAddress(self::USDT_JETTON_MASTER);
        self::assertSame(0, $addr->getWorkchain());
    }
}
