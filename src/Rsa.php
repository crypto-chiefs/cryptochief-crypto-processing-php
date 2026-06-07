<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\CryptoChiefException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA as PhpsecRsa;
use phpseclib3\Crypt\RSA\PrivateKey;

/**
 * Local RSA decryption of generated wallets' private keys.
 *
 * When the API generates a wallet, the private key is returned encrypted with the RSA
 * public key uploaded to the project (Project Settings -> RSA Key). The scheme is
 * RSA-OAEP / SHA-256 over base64-encoded ciphertext.
 *
 * Uses phpseclib because `openssl_private_decrypt` forces SHA-1 for OAEP MGF1, while
 * the API requires SHA-256.
 */
final class Rsa
{
    /**
     * Load a PEM-encoded RSA private key (PKCS#1 or PKCS#8).
     */
    public static function loadPrivateKeyPem(string $pem): PrivateKey
    {
        try {
            /** @var \phpseclib3\Crypt\Common\AsymmetricKey $key */
            $key = PublicKeyLoader::load($pem);
        } catch (\Throwable $err) {
            throw new CryptoChiefException('cryptochief: RSA key: ' . $err->getMessage());
        }
        if (!$key instanceof PrivateKey) {
            throw new CryptoChiefException('cryptochief: RSA key: not an RSA private key');
        }
        return $key
            ->withPadding(PhpsecRsa::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
    }

    /**
     * Read and parse a PEM-encoded RSA private key from disk.
     */
    public static function loadPrivateKeyFile(string $path): PrivateKey
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new CryptoChiefException("cryptochief: read RSA key '{$path}'");
        }
        return self::loadPrivateKeyPem($contents);
    }

    /**
     * Decrypt a single base64-encoded RSA-OAEP / SHA-256 payload (the encoding the API
     * uses for `private_key_encrypted`). Returns the wallet's private key in the chain's
     * native hex form.
     */
    public static function decryptOaep(PrivateKey $key, string $base64Ciphertext): string
    {
        $cipher = base64_decode($base64Ciphertext, true);
        if ($cipher === false) {
            throw new CryptoChiefException('cryptochief: RSA decrypt: bad base64 ciphertext');
        }
        try {
            $plain = $key->decrypt($cipher);
        } catch (\Throwable $err) {
            throw new CryptoChiefException('cryptochief: RSA decrypt: ' . $err->getMessage());
        }
        return is_string($plain) ? $plain : (string) $plain;
    }
}
