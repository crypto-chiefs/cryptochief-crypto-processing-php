<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Stable error codes, comparable against ApiException::$errorCode. Not exhaustive - the
 * API defines more per endpoint and may add new ones, so treat an unknown
 * ApiException::$errorCode as opaque.
 */
enum ErrorCode: string
{
    case InsufficientFunds                = 'INSUFFICIENT_FUNDS';
    case InsufficientCredits              = 'INSUFFICIENT_CREDITS';
    case DebtLimitExceeded                = 'DEBT_LIMIT_EXCEEDED';
    case AssetNotEnabled                  = 'ASSET_NOT_ENABLED';
    case OrderAlreadyExist                = 'ORDER_ALREADY_EXIST';
    case OrderCannotCancel                = 'ORDER_CANNOT_CANCEL';
    case OrderNotLive                     = 'ORDER_NOT_LIVE';
    case AssetAlreadySelected             = 'ASSET_ALREADY_SELECTED';
    case InvalidParams                    = 'INVALID_PARAMS';
    case ServiceError                     = 'SERVICE_ERROR';
    case Unauthorized                     = 'UNAUTHORIZED';
    case UrlCallbackRequired              = 'URL_CALLBACK_REQUIRED';
    case LabelTooLong                     = 'LABEL_TOO_LONG';
    case BatchEmpty                       = 'BATCH_EMPTY';
    case BatchTooLarge                    = 'BATCH_TOO_LARGE';
    case BatchDuplicateOrderId            = 'BATCH_DUPLICATE_ORDER_ID';
    case FromWalletNotOwned               = 'FROM_WALLET_NOT_OWNED';
    case SignatureExpired                 = 'SIGNATURE_EXPIRED';
    case AlreadyExecuted                  = 'ALREADY_EXECUTED';
    case PreflightFailed                  = 'PREFLIGHT_FAILED';
    case BroadcastFailed                  = 'BROADCAST_FAILED';
    case SignedTxMismatch                 = 'SIGNED_TX_MISMATCH';
    case ContractRequiredForToken         = 'CONTRACT_REQUIRED_FOR_TOKEN';
    case TransferFieldsNotAllowedForContract = 'TRANSFER_FIELDS_NOT_ALLOWED_FOR_CONTRACT';
    case CallsRequired                    = 'CALLS_REQUIRED';
    case CallsNotAllowedForTransfer       = 'CALLS_NOT_ALLOWED_FOR_TRANSFER';
    case ContractCallsUnsupportedOnNetwork = 'CONTRACT_CALLS_UNSUPPORTED_ON_NETWORK';
    case NetworkError                     = 'NETWORK_ERROR';

    /** The object does not exist OR is not this project's — deliberately indistinguishable. */
    case NotFound                         = 'NOT_FOUND';
    /** Webhook resend: a newer event exists for the same object; only the latest may be resent. Permanent. */
    case DeliverySuperseded               = 'DELIVERY_SUPERSEDED';
    /** Webhook resend: a worker holds the delivery, or it is already scheduled for a retry. */
    case DeliveryInFlight                 = 'DELIVERY_IN_FLIGHT';
    /** Webhook resend: resent under a minute ago (HTTP 429, Retry-After). */
    case ResendTooSoon                    = 'RESEND_TOO_SOON';
    /** Static-deposit resend: no webhook was ever queued — the wallet had no callback_url. */
    case NoDeliveries                     = 'NO_DELIVERIES';
}
