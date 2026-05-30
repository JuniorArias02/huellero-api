<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\UseCase\ListarAsistenciasUseCase;
use App\Application\UseCase\SincronizarAsistenciasUseCase;
use App\Application\UseCase\ExportarExcelUseCase;
use App\Application\UseCase\BuscarAsistenciasUseCase;
use App\Domain\Model\Asistencia\AsistenciaRepositorio;
use App\Infrastructure\Biometric\HikvisionBiometricClient;

/**
 * Controlador HTTP para gestionar las peticiones de asistencia.
 */
class AsistenciaController
{
    public function __construct(
        private ListarAsistenciasUseCase $listarUseCase,
        private SincronizarAsistenciasUseCase $sincronizarUseCase,
        private ExportarExcelUseCase $exportarUseCase,
        private BuscarAsistenciasUseCase $buscarUseCase,
        private AsistenciaRepositorio $repositorio,
        private $clienteBiometrico
    ) {}

    /**
     * Devuelve el listado de asistencias en formato JSON.
     */
    public function listar(array $queryParams): void
    {
        $fechaInicio = $queryParams['fecha_inicio'] ?? null;
        $fechaFin = $queryParams['fecha_fin'] ?? null;
        $busqueda = $queryParams['busqueda'] ?? null;

        try {
            $datos = $this->listarUseCase->ejecutar($fechaInicio, $fechaFin, $busqueda);
            $this->jsonResponse(200, $datos);
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Fallo al obtener asistencias: ' . $e->getMessage()]);
        }
    }

    /**
     * Busca y filtra las asistencias según parámetros y devuelve JSON.
     */
    public function buscar(array $queryParams): void
    {
        $fechaInicio = $queryParams['fecha_inicio'] ?? null;
        $fechaFin = $queryParams['fecha_fin'] ?? null;
        $busqueda = $queryParams['busqueda'] ?? null;

        try {
            $datos = $this->buscarUseCase->ejecutar($fechaInicio, $fechaFin, $busqueda);
            $this->jsonResponse(200, $datos);
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Error al buscar asistencias: ' . $e->getMessage()]);
        }
    }

    /**
     * Sincroniza las asistencias desde el biométrico a la base de datos local.
     */
    public function sincronizar(array $queryParams): void
    {
        // Se puede especificar el rango de fechas en la petición, si no, se toma Mayo de 2026 por defecto (como en el script original)
        $fechaInicio = $queryParams['fecha_inicio'] ?? '2026-05-01T00:00:00-05:00';
        $fechaFin = $queryParams['fecha_fin'] ?? '2026-05-31T23:59:59-05:00';

        try {
            $cantidad = $this->sincronizarUseCase->ejecutar($fechaInicio, $fechaFin);
            $this->jsonResponse(200, [
                'mensaje' => 'Sincronización realizada correctamente',
                'sincronizados' => $cantidad
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(500, [
                'error' => 'Error durante el proceso de sincronización: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Genera y descarga el archivo Excel.
     */
    public function exportar(array $queryParams): void
    {
        $fechaInicio = $queryParams['fecha_inicio'] ?? null;
        $fechaFin = $queryParams['fecha_fin'] ?? null;
        $busqueda = $queryParams['busqueda'] ?? null;

        try {
            $excelData = $this->exportarUseCase->ejecutar($fechaInicio, $fechaFin, $busqueda);

            // Cabeceras HTTP para descarga de Excel
            header('Content-Disposition: attachment; filename="reporte_asistencia_' . date('Ymd_His') . '.xlsx"');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Length: ' . strlen($excelData));
            header('Cache-Control: max-age=0');
            
            echo $excelData;
            exit;
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Error al generar el reporte Excel: ' . $e->getMessage()]);
        }
    }

    /**
     * Obtiene la configuración general y la lista de empleados con sus horarios.
     */
    public function obtenerConfiguracion(): void
    {
        try {
            $general = $this->repositorio->obtenerGeneralConfig();
            $empleados = $this->repositorio->obtenerEmpleadosConfig();

            $this->jsonResponse(200, [
                'jornadas' => $general['jornadas'] ?? [],
                'tolerancia_minutos' => (int)($general['tolerancia_minutos'] ?? 20),
                'empleados' => $empleados
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Error al obtener la configuración: ' . $e->getMessage()]);
        }
    }

    /**
     * Guarda la configuración de la empresa y empleados.
     */
    public function guardarConfiguracion(array $bodyParams): void
    {
        $configData = [
            'jornadas' => $bodyParams['jornadas'] ?? [],
            'tolerancia_minutos' => (int)($bodyParams['tolerancia_minutos'] ?? 20)
        ];
        $empleados = $bodyParams['empleados'] ?? [];

        try {
            $this->repositorio->guardarGeneralConfig($configData);
            $this->repositorio->guardarEmpleadosConfig($empleados);

            $this->jsonResponse(200, ['mensaje' => 'Configuración guardada correctamente']);
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Error al guardar la configuración: ' . $e->getMessage()]);
        }
    }

    /**
     * Autentica al usuario administrador y devuelve un token JWT.
     */
    public function login(array $bodyParams): void
    {
        $username = $bodyParams['username'] ?? '';
        $password = $bodyParams['password'] ?? '';

        // Obtenemos los valores esperados de configuración (con valores por defecto seguros)
        $adminUser = getenv('ADMIN_USER') ?: 'admin';
        $adminPass = getenv('ADMIN_PASS') ?: 'admin123';

        // Validamos credenciales (soporta tanto texto plano como hash bcrypt)
        $validUser = ($username === $adminUser);
        $validPass = false;

        if ($validUser) {
            // Si ADMIN_PASS es un hash de password_hash, usar password_verify. Si no, comparación directa
            if (str_starts_with($adminPass, '$2y$') || strlen($adminPass) === 60) {
                $validPass = password_verify($password, $adminPass);
            } else {
                $validPass = ($password === $adminPass);
            }
        }

        if ($validUser && $validPass) {
            $token = \App\Infrastructure\Config\TokenService::generar([
                'username' => $username,
                'rol' => 'administrador'
            ]);
            $this->jsonResponse(200, [
                'token' => $token,
                'username' => $username,
                'mensaje' => 'Autenticación exitosa'
            ]);
        } else {
            $this->jsonResponse(401, [
                'error' => 'Usuario o contraseña incorrectos'
            ]);
        }
    }

    /**
     * Envía una respuesta HTTP formateada en JSON.
     */
    private function jsonResponse(int $statusCode, array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public function proxyFoto(array $vars): void
    {
        $employeeNo = $vars['id'] ?? '';
        if (empty($employeeNo)) {
            http_response_code(400);
            echo "ID requerido";
            return;
        }

        $fotosDir = __DIR__ . '/../../../../data/fotos';
        $fotoPath = $fotosDir . '/' . basename($employeeNo) . '.jpg';

        if (!file_exists($fotoPath)) {
            http_response_code(204); // No Content: evita errores rojos en consola
            return;
        }

        header("Cache-Control: public, max-age=86400");
        header("Content-Type: image/jpeg");
        header("Content-Length: " . filesize($fotoPath));
        readfile($fotoPath);
    }

    public function uploadFoto(array $vars): void
    {
        $employeeNo = $vars['id'] ?? '';
        if (empty($employeeNo) || !isset($_FILES['foto'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el ID del empleado o el archivo de foto']);
            return;
        }

        $fotosDir = __DIR__ . '/../../../../data/fotos';
        if (!is_dir($fotosDir)) {
            mkdir($fotosDir, 0755, true);
        }

        $fotoOcupada = $_FILES['foto'];
        $extension = strtolower(pathinfo($fotoOcupada['name'], PATHINFO_EXTENSION));
        
        // Solo aceptamos imágenes
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Formato de imagen no válido. Usa JPG o PNG.']);
            return;
        }

        $fotoPath = $fotosDir . '/' . basename($employeeNo) . '.jpg'; // Siempre guardamos como jpg por simplicidad

        // Si suben un PNG/WEBP, idealmente lo convertiríamos, pero mover el archivo base funcionará en navegadores modernos.
        if (move_uploaded_file($fotoOcupada['tmp_name'], $fotoPath)) {
            $this->jsonResponse(200, ['mensaje' => 'Foto subida exitosamente', 'ruta' => "/api/empleado/{$employeeNo}/foto"]);
        } else {
            $this->jsonResponse(500, ['error' => 'Error al guardar la imagen en el servidor']);
        }
    }

    public function refresh(): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $token = '';
        
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } elseif (isset($_GET['token'])) {
            $token = $_GET['token'];
        }

        $payload = \App\Infrastructure\Config\TokenService::verificar($token);
        if (!$payload) {
            $this->jsonResponse(401, ['error' => 'Token inválido o expirado.']);
            return;
        }

        // Generar un nuevo token fresco de 30 minutos
        $nuevoToken = \App\Infrastructure\Config\TokenService::generar([
            'username' => $payload['username'] ?? 'admin',
            'rol' => $payload['rol'] ?? 'administrador'
        ]);

        $this->jsonResponse(200, [
            'token' => $nuevoToken,
            'username' => $payload['username'] ?? 'admin'
        ]);
    }

    public function actualizarNombreEmpleado(array $params, array $body): void
    {
        $id = $params['id'] ?? null;
        $nuevoNombre = trim($body['nombre'] ?? '');

        if (!$id || $nuevoNombre === '') {
            $this->jsonResponse(400, ['error' => 'Falta el ID o el nuevo nombre.']);
            return;
        }

        try {
            // 1. Actualizar el biométrico
            $ok = $this->clienteBiometrico->modificarNombreEmpleado((string)$id, $nuevoNombre);
            if (!$ok) {
                $this->jsonResponse(500, ['error' => 'El dispositivo biométrico rechazó la actualización del nombre.']);
                return;
            }

            // 2. Actualizar la base de datos local
            $this->repositorio->actualizarNombreEmpleadoConfig((string)$id, $nuevoNombre);

            $this->jsonResponse(200, [
                'mensaje' => 'Nombre actualizado con éxito en dispositivo y BD local.',
                'employeeNo' => $id,
                'nombre' => $nuevoNombre
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Error de conexión o guardado: ' . $e->getMessage()]);
        }
    }

    public function actualizarEstadoEmpleado(array $params, array $body): void
    {
        $id = $params['id'] ?? null;
        $nombre = $body['nombre'] ?? '';
        $activo = isset($body['activo']) ? (bool)$body['activo'] : true;

        if (!$id) {
            $this->jsonResponse(400, ['error' => 'Falta el ID del empleado.']);
            return;
        }

        try {
            // 1. Actualizar estado en el biométrico (Deshabilitar/Habilitar)
            $ok = $this->clienteBiometrico->modificarEstadoEmpleado((string)$id, $nombre, $activo);
            if (!$ok) {
                $this->jsonResponse(500, ['error' => 'El dispositivo biométrico rechazó el cambio de estado.']);
                return;
            }

            // 2. Guardar estado en base de datos local
            $this->repositorio->actualizarEstadoEmpleadoConfig((string)$id, $activo ? 1 : 0);

            $this->jsonResponse(200, [
                'mensaje' => $activo ? 'Empleado habilitado correctamente.' : 'Empleado deshabilitado correctamente.',
                'employeeNo' => $id,
                'activo' => $activo
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(500, ['error' => 'Error al cambiar estado: ' . $e->getMessage()]);
        }
    }
}
