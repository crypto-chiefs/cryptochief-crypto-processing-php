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
    /**
     * `url_callback` of a PayIn, payout, batch payout item or signed transaction: the host
     * is a non-public IP address, a single-label or local name, or resolves to a non-public
     * address (HTTP 400).
     */
    case UrlCallbackHostNotAllowed        = 'url_callback_HOST_NOT_ALLOWED';
    /** `url_callback`: the scheme is not https or http (HTTP 400). */
    case UrlCallbackSchemeInvalid         = 'url_callback_SCHEME_INVALID';
    /** `url_callback`: not an absolute URL (HTTP 400). */
    case UrlCallbackInvalid               = 'url_callback_INVALID';
    /** Static wallet `callback_url` (generate, set callback URL) breaks the same rule; the reason is in the message (HTTP 400). */
    case CallbackUrlInvalid               = 'CALLBACK_URL_INVALID';
    case LabelTooLong                     = 'LABEL_TOO_LONG';
    case BatchEmpty                       = 'BATCH_EMPTY';
    case BatchTooLarge                    = 'BATCH_TOO_LARGE';
    case BatchDuplicateOrderId            = 'BATCH_DUPLICATE_ORDER_ID';
    case FromWalletNotOwned               = 'FROM_WALLET_NOT_OWNED';
    case SignatureExpired                 = 'SIGNATURE_EXPIRED';
    case AlreadyExecuted                  = 'ALREADY_EXECUTED';
    case PreflightFailed                  = 'PREFLIGHT_FAILED';
    case BroadcastFailed                  = 'BROADCAST_FAILED';
    /**
     * EVM execute: a lower nonce of the address is held by another signature that was not
     * executed. Nothing was sent; the transaction's `errorReason` names that signature when
     * it is known. Execute it first, then retry the same uuid.
     */
    case NonceGap                         = 'NONCE_GAP';
    /** EVM execute: the chain already used this transaction's nonce. Nothing was sent by this call. */
    case NonceAlreadyUsed                 = 'NONCE_ALREADY_USED';
    /**
     * EVM sign: an earlier signature from the same address has an execute whose outcome is
     * not known yet. The code may carry that signature's uuid
     * (`PREVIOUS_EXECUTE_UNRESOLVED: uuid=<uuid>`); compare with `str_starts_with`.
     * Retry execute of that uuid instead of signing again.
     */
    case PreviousExecuteUnresolved        = 'PREVIOUS_EXECUTE_UNRESOLVED';
    case SignedTxMismatch                 = 'SIGNED_TX_MISMATCH';
    case ContractRequiredForToken         = 'CONTRACT_REQUIRED_FOR_TOKEN';
    case TransferFieldsNotAllowedForContract = 'TRANSFER_FIELDS_NOT_ALLOWED_FOR_CONTRACT';
    case CallsRequired                    = 'CALLS_REQUIRED';
    case CallsNotAllowedForTransfer       = 'CALLS_NOT_ALLOWED_FOR_TRANSFER';
    case ContractCallsUnsupportedOnNetwork = 'CONTRACT_CALLS_UNSUPPORTED_ON_NETWORK';
    /**
     * Transaction estimate or sign of a token transfer: `from_address` holds less of the
     * token than `value` (HTTP 400). Execute reports it as
     * `PREFLIGHT_FAILED: insufficient_balance`.
     */
    case InsufficientBalance              = 'INSUFFICIENT_BALANCE';
    /**
     * EVM sign: the transaction reverted in simulation (HTTP 400). The contract's reason may
     * follow (`SIMULATION_REVERTED: <reason>`); compare with `str_starts_with`.
     */
    case SimulationReverted               = 'SIMULATION_REVERTED';
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
    /** X-CC-* headers are missing, repeated or malformed (HTTP 400). */
    case BadAuthHeaders                   = 'BAD_AUTH_HEADERS';
    /** X-CC-Signature does not match (HTTP 401). */
    case InvalidSignature                 = 'INVALID_SIGNATURE';
    /** X-CC-Timestamp is more than 300 s from server time (HTTP 401); `ApiException::$serverTime` carries `server_time`. */
    case SignatureTimestampOutOfRange     = 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE';
    /** X-CC-Nonce was already used (HTTP 401). */
    case SignatureReplayed                = 'SIGNATURE_REPLAYED';
    /** Request body exceeds the endpoint's size limit (HTTP 413). */
    case PayloadTooLarge                  = 'PAYLOAD_TOO_LARGE';
}
