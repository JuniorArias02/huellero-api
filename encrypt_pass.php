<?php

/**
 * Script de consola para encriptar contraseñas y guardarlas seguras en el .env
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Infrastructure\Config\DotEnv;
use App\Infrastructure\Config\CryptoHelper;

// Cargar variables si existen
if (file_exists(__DIR__ . '/.env')) {
    DotEnv::load(__DIR__ . '/.env');
}

if ($argc < 2) {
    echo "Uso: php encrypt_pass.php <texto_a_encriptar>\n";
    exit(1);
}

$texto = $argv[1];
$encriptado = CryptoHelper::encriptar($texto);

echo "\n=========================================\n";
echo "Texto original:  " . $texto . "\n";
echo "Texto encriptado: " . $encriptado . "\n";
echo "=========================================\n";
echo "Copia el texto encriptado y pégalo en tu .env sin comillas.\n\n";
