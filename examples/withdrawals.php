<?php

declare(strict_types=1);

/**
 * Manual withdrawals - list recent ones, or show one.
 *
 *   MERCHANT_ID=... API_KEY=... [WITHDRAWAL=uuid] php examples/withdrawals.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\Withdrawal;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

function describe(Withdrawal $w): string
{
    return match ($w->status) {
        'completed' => sprintf(
            'completed at %s, %s/%s confirmations, fee $%s',
            $w->completedAt ?? '-',
            $w->confirmations ?? '-',
            $w->requiredConfirmations ?? '-',
            $w->actualFeeFiat ?? '-'
        ),
        'failed' => sprintf('failed: %s', $w->errorReason ?? '-'),
        'confirm_check' => sprintf(
            'confirm_check: %s/%s confirmations',
            $w->confirmations ?? 'not in a block yet',
            $w->requiredConfirmations ?? '-'
        ),
        // queue, refueling, refuel_confirmed, sending, broadcasting, in_mempool
        default => $w->status,
    };
}

$uuid = getenv('WITHDRAWAL') ?: '';
if ($uuid !== '') {
    $w = $client->withdrawals()->info($uuid);
    printf("%s %s %s -> %s: %s\n", $w->uuid, $w->amount ?? '?', $w->coin ?? '', $w->toAddress ?? '', describe($w));
    exit(0);
}

$page = $client->withdrawals()->history(new HistoryQuery(page: 1, pageSize: 20));
foreach ($page->items ?? [] as $w) {
    printf("%s %-14s %s %s: %s\n", $w->uuid, $w->network ?? '', $w->amount ?? '?', $w->coin ?? '', describe($w));
}
printf("page %d of %s, %d withdrawals\n", $page->meta?->page ?? 1, $page->meta?->totalPages ?? '?', $page->meta?->total ?? 0);
