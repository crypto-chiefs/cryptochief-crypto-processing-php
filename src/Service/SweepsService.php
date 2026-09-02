<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Clear;
use CryptoChief\Processing\Dto\ForceSweepResponse;
use CryptoChief\Processing\Dto\SweepHistoryQuery;
use CryptoChief\Processing\Dto\SweepHistoryResponse;
use CryptoChief\Processing\Dto\SweepSettings;
use CryptoChief\Processing\SweepFeeMode;
use CryptoChief\Processing\SweepGasSource;
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

    /**
     * Recent sweeps across the whole project. Filter with `mode`, one `status`, and
     * `search` - a substring of the wallet address, either transaction hash, or the
     * `task_id`. An absent `status` includes every status, `skipped` among them.
     */
    public function history(?SweepHistoryQuery $query = null): SweepHistoryResponse
    {
        return self::fromWire(
            SweepHistoryResponse::class,
            $this->post('/v1/sweeps/history', $query ?? new SweepHistoryQuery())
        );
    }

    /**
     * Recent sweeps scoped to one wallet. Same filters as {@see self::history()}, except
     * that `search` matches only the transaction hashes and the `task_id` - the address
     * is already fixed by this call.
     */
    public function walletHistory(string $address, ?SweepHistoryQuery $query = null): SweepHistoryResponse
    {
        $body = ['address' => $address];
        if ($query !== null) {
            if ($query->mode !== null) {
                $body['mode'] = $query->mode;
            }
            if ($query->status !== null) {
                $body['status'] = $query->status;
            }
            if ($query->search !== null) {
                $body['search'] = $query->search;
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
     * All three layers carry `gasSource`. On `effective` it is always a concrete value -
     * read it there to see what will actually happen. On `override` a `null` means only
     * that this layer does not decide it; the value is inherited, not switched off.
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
     * expresses that by naming the field in its `fields` mask with no value, which `null`
     * cannot say here because it already means "not supplied". The four names the mask
     * accepts are `type_work`, `threshold_amount_usd`, `fee_mode` and `gas_source`, and
     * this method fills it in from whichever arguments you passed.
     *
     * `gasSource` is TRON only and carried and ignored elsewhere. Leaving it `null` does
     * NOT mean `native`: a wallet that has never chosen one gets the platform default,
     * `rented`, so the platform supplies the energy and bills it to your API credits with
     * nobody having switched it on. Send {@see SweepGasSource::Native} explicitly to have
     * the wallet burn its own TRX instead.
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
        SweepGasSource|string|Clear|null $gasSource = null,
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
            'gas_source' => $gasSource,
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
