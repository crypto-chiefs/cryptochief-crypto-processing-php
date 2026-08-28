<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * The two environments an order can belong to.
 *
 * A project may be allowed one or both; asking for testnet on a project that does not
 * permit it is refused with `TESTNET_NOT_ALLOWED` rather than quietly served on mainnet,
 * and a value that is neither is `ENVIRONMENT_INVALID` rather than a silent fallback.
 */
enum Environment: string
{
    case Mainnet = 'mainnet';
    case Testnet = 'testnet';
}
