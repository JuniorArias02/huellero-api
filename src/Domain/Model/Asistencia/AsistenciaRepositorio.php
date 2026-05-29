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
}
