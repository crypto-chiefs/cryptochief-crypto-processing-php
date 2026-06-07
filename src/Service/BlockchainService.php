<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\AvailableContractsResponse;
use CryptoChief\Processing\Dto\TxStatusRow;
use CryptoChief\Processing\Dto\WalletBalanceRow;

/**
 * Read-only on-chain queries: enabled assets, balances, tx status.
 */
final class BlockchainService extends BaseService
{
    /**
     * Coins / tokens this project may use. Pass a `network` to scope to one chain, or
     * omit for the full set. Each row's `decimals` is what `Amount::humanToBase` /
     * `Amount::baseToHuman` need.
     */
    public function contractsAvailable(?string $network = null): AvailableContractsResponse
    {
        $body = $network !== null ? ['network' => $network] : [];
        return self::fromWire(
            AvailableContractsResponse::class,
            $this->post('/v1/blockchain/contracts/available', $body)
        );
    }

    /**
     * Native + token balances for one or more addresses.
     *
     * @param string[] $addresses
     * @param string[]|null $contracts
     * @return WalletBalanceRow[]
     */
    public function walletBalance(string $chain, array $addresses, ?array $contracts = null): array
    {
        $body = ['chain' => $chain, 'addresses' => $addresses];
        if ($contracts !== null && $contracts !== []) {
            $body['contracts'] = $contracts;
        }
        return self::fromWireList(WalletBalanceRow::class, $this->post('/v1/blockchain/wallet/balance', $body));
    }

    /**
     * Current on-chain state of a transaction by hash.
     *
     * @return TxStatusRow[]
     */
    public function transactionStatus(string $chain, string $txHash): array
    {
        return self::fromWireList(
            TxStatusRow::class,
            $this->post('/v1/blockchain/transaction/status', ['chain' => $chain, 'hash' => $txHash])
        );
    }
}
