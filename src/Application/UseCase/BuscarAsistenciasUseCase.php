<?php

namespace App\Application\UseCase;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;

/**
 * Caso de Uso para Buscar y Filtrar Asistencias.
 */
class BuscarAsistenciasUseCase
{
    public function __construct(
        private AsistenciaRepositorio $repositorio
    ) {}

    /**
     * Busca asistencias basadas en filtros de fecha y búsqueda.
     *
     * @param string|null $fechaInicio
     * @param string|null $fechaFin
     * @param string|null $busqueda
     * @return array
     */
    public function ejecutar(?string $fechaInicio = null, ?string $fechaFin = null, ?string $busqueda = null): array
    {
        $registros = $this->repositorio->obtenerTodos($fechaInicio, $fechaFin, $busqueda);

        return array_map(function($registro) {
            return $registro->toArray();
        }, $registros);
    }
}
