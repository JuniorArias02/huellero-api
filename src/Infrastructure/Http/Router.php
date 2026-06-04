<?php

namespace App\Infrastructure\Http;

use App\Infrastructure\Http\Controller\AsistenciaController;

/**
 * Enrutador HTTP simple con soporte CORS para el backend API.
 */
class Router
{
    public function __construct(
        private AsistenciaController $controller
    ) {}

    /**
     * Resuelve la URI solicitada y ejecuta el controlador respectivo.
     *
     * @param string $uri URI solicitada
     * @param string $method Método HTTP (GET, POST, OPTIONS, etc.)
     */
    public function resolver(string $uri, string $method): void
    {
        $this->manejarCors();

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        // Limpiamos la ruta si contiene index.php al final
        $path = preg_replace('/\/index\.php$/', '', $path);
        $path = rtrim($path, '/');
        
        // Si la ruta queda vacía (e.g. localhost:8000), le ponemos barra diagonal
        if ($path === '') {
            $path = '/';
        }

        $queryParams = $_GET;

        // Rutas del API - Autenticación Pública
        if ($method === 'POST' && ($path === '/api/login' || $path === '/api/auth/login')) {
            $bodyJson = json_decode(file_get_contents('php://input'), true) ?? [];
            $this->controller->login($bodyJson);
            return;
        }

        // Refresco de token (también protegido, pero procesado antes del filtro general para no duplicar lógica)
        if ($method === 'POST' && $path === '/api/refresh') {
            $this->validarAutorizacion();
            $this->controller->refresh();
            return;
        }

        // Rutas del API - Protegidas con Token Bearer
        if (str_starts_with($path, '/api/')) {
            $this->validarAutorizacion();
        }

        if ($method === 'GET' && ($path === '/api/asistencia' || $path === '/api/asistencias')) {
            $this->controller->listar($queryParams);
            return;
        }

        if ($method === 'GET' && $path === '/api/configuracion') {
            $this->controller->obtenerConfiguracion();
            return;
        }

        if ($method === 'POST' && $path === '/api/configuracion') {
            $bodyJson = json_decode(file_get_contents('php://input'), true) ?? [];
            $this->controller->guardarConfiguracion($bodyJson);
            return;
        }

        if ($method === 'GET' && ($path === '/api/asistencia/buscar' || $path === '/api/asistencias/buscar')) {
            $this->controller->buscar($queryParams);
            return;
        }

        if ($method === 'POST' && ($path === '/api/sincronizar' || $path === '/api/sync')) {
            // Unificamos query params con body params por comodidad
            $bodyJson = json_decode(file_get_contents('php://input'), true) ?? [];
            $paramsCombined = array_merge($queryParams, $bodyJson);
            $this->controller->sincronizar($paramsCombined);
            return;
        }

        if ($method === 'GET' && $path === '/api/exportar') {
            $this->controller->exportar($queryParams);
            return;
        }

        // Ruta para proxy de fotografías binarias (usa token por Query Param si se accede desde img src)
        if ($method === 'GET' && preg_match('#^/api/empleado/([^/]+)/foto$#', $path, $matches)) {
            $id = $matches[1];
            $this->controller->proxyFoto(['id' => $id]);
            return;
        }

        // Ruta para cargar fotografías locales
        if ($method === 'POST' && preg_match('#^/api/empleado/([^/]+)/foto$#', $path, $matches)) {
            $id = $matches[1];
            $this->controller->uploadFoto(['id' => $id]);
            return;
        }

        // Ruta para actualizar nombre del empleado
        if ($method === 'PUT' && preg_match('#^/api/empleado/([^/]+)/nombre$#', $path, $matches)) {
            $id = $matches[1];
            $bodyJson = json_decode(file_get_contents('php://input'), true) ?? [];
            $this->controller->actualizarNombreEmpleado(['id' => $id], $bodyJson);
            return;
        }

        // Ruta para deshabilitar/habilitar empleado
        if ($method === 'PUT' && preg_match('#^/api/empleado/([^/]+)/estado$#', $path, $matches)) {
            $id = $matches[1];
            $bodyJson = json_decode(file_get_contents('php://input'), true) ?? [];
            $this->controller->actualizarEstadoEmpleado(['id' => $id], $bodyJson);
            return;
        }

        // Ruta para sincronizar masivamente todo el personal
        if ($method === 'POST' && $path === '/api/empleado/sincronizar') {
            $this->controller->sincronizarPersonal();
            return;
        }

        // Rutas para novedades (Vacaciones, Incapacidades, Permisos)
        if ($method === 'GET' && preg_match('#^/api/empleado/([^/]+)/novedades$#', $path, $matches)) {
            $this->controller->obtenerNovedades(['id' => $matches[1]]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/empleado/([^/]+)/novedades$#', $path, $matches)) {
            $bodyJson = json_decode(file_get_contents('php://input'), true) ?? [];
            $this->controller->guardarNovedad(['id' => $matches[1]], $bodyJson);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/novedades/([^/]+)$#', $path, $matches)) {
            $this->controller->eliminarNovedad(['novedadId' => $matches[1]]);
            return;
        }

        // Ruta de bienvenida básica
        if ($method === 'GET' && $path === '/') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'api' => 'Servicio de Asistencia Biométrica Hikvision API',
                'estado' => 'activo',
                'documentacion' => [
                    'GET /api/asistencias' => 'Obtener lista de asistencias',
                    'POST /api/sincronizar' => 'Sincronizar datos del dispositivo',
                    'GET /api/exportar' => 'Exportar a Excel'
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Ruta no encontrada
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'error' => 'Ruta no encontrada',
            'metodo' => $method,
            'ruta' => $path
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Habilita CORS para permitir peticiones del frontend React (incluso en otros puertos).
     */
    private function manejarCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }

    /**
     * Valida el token JWT en las cabeceras HTTP. Responde con 401 si es inválido.
     */
    private function validarAutorizacion(): void
    {
        $token = '';
        $authHeader = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['Authorization'])) {
            $authHeader = $_SERVER['Authorization'];
        } else {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } elseif (isset($_GET['token']) && trim($_GET['token']) !== '') {
            $token = $_GET['token'];
        }

        if (empty($token)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado. Se requiere token Bearer o parámetro token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = \App\Infrastructure\Config\TokenService::verificar($token);

        if (!$payload) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'Token inválido o expirado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
