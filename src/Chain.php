<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Chain codes the API currently supports. The enum value matches the wire string, so
 * passing Chain::EthSepolia where a string is expected works through `->value`. Any plain
 * string is accepted too, so new chains work before this SDK is updated.
 */
enum Chain: string
{
    case EthMainnet         = 'ETH_MAINNET';
    case EthSepolia         = 'ETH_SEPOLIA';
    case BscMainnet         = 'BSC_MAINNET';
    case BscTestnet         = 'BSC_TESTNET';
    case PolygonMainnet     = 'POLYGON_MAINNET';
    case PolygonAmoy        = 'POLYGON_AMOY';
    case ArbitrumOne        = 'ARBITRUM_ONE';
    case ArbitrumSepolia    = 'ARBITRUM_SEPOLIA';
    case OptimismMainnet    = 'OPTIMISM_MAINNET';
    case OptimismSepolia    = 'OPTIMISM_SEPOLIA';
    case AvaxMainnet        = 'AVAX_MAINNET';
    case AvaxTestnet        = 'AVAX_TESTNET';

    case BtcMainnet         = 'BTC_MAINNET';
    case BtcTestnet4        = 'BTC_TESTNET_4';
    case LitecoinMainnet    = 'LITECOIN_MAINNET';
    case BitcoinCashMainnet = 'BITCOIN_CASH_MAINNET';
    case DogecoinMainnet    = 'DOGECOIN_MAINNET';

    case TronMainnet        = 'TRON_MAINNET';
    case TronNile           = 'TRON_NILE';

    case SolanaMainnet      = 'SOLANA_MAINNET';
    case SolanaDevnet       = 'SOLANA_DEVNET';

    case TonMainnet         = 'TON_MAINNET';
    case TonTestnet         = 'TON_TESTNET';

    case XrpMainnet         = 'XRP_MAINNET';
    case XrpTestnet         = 'XRP_TESTNET';

    /**
     * Protocol family for this chain, or null if unrecognized.
     */
    public function family(): ?ChainFamily
    {
        return self::familyOf($this->value);
    }

    public static function familyOf(string $chain): ?ChainFamily
    {
        return match ($chain) {
            'ETH_MAINNET', 'ETH_SEPOLIA',
            'BSC_MAINNET', 'BSC_TESTNET',
            'POLYGON_MAINNET', 'POLYGON_AMOY',
            'ARBITRUM_ONE', 'ARBITRUM_SEPOLIA',
            'OPTIMISM_MAINNET', 'OPTIMISM_SEPOLIA',
            'AVAX_MAINNET', 'AVAX_TESTNET' => ChainFamily::Evm,
            'BTC_MAINNET'                  => ChainFamily::BtcUtxo,
            'BTC_TESTNET_4'                => ChainFamily::BtcUtxoTestnet,
            'LITECOIN_MAINNET'             => ChainFamily::LitecoinUtxo,
            'BITCOIN_CASH_MAINNET'         => ChainFamily::BtcCashUtxo,
            'DOGECOIN_MAINNET'             => ChainFamily::DogecoinUtxo,
            'TRON_MAINNET', 'TRON_NILE'    => ChainFamily::Tron,
            'SOLANA_MAINNET', 'SOLANA_DEVNET' => ChainFamily::Solana,
            'TON_MAINNET', 'TON_TESTNET'   => ChainFamily::Ton,
            'XRP_MAINNET', 'XRP_TESTNET'   => ChainFamily::XrpLedger,
            default                        => null,
        };
    }
}
