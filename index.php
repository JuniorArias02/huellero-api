<?php

/**
 * Punto de entrada principal (Composition Root) del backend.
 * Carga la configuración, resuelve dependencias mediante Inyección de Dependencias
 * y arranca el enrutador HTTP.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Infrastructure\Config\DotEnv;
use App\Infrastructure\Persistence\SqliteAsistenciaRepositorio;
use App\Infrastructure\Biometric\HikvisionBiometricClient;
use App\Infrastructure\Biometric\SimulatedBiometricClient;
use App\Application\UseCase\ListarAsistenciasUseCase;
use App\Application\UseCase\SincronizarAsistenciasUseCase;
use App\Application\UseCase\ExportarExcelUseCase;
use App\Application\UseCase\BuscarAsistenciasUseCase;
use App\Infrastructure\Config\CryptoHelper;
use App\Infrastructure\Http\Controller\AsistenciaController;
use App\Infrastructure\Http\Router;

// 1. Cargar variables de entorno del archivo .env
DotEnv::load(__DIR__ . '/.env');

// 2. Obtener configuraciones de entorno con valores de contingencia (fallback)
$dbPath = getenv('DB_PATH') ?: __DIR__ . '/data/asistencias.sqlite';
$biometricUrl = getenv('BIOMETRIC_URL') ?: 'http://190.145.135.122:8547/ISAPI/AccessControl/AcsEvent?format=json';
$biometricUser = CryptoHelper::desencriptar(getenv('BIOMETRIC_USER') ?: 'admin');
$biometricPass = CryptoHelper::desencriptar(getenv('BIOMETRIC_PASS') ?: '900752620ch*');
$simulate = (getenv('BIOMETRIC_SIMULATE') === 'true' || getenv('BIOMETRIC_SIMULATE') === '1');

// 3. Resolver e instanciar dependencias de la Infraestructura
$repositorio = new SqliteAsistenciaRepositorio($dbPath);

if ($simulate) {
    // Si se activa simulación en .env, inyectamos el cliente simulado para pruebas locales
    $clienteBiometrico = new SimulatedBiometricClient();
} else {
    // En caso contrario, inyectamos el cliente real Hikvision Digest Auth
    $clienteBiometrico = new HikvisionBiometricClient($biometricUrl, $biometricUser, $biometricPass);
}

// 4. Instanciar Servicios de Aplicación (Casos de Uso) inyectando dependencias
$listarUseCase = new ListarAsistenciasUseCase($repositorio);
$sincronizarUseCase = new SincronizarAsistenciasUseCase($clienteBiometrico, $repositorio);
$exportarUseCase = new ExportarExcelUseCase($repositorio);
$buscarUseCase = new BuscarAsistenciasUseCase($repositorio);

// 5. Instanciar Controlador HTTP
$controller = new AsistenciaController($listarUseCase, $sincronizarUseCase, $exportarUseCase, $buscarUseCase, $repositorio);

// 6. Enrutar la petición
$router = new Router($controller);
$router->resolver($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
