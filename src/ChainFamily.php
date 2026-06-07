<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Protocol families (the `chain_family` field in API responses). Drives capability checks
 * like "does this chain accept contract calls?".
 */
enum ChainFamily: string
{
    case Evm            = 'EVM';
    case Tron           = 'TRON';
    case Solana         = 'SOLANA';
    case XrpLedger      = 'XRP_LEDGER';
    case Ton            = 'TON';
    case BtcUtxo        = 'BTC_UTXO';
    case BtcUtxoTestnet = 'BTC_UTXO_TESTNET';
    case DogecoinUtxo   = 'DOGECOIN_UTXO';
    case BtcCashUtxo    = 'BTC_CASH_UTXO';
    case LitecoinUtxo   = 'LITECOIN_UTXO';

    /**
     * Whether this family accepts the `contract` transaction type.
     */
    public function supportsContractCalls(): bool
    {
        return match ($this) {
            self::Evm, self::Tron, self::Solana, self::Ton => true,
            default => false,
        };
    }
}
