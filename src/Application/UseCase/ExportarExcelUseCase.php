<?php

namespace App\Application\UseCase;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;
use Shuchkin\SimpleXLSXGen;

/**
 * Caso de Uso para Exportar las Asistencias Filtradas a un Archivo Excel.
 */
class ExportarExcelUseCase
{
    public function __construct(
        private AsistenciaRepositorio $repositorio
    ) {}

    /**
     * Genera y retorna el contenido binario del archivo Excel (.xlsx).
     *
     * @param string|null $fechaInicio
     * @param string|null $fechaFin
     * @param string|null $busqueda
     * @return string
     */
    public function ejecutar(?string $fechaInicio = null, ?string $fechaFin = null, ?string $busqueda = null): string
    {
        $registros = $this->repositorio->obtenerTodos($fechaInicio, $fechaFin, $busqueda);
        $registrosEnriquecidos = \App\Application\Helper\AsistenciaEnricher::enriquecer($registros, $this->repositorio, $fechaInicio, $fechaFin, $busqueda);

        // Definimos la cabecera estilizada usando <b> para negrita en SimpleXLSXGen
        $filas = [
            [
                '<b>Nº Serie</b>', 
                '<b>ID Empleado</b>', 
                '<b>Nombre Completo</b>', 
                '<b>Fecha y Hora</b>', 
                '<b>Modo Verificación</b>', 
                '<b>Nº Lector</b>', 
                '<b>Nº Puerta</b>', 
                '<b>Tipo Registro</b>', 
                '<b>Hora Prog.</b>', 
                '<b>Estado</b>', 
                '<b>Retraso (Min)</b>',
                '<b>Extra (Min)</b>',
                '<b>S. Temprana (Min)</b>'
            ]
        ];

        foreach ($registrosEnriquecidos as $reg) {
            $fechaFormateada = '';
            if ($reg['fechaHora']) {
                try {
                    $dt = new \DateTime($reg['fechaHora']);
                    $dt->setTimezone(new \DateTimeZone('America/Bogota'));
                    $fechaFormateada = $dt->format('d/m/Y h:i:s A');
                } catch (\Exception) {
                    $fechaFormateada = $reg['fechaHora'];
                }
            }

            // Traducimos el modo de verificación a español para un reporte limpio
            $modo = match(strtolower($reg['modoVerificacion'])) {
                'fp' => 'Huella Dactilar',
                'face' => 'Rostro',
                'card' => 'Tarjeta',
                'pw' => 'Contraseña',
                'faceorfp' => 'Rostro o Huella',
                'fpandpw' => 'Huella y Contraseña',
                default => ucfirst($reg['modoVerificacion'])
            };

            $filas[] = [
                $reg['serialNo'] < 0 ? '—' : $reg['serialNo'],
                $reg['employeeNo'],
                $reg['nombre'],
                $fechaFormateada,
                $modo,
                $reg['lectorNo'] > 0 ? $reg['lectorNo'] : '—',
                $reg['puertaNo'] > 0 ? $reg['puertaNo'] : '—',
                $reg['tipoRegistro'],
                $reg['horaProgramada'] ?: 'N/A',
                $reg['estado'],
                $reg['retrasoMinutos'] > 0 ? $reg['retrasoMinutos'] : 0,
                $reg['horasExtrasMinutos'] > 0 ? $reg['horasExtrasMinutos'] : 0,
                $reg['salidaTempranaMinutos'] > 0 ? $reg['salidaTempranaMinutos'] : 0
            ];
        }

        // Generamos el archivo Excel
        $xlsx = SimpleXLSXGen::fromArray($filas);
        
        return (string)$xlsx;
    }
}
