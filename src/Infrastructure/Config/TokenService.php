<?php

namespace App\Infrastructure\Config;

/**
 * Servicio ligero y sin dependencias para firmar y verificar tokens de tipo JWT.
 */
class TokenService
{
    private static function getSecret(): string
    {
        return getenv('JWT_SECRET') ?: 'llave_secreta_para_firmar_tokens_jwt_asistencia_2026';
    }

    /**
     * Genera un token JWT firmado.
     */
    public static function generar(array $payload, int $expiracionSegundos = 86400): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiracionSegundos;

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payloadJson = json_encode($payload);
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payloadJson);
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Verifica y decodifica un token JWT. Retorna el payload o null si es inválido o expiró.
     */
    public static function verificar(string $token): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            return null;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $partes;
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecret(), true);
        $base64UrlSignatureCheck = self::base64UrlEncode($signature);
        
        if (!hash_equals($base64UrlSignature, $base64UrlSignatureCheck)) {
            return null;
        }
        
        $payload = json_decode(self::base64UrlDecode($base64UrlPayload), true);
        
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null; // El token ha expirado
        }
        
        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
