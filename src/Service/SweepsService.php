<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Clear;
use CryptoChief\Processing\Dto\ForceSweepResponse;
use CryptoChief\Processing\Dto\SweepHistoryQuery;
use CryptoChief\Processing\Dto\SweepHistoryResponse;
use CryptoChief\Processing\Dto\SweepSettings;
use CryptoChief\Processing\SweepFeeMode;
use CryptoChief\Processing\SweepPolicyMode;

/**
 * Treasury sweeps (transit -> master).
 */
final class SweepsService extends BaseService
{
    /**
     * Trigger an immediate transit->master sweep for one address. The status
     * acknowledges acceptance; the resulting Sweep record appears via walletHistory
     * once the on-chain tx is built.
     */
    public function force(string $address, string $network): ForceSweepResponse
    {
        return self::fromWire(
            ForceSweepResponse::class,
            $this->post('/v1/sweeps/force', ['address' => $address, 'network_code' => $network])
        );
    }

    /** Recent sweeps across the whole project. */
    public function history(?SweepHistoryQuery $query = null): SweepHistoryResponse
    {
        return self::fromWire(
            SweepHistoryResponse::class,
            $this->post('/v1/sweeps/history', $query ?? new SweepHistoryQuery())
        );
    }

    /** Recent sweeps scoped to one wallet. */
    public function walletHistory(string $address, ?SweepHistoryQuery $query = null): SweepHistoryResponse
    {
        $body = ['address' => $address];
        if ($query !== null) {
            if ($query->mode !== null) {
                $body['mode'] = $query->mode;
            }
            if ($query->page !== null) {
                $body['page'] = $query->page;
            }
            if ($query->pageSize !== null) {
                $body['page_size'] = $query->pageSize;
            }
        }
        return self::fromWire(
            SweepHistoryResponse::class,
            $this->post('/v1/sweeps/wallet/history', $body)
        );
    }

    /**
     * The auto-sweep policy in force for one wallet, together with what it overrides and
     * what it inherits. Omitting the address asks for the project's own default rather
     * than any wallet's policy.
     *
     * Scoped to the caller's own wallets: an address that is not the project's answers
     * `WALLET_NOT_FOUND`.
     */
    public function settings(?string $address = null, ?string $networkCode = null): SweepSettings
    {
        $body = [];
        if ($address !== null && $address !== '') {
            $body['address'] = $address;
        }
        if ($networkCode !== null && $networkCode !== '') {
            $body['network_code'] = $networkCode;
        }
        return self::fromWire(SweepSettings::class, $this->post('/v1/sweeps/settings', $body));
    }

    /**
     * Write a wallet's auto-sweep policy. Returns the settings as they stand afterwards,
     * so the caller sees what the write resolved to without asking again.
     *
     * `null` leaves a field alone. {@see Clear::value()} stops overriding it and goes back
     * to inheriting - the only way to drop one field while keeping the others. The API
     * expresses that by naming the field with no value, which `null` cannot say here
     * because it already means "not supplied".
     *
     * Refusals are named: `TYPE_WORK_INVALID`, `FEE_MODE_INVALID`, `THRESHOLD_INVALID`,
     * `THRESHOLD_MUST_BE_POSITIVE`, `THRESHOLD_REQUIRED_FOR_THRESHOLD_MODE`, and
     * `SWEEP_SETTINGS_LOCKED` when an operator has pinned the policy.
     */
    public function updateSettings(
        string $address,
        SweepPolicyMode|string|Clear|null $typeWork = null,
        string|Clear|null $thresholdAmountUsd = null,
        SweepFeeMode|string|Clear|null $feeMode = null,
        ?string $networkCode = null,
    ): SweepSettings {
        $body = ['address' => $address];
        if ($networkCode !== null && $networkCode !== '') {
            $body['network_code'] = $networkCode;
        }

        $fields = [];
        foreach ([
            'type_work' => $typeWork,
            'threshold_amount_usd' => $thresholdAmountUsd,
            'fee_mode' => $feeMode,
        ] as $wireName => $value) {
            if ($value === null) {
                continue;
            }
            $fields[] = $wireName;
            if ($value instanceof Clear) {
                continue;
            }
            $body[$wireName] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        if ($fields !== []) {
            $body['fields'] = $fields;
        }

        return self::fromWire(SweepSettings::class, $this->post('/v1/sweeps/settings/update', $body));
    }
}
