<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Ton;

use Brick\Math\BigInteger;
use CryptoChief\Processing\Exception\CryptoChiefException;
use Olifanton\Interop\Address as OlifantonAddress;
use Olifanton\Interop\Boc\Builder;
use Olifanton\Interop\Boc\Cell;

/**
 * BoC body builders for TEP-74 Jetton transfer, TEP-62 NFT transfer, and the text-comment
 * body. BitString / Cell / Builder / BoC serialization are delegated to `olifanton/interop`;
 * the encoders here only describe the message shape.
 *
 * Returns raw BoC bytes ready to feed into `TransactionsService::signTonCall()`.
 */
final class Messages
{
    /** TEP-74 jetton transfer op. */
    public const OP_JETTON_TRANSFER = 0x0F8A7EA5;

    /** TEP-62 NFT transfer op. */
    public const OP_NFT_TRANSFER = 0x5FCC3D14;

    /** Text-comment op (every wallet renders it as a note on the transfer). */
    public const OP_TEXT_COMMENT = 0x00000000;

    /**
     * Build a TEP-74 jetton transfer body.
     *
     *   transfer#0f8a7ea5
     *     query_id:uint64
     *     amount:(VarUInteger 16)
     *     destination:MsgAddress
     *     response_destination:MsgAddress
     *     custom_payload:(Maybe ^Cell)
     *     forward_ton_amount:(VarUInteger 16)
     *     forward_payload:(Either Cell ^Cell)
     *
     * @param int|string|BigInteger $amount       Jetton amount in base units.
     * @param int|string|BigInteger $forwardTon   Attached TON forwarded to the receiver, nanoTON.
     * @param int|string|BigInteger $queryId      Idempotency token, free to leave at 0.
     * @return string Raw BoC bytes.
     */
    public static function buildJettonTransferBody(
        string $destination,
        string $responseDest,
        int|string|BigInteger $amount,
        int|string|BigInteger $forwardTon = 0,
        ?Cell $forwardPayload = null,
        int|string|BigInteger $queryId = 0,
        ?Cell $customPayload = null,
    ): string {
        $b = (new Builder())
            ->writeUint(self::OP_JETTON_TRANSFER, 32)
            ->writeUint(self::big($queryId), 64)
            ->writeCoins(self::big($amount))
            ->writeAddress(self::parseAddress($destination))
            ->writeAddress(self::parseAddress($responseDest))
            ->writeMaybeRef($customPayload)
            ->writeCoins(self::big($forwardTon))
            ->writeMaybeRef($forwardPayload);

        return self::toBoc($b->cell());
    }

    /**
     * Build a TEP-62 NFT transfer body.
     *
     *   transfer#5fcc3d14
     *     query_id:uint64
     *     new_owner:MsgAddress
     *     response_destination:MsgAddress
     *     custom_payload:(Maybe ^Cell)
     *     forward_amount:(VarUInteger 16)
     *     forward_payload:(Either Cell ^Cell)
     *
     * @param int|string|BigInteger $forwardTon
     * @param int|string|BigInteger $queryId
     */
    public static function buildNftTransferBody(
        string $newOwner,
        string $responseDest,
        int|string|BigInteger $forwardTon = 0,
        ?Cell $forwardPayload = null,
        int|string|BigInteger $queryId = 0,
        ?Cell $customPayload = null,
    ): string {
        $b = (new Builder())
            ->writeUint(self::OP_NFT_TRANSFER, 32)
            ->writeUint(self::big($queryId), 64)
            ->writeAddress(self::parseAddress($newOwner))
            ->writeAddress(self::parseAddress($responseDest))
            ->writeMaybeRef($customPayload)
            ->writeCoins(self::big($forwardTon))
            ->writeMaybeRef($forwardPayload);

        return self::toBoc($b->cell());
    }

    /**
     * Build a text-comment body cell (raw BoC bytes).
     *
     *   text_comment#00000000 text:Snake
     *
     * For texts that fit in 32 + 8*N <= 1023 bits (~123 UTF-8 bytes) the body is one cell.
     * Longer inputs are truncated to that limit; multi-cell snake encoding is not handled.
     */
    public static function buildTextCommentBody(string $text): string
    {
        return self::toBoc(self::buildTextCommentCell($text));
    }

    /**
     * Build a text-comment as a standalone Cell (useful as a `forwardPayload` ref for
     * jetton transfers - the receiver's wallet then shows the comment alongside the
     * incoming jetton).
     */
    public static function buildTextCommentCell(string $text): Cell
    {
        $maxBytes = (1023 - 32) >> 3;
        $body = strlen($text) > $maxBytes ? substr($text, 0, $maxBytes) : $text;

        return (new Builder())
            ->writeUint(self::OP_TEXT_COMMENT, 32)
            ->writeString($body)
            ->cell();
    }

    /**
     * Parse any TON address form (EQ.../UQ.../workchain:hex) into an olifanton Address.
     */
    public static function parseAddress(string $value): OlifantonAddress
    {
        $s = trim($value);
        if ($s === '') {
            throw new CryptoChiefException('cryptochief/ton: empty address');
        }
        try {
            return new OlifantonAddress($s);
        } catch (\Throwable $err) {
            throw new CryptoChiefException('cryptochief/ton: bad address: ' . $err->getMessage());
        }
    }

    /**
     * Serialize a Cell to raw BoC bytes (the form `signTonCall()` base64-encodes for the
     * wire).
     */
    public static function toBoc(Cell $cell): string
    {
        $ui8 = $cell->toBoc();
        $bytes = '';
        $len = (int) $ui8->__get('length');
        for ($i = 0; $i < $len; $i++) {
            $bytes .= chr($ui8[$i]);
        }
        return $bytes;
    }

    private static function big(int|string|BigInteger $v): BigInteger
    {
        if ($v instanceof BigInteger) {
            return $v;
        }
        return BigInteger::of((string) $v);
    }
}
