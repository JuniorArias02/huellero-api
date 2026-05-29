<?php

namespace App\Application\UseCase;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;
use App\Application\Helper\AsistenciaEnricher;

/**
 * Caso de Uso para Listar las Asistencias Guardadas con Filtros.
 */
class ListarAsistenciasUseCase
{
    public function __construct(
        private AsistenciaRepositorio $repositorio
    ) {}

    /**
     * Obtiene el listado de asistencias formateado.
     *
     * @param string|null $fechaInicio
     * @param string|null $fechaFin
     * @param string|null $busqueda
     * @return array
     */
    public function ejecutar(?string $fechaInicio = null, ?string $fechaFin = null, ?string $busqueda = null): array
    {
        $registros = $this->repositorio->obtenerTodos($fechaInicio, $fechaFin, $busqueda);

        return AsistenciaEnricher::enriquecer($registros, $this->repositorio, $fechaInicio, $fechaFin, $busqueda);
    }
}
