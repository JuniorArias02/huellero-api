<?php
require 'vendor/autoload.php';
use App\Infrastructure\Config\DotEnv;
use App\Infrastructure\Config\CryptoHelper;
use App\Infrastructure\Biometric\HikvisionBiometricClient;
use App\Infrastructure\Persistence\SqliteAsistenciaRepositorio;

DotEnv::load(__DIR__ . '/.env');
$dbPath = getenv('DB_PATH') ?: __DIR__ . '/data/asistencias.sqlite';
$biometricUrl = getenv('BIOMETRIC_URL');
$biometricUser = CryptoHelper::desencriptar(getenv('BIOMETRIC_USER'));
$biometricPass = CryptoHelper::desencriptar(getenv('BIOMETRIC_PASS'));

$repositorio = new SqliteAsistenciaRepositorio($dbPath);
$clienteBiometrico = new HikvisionBiometricClient($biometricUrl, $biometricUser, $biometricPass);

try {
    $empleados = $clienteBiometrico->obtenerTodosLosEmpleados();
    echo "Found " . count($empleados) . " employees.\n";
    $repositorio->sincronizarEmpleadosConfig($empleados);
    echo "Sync successful.\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
}
