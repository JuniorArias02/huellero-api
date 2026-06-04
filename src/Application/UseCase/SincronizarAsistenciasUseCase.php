<?php

namespace App\Application\UseCase;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;
use App\Domain\Model\Asistencia\ClienteBiometrico;
use App\Domain\Model\Asistencia\RegistroAsistencia;

/**
 * Caso de Uso para Sincronizar Asistencias del Dispositivo Biométrico a la Base de Datos.
 */
class SincronizarAsistenciasUseCase
{
    public function __construct(
        private ClienteBiometrico $cliente,
        private AsistenciaRepositorio $repositorio
    ) {}

    /**
     * Ejecuta la sincronización para un rango de fechas dado.
     *
     * @param string $fechaInicio
     * @param string $fechaFin
     * @return int Cantidad de nuevos registros guardados/actualizados.
     */
    public function ejecutar(string $fechaInicio, string $fechaFin): int
    {
        // Optimizamos obteniendo la última fecha sincronizada para no pedir todo desde el inicio
        $ultimaFecha = $this->repositorio->obtenerUltimaFechaSincronizada();
        if ($ultimaFecha && $ultimaFecha > $fechaInicio) {
            $fechaInicio = $ultimaFecha;
        }

        $ultimoSerial = $this->repositorio->obtenerUltimoSerial();

        $posicionActual = 0;
        $tieneMasDatos = true;
        $totalSincronizados = 0;

        do {
            try {
                $resultado = $this->cliente->consultar($posicionActual, $fechaInicio, $fechaFin);
                $infoList = $resultado['infoList'] ?? [];
                $numOfMatches = $resultado['numOfMatches'] ?? 0;
                $responseStatusStrg = $resultado['responseStatusStrg'] ?? '';

                if (empty($infoList)) {
                    $tieneMasDatos = false;
                    break;
                }

                $registrosAEntidades = [];
                foreach ($infoList as $item) {
                    $serialNo = $item['serialNo'] ?? null;
                    // Evitamos procesar y duplicar registros que ya existen en la base de datos local
                    if ($serialNo === null || (int)$serialNo <= $ultimoSerial) {
                        continue;
                    }

                    // Mapeamos los datos del biométrico a la entidad de nuestro dominio
                    $registrosAEntidades[] = new RegistroAsistencia(
                        (int)$serialNo,
                        $item['employeeNoString'] ?? '',
                        $item['name'] ?? 'Sin Nombre',
                        $item['time'] ?? '',
                        $item['currentVerifyMode'] ?? 'desconocido',
                        (int)($item['cardReaderNo'] ?? 1),
                        (int)($item['doorNo'] ?? 1),
                        (int)($item['major'] ?? 0),
                        (int)($item['minor'] ?? 0),
                        $item['mask'] ?? 'unknown'
                    );
                }

                if (!empty($registrosAEntidades)) {
                    $this->repositorio->guardarMultiples($registrosAEntidades);
                    $totalSincronizados += count($registrosAEntidades);
                }

                if ($responseStatusStrg === 'MORE') {
                    $posicionActual += $numOfMatches;
                    // Pausa segura de 300ms para evitar el bloqueo de IP (illegal login lock) del biométrico
                    usleep(300000);
                } else {
                    $tieneMasDatos = false;
                }
            } catch (\Exception $e) {
                throw new \RuntimeException("Error al sincronizar en la posición {$posicionActual}: " . $e->getMessage(), 0, $e);
            }
        } while ($tieneMasDatos);

        // --- SECCIÓN NUEVA: SINCRONIZACIÓN DE EMPLEADOS (DIRECTORIO) ---
        try {
            $empleadosBiometrico = $this->cliente->obtenerEmpleados();
            if (!empty($empleadosBiometrico)) {
                $empleadosBD = $this->repositorio->obtenerEmpleadosConfig();
                $mapaEmpleadosBD = [];
                foreach ($empleadosBD as $emp) {
                    $mapaEmpleadosBD[$emp['employeeNo']] = $emp;
                }

                // Fusionar: mantener configuración existente (como horarios personalizados) pero añadir/actualizar nombres
                foreach ($empleadosBiometrico as $empBio) {
                    $id = $empBio['employeeNo'];
                    if (isset($mapaEmpleadosBD[$id])) {
                        // Solo actualizamos el nombre si existe, respetamos sus jornadas
                        $mapaEmpleadosBD[$id]['nombre'] = $empBio['nombre'];
                    } else {
                        // Es un empleado nuevo que nunca ha marcado asistencia
                        $mapaEmpleadosBD[$id] = [
                            'employeeNo' => $id,
                            'nombre' => $empBio['nombre'],
                            'jornadas' => null // Usa horario global por defecto
                        ];
                    }
                }
                
                $this->repositorio->guardarEmpleadosConfig(array_values($mapaEmpleadosBD));
            }
        } catch (\Exception $e) {
            // Si falla la extracción de usuarios maestros (quizás por permisos), no interrumpimos la sincro de huellas
            error_log("No se pudo sincronizar la base de empleados maestros: " . $e->getMessage());
        }

        return $totalSincronizados;
    }
}
