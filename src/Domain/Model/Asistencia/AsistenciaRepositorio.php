<?php

namespace App\Domain\Model\Asistencia;

/**
 * Interfaz del Repositorio de Asistencias (Dominio).
 */
interface AsistenciaRepositorio
{
    /**
     * Guarda un registro de asistencia.
     */
    public function guardar(RegistroAsistencia $registro): void;

    /**
     * Guarda múltiples registros en lote.
     * @param RegistroAsistencia[] $registros
     */
    public function guardarMultiples(array $registros): void;

    /**
     * Obtiene registros filtrados por rango de fechas o nombre/código de empleado.
     * @return RegistroAsistencia[]
     */
    public function obtenerTodos(?string $fechaInicio = null, ?string $fechaFin = null, ?string $busqueda = null): array;

    /**
     * Obtiene el último número de serie (serialNo) guardado en el almacén de datos.
     */
    public function obtenerUltimoSerial(): int;

    /**
     * Obtiene la configuración general (horarios por defecto y tolerancia).
     */
    public function obtenerGeneralConfig(): array;

    /**
     * Guarda la configuración general (días laborables, jornadas, tolerancia).
     * @param array $configData
     */
    public function guardarGeneralConfig(array $configData): void;

    /**
     * Obtiene la lista de empleados con sus configuraciones de horarios personalizadas.
     */
    public function obtenerEmpleadosConfig(): array;

    /**
     * Guarda las configuraciones personalizadas de horarios para los empleados.
     */
    public function guardarEmpleadosConfig(array $empleados): void;
}
