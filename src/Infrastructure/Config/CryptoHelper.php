<?php

namespace App\Infrastructure\Config;

/**
 * Helper para encriptar y desencriptar credenciales.
 */
class CryptoHelper
{
    private static function getKey(): string
    {
        // Se puede definir CRYPTO_KEY en el .env, si no, se usa una por defecto
        return getenv('CRYPTO_KEY') ?: 'asistencia_biometrica_secret_key_987654321';
    }

    /**
     * Encripta una cadena en formato AES-256-CBC y retorna Base64.
     */
    public static function encriptar(string $texto): string
    {
        $metodo = 'aes-256-cbc';
        $key = hash('sha256', self::getKey(), true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($metodo));
        $encrypted = openssl_encrypt($texto, $metodo, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Desencripta una cadena cifrada en Base64.
     */
    public static function desencriptar(string $textoCifrado): string
    {
        $metodo = 'aes-256-cbc';
        $key = hash('sha256', self::getKey(), true);
        $data = base64_decode($textoCifrado);
        $ivSize = openssl_cipher_iv_length($metodo);
        
        if (strlen($data) < $ivSize) {
            return $textoCifrado; // Si no es un texto cifrado válido, lo retorna tal cual
        }
        
        $iv = substr($data, 0, $ivSize);
        $encrypted = substr($data, $ivSize);
        $decrypted = openssl_decrypt($encrypted, $metodo, $key, OPENSSL_RAW_DATA, $iv);
        
        return $decrypted === false ? $textoCifrado : $decrypted;
    }
}
