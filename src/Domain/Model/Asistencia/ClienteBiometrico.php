<?php

namespace App\Domain\Model\Asistencia;

/**
 * Interfaz del Cliente Biométrico (Dominio).
 * Abstrae la comunicación HTTP/ISAPI con el dispositivo.
 */
interface ClienteBiometrico
{
    /**
     * Consulta registros al dispositivo biométrico.
     *
     * @param int $posicionActual Posición de inicio para paginación (searchResultPosition)
     * @param string $fechaInicio Fecha/hora inicio (formato ISO)
     * @param string $fechaFin Fecha/hora fin (formato ISO)
     * @return array ['numOfMatches' => int, 'responseStatusStrg' => string, 'infoList' => array]
     */
    public function consultar(int $posicionActual, string $fechaInicio, string $fechaFin): array;

    /**
     * Consulta el listado maestro de empleados del biométrico.
     * @return array de {employeeNo, nombre}
     */
    public function obtenerEmpleados(): array;
}
