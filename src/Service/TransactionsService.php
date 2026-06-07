<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Contract\Borsh;
use CryptoChief\Processing\Contract\EvmAbi;
use CryptoChief\Processing\Dto\AnchorCallRequest;
use CryptoChief\Processing\Dto\ContractCall;
use CryptoChief\Processing\Dto\Erc20TransferRequest;
use CryptoChief\Processing\Dto\EvmCallRequest;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\JettonTransferRequest;
use CryptoChief\Processing\Dto\NftTransferRequest;
use CryptoChief\Processing\Dto\SignTransactionRequest;
use CryptoChief\Processing\Dto\SignTransactionResponse;
use CryptoChief\Processing\Dto\SolanaCallRequest;
use CryptoChief\Processing\Dto\TonCallRequest;
use CryptoChief\Processing\Dto\TonCommentRequest;
use CryptoChief\Processing\Dto\TransactionHistoryResponse;
use CryptoChief\Processing\Dto\TransactionInfo;
use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Poll;
use CryptoChief\Processing\Ton\Messages;

/**
 * Two-phase sign / execute for arbitrary merchant-owned transactions, plus one-call
 * helpers for EVM / TRON contracts, Solana Anchor programs, and TON Jetton / NFT / comment
 * transfers.
 *
 * The TON helpers (`jettonTransfer`, `nftTransfer`, `sendTonComment`) build the TEP-74 /
 * TEP-62 / text-comment bodies through `Ton\Messages`. Use `signTonCall(TonCallRequest)`
 * when you already have a pre-built BoC body.
 */
final class TransactionsService extends BaseService
{
    /**
     * Build and sign a transaction WITHOUT broadcasting. The signature has a per-family
     * TTL (EVM 10m, UTXO 15m, TRON 45s, Solana 60s, XRP 90s, TON 300s); call execute()
     * before it elapses.
     */
    public function sign(SignTransactionRequest $req): SignTransactionResponse
    {
        return self::fromWire(SignTransactionResponse::class, $this->post('/v1/transaction/signature', $req));
    }

    /** Broadcast a previously-signed transaction by uuid. */
    public function execute(ExecuteTransactionRequest $req): TransactionInfo
    {
        return self::fromWire(TransactionInfo::class, $this->post('/v1/transaction/execute', $req));
    }

    /** Fetch the current state of one transaction by uuid. */
    public function info(string $uuid): TransactionInfo
    {
        return self::fromWire(TransactionInfo::class, $this->post('/v1/transaction/info', ['uuid' => $uuid]));
    }

    /** Paged list of merchant-owned transactions. */
    public function history(?HistoryQuery $query = null): TransactionHistoryResponse
    {
        return self::fromWire(
            TransactionHistoryResponse::class,
            $this->post('/v1/transaction/history', $query ?? new HistoryQuery())
        );
    }

    /** Poll info until the transaction reaches a terminal state (or timeout). */
    public function waitFor(string $uuid, float $intervalSec = 5.0, float $timeoutSec = 600.0): TransactionInfo
    {
        /** @var TransactionInfo $result */
        $result = Poll::waitForTerminal(
            fn () => $this->info($uuid),
            fn (TransactionInfo $t) => $t->isTerminal(),
            $intervalSec,
            $timeoutSec,
        );
        return $result;
    }

    // -- Contract-call helpers ------------------------------------------------

    /** Sign an EVM/TRON contract call, ABI-encoding `data` from the signature + args. */
    public function signEvmCall(EvmCallRequest $req): SignTransactionResponse
    {
        try {
            $data = EvmAbi::encodeCallHex($req->method, ...$req->args);
        } catch (\Throwable $err) {
            throw new CryptoChiefException("cryptochief: encode call '{$req->method}': " . $err->getMessage());
        }
        return $this->sign(new SignTransactionRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            type: 'contract',
            urlCallback: $req->urlCallback,
            calls: [new ContractCall(
                to: $req->contract,
                data: $data,
                value: self::valueString($req->value),
            )],
        ));
    }

    /** Alias for signEvmCall - TRON shares the EVM ABI encoding. */
    public function signTronCall(EvmCallRequest $req): SignTransactionResponse
    {
        return $this->signEvmCall($req);
    }

    /** One-liner for an ERC-20 / TRC-20 transfer(address,uint256). */
    public function erc20Transfer(Erc20TransferRequest $req): SignTransactionResponse
    {
        return $this->signEvmCall(new EvmCallRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            contract: $req->tokenContract,
            method: 'transfer(address,uint256)',
            args: [$req->recipient, $req->amount],
            urlCallback: $req->urlCallback,
        ));
    }

    /** Sign an Anchor program call (8-byte discriminator + Borsh-encoded args). */
    public function signAnchorCall(AnchorCallRequest $req): SignTransactionResponse
    {
        try {
            $data = Borsh::encodeAnchorInstruction($req->method, ...$req->args);
        } catch (\Throwable $err) {
            throw new CryptoChiefException(
                "cryptochief: encode anchor instruction '{$req->method}': " . $err->getMessage()
            );
        }
        return $this->sign(new SignTransactionRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            type: 'contract',
            urlCallback: $req->urlCallback,
            calls: [new ContractCall(
                to: $req->program,
                data: base64_encode($data),
                accounts: $req->accounts,
            )],
        ));
    }

    /** Sign a non-Anchor Solana program call with raw instruction bytes. */
    public function signSolanaCall(SolanaCallRequest $req): SignTransactionResponse
    {
        return $this->sign(new SignTransactionRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            type: 'contract',
            urlCallback: $req->urlCallback,
            calls: [new ContractCall(
                to: $req->program,
                data: base64_encode($req->instructionData),
                accounts: $req->accounts,
            )],
        ));
    }

    /**
     * Sign a TON contract call from a pre-built BoC body cell. `bodyCell` is raw bytes;
     * the SDK base64-encodes it before sending.
     */
    public function signTonCall(TonCallRequest $req): SignTransactionResponse
    {
        return $this->sign(new SignTransactionRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            type: 'contract',
            urlCallback: $req->urlCallback,
            calls: [new ContractCall(
                to: $req->contract,
                data: base64_encode($req->bodyCell),
                value: self::valueString($req->value),
                bounce: $req->bounce,
            )],
        ));
    }

    // -- TON helpers ----------------------------------------------------------

    /**
     * Transfer Jetton tokens.
     *
     * Builds the TEP-74 transfer body, resolves the sender's Jetton wallet via the TON
     * RPC proxy if not supplied, and picks a gas budget (0.07 TON if the receiver's
     * Jetton wallet already exists, 0.15 TON if a fresh one has to be deployed). Pass
     * `memo` to attach a text comment.
     */
    public function jettonTransfer(JettonTransferRequest $req): SignTransactionResponse
    {
        if ($req->recipient === '') {
            throw new CryptoChiefException('cryptochief: jettonTransfer: recipient required');
        }
        if ($req->jettonMaster === null && $req->jettonWalletAddress === null) {
            throw new CryptoChiefException(
                'cryptochief: jettonTransfer: jettonMaster or jettonWalletAddress required'
            );
        }

        $jettonWallet = $req->jettonWalletAddress
            ?? $this->client->tonRpc()->lookupJettonWallet($req->jettonMaster ?? '', $req->fromAddress);

        $forwardPayload = $req->memo !== null ? Messages::buildTextCommentCell($req->memo) : null;
        $forwardTon = $req->forwardTonAmount;
        if ($forwardTon === null) {
            $forwardTon = $req->memo !== null ? '1' : '0';
        }

        $body = Messages::buildJettonTransferBody(
            destination: $req->recipient,
            responseDest: $req->responseDestination ?? $req->fromAddress,
            amount: $req->amount,
            forwardTon: $forwardTon,
            forwardPayload: $forwardPayload,
            queryId: $req->queryId,
        );

        $attached = $req->attachedTon;
        if ($attached === null) {
            $attached = '150000000'; // 0.15 TON - covers a fresh wallet deploy
            if ($req->jettonMaster !== null) {
                try {
                    if ($this->client->tonRpc()->hasJettonWallet($req->jettonMaster, $req->recipient)) {
                        $attached = '70000000'; // 0.07 TON - receiver wallet already exists
                    }
                } catch (\Throwable) {
                    // keep the conservative default
                }
            }
        }

        return $this->signTonCall(new TonCallRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            contract: $jettonWallet,
            bodyCell: $body,
            value: $attached,
            bounce: true,
            urlCallback: $req->urlCallback,
        ));
    }

    /**
     * Transfer ownership of an NFT item (TEP-62 transfer body).
     */
    public function nftTransfer(NftTransferRequest $req): SignTransactionResponse
    {
        if ($req->nftItem === '' || $req->newOwner === '') {
            throw new CryptoChiefException('cryptochief: nftTransfer: nftItem and newOwner required');
        }
        $body = Messages::buildNftTransferBody(
            newOwner: $req->newOwner,
            responseDest: $req->responseDestination ?? $req->fromAddress,
            forwardTon: $req->forwardTonAmount ?? '0',
            queryId: $req->queryId,
        );
        return $this->signTonCall(new TonCallRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            contract: $req->nftItem,
            bodyCell: $body,
            value: $req->attachedTon ?? '50000000', // 0.05 TON
            bounce: true,
            urlCallback: $req->urlCallback,
        ));
    }

    /**
     * Send TON with a text comment (the note every wallet displays).
     */
    public function sendTonComment(TonCommentRequest $req): SignTransactionResponse
    {
        if ($req->recipient === '') {
            throw new CryptoChiefException('cryptochief: sendTonComment: recipient required');
        }
        $body = Messages::buildTextCommentBody($req->text);
        return $this->signTonCall(new TonCallRequest(
            network: $req->network,
            fromAddress: $req->fromAddress,
            contract: $req->recipient,
            bodyCell: $body,
            value: $req->amountTon ?? '0',
            bounce: false,
            urlCallback: $req->urlCallback,
        ));
    }

    private static function valueString(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        return (string) $value;
    }
}
