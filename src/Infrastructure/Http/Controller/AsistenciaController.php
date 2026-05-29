<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\UseCase\ListarAsistenciasUseCase;
use App\Application\UseCase\SincronizarAsistenciasUseCase;
use App\Application\UseCase\ExportarExcelUseCase;
use App\Application\UseCase\BuscarAsistenciasUseCase;

/**
 * Controlador HTTP para gestionar las peticiones de asistencia.
 */
class AsistenciaController
{
    public function __construct(
        private ListarAsistenciasUseCase $listarUseCase,
        private SincronizarAsistenciasUseCase $sincronizarUseCase,
        private ExportarExcelUseCase $exportarUseCase,
        private BuscarAsistenciasUseCase $buscarUseCase
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
}
