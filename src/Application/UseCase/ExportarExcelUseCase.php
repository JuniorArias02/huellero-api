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
                '<b>Mayor</b>', 
                '<b>Menor</b>', 
                '<b>Mascarilla</b>'
            ]
        ];

        foreach ($registros as $reg) {
            $fechaFormateada = '';
            if ($reg->getFechaHora()) {
                try {
                    $dt = new \DateTime($reg->getFechaHora());
                    $dt->setTimezone(new \DateTimeZone('America/Bogota'));
                    $fechaFormateada = $dt->format('d/m/Y h:i:s A');
                } catch (\Exception) {
                    $fechaFormateada = $reg->getFechaHora();
                }
            }

            // Traducimos el modo de verificación a español para un reporte limpio
            $modo = match(strtolower($reg->getModoVerificacion())) {
                'fp' => 'Huella Dactilar',
                'face' => 'Rostro',
                'card' => 'Tarjeta',
                'pw' => 'Contraseña',
                'faceorfp' => 'Rostro o Huella',
                'fpandpw' => 'Huella y Contraseña',
                default => ucfirst($reg->getModoVerificacion())
            };

            $filas[] = [
                $reg->getSerialNo(),
                $reg->getEmployeeNo(),
                $reg->getNombre(),
                $fechaFormateada,
                $modo,
                $reg->getLectorNo(),
                $reg->getPuertaNo(),
                $reg->getMajor(),
                $reg->getMinor(),
                $reg->getMascarilla()
            ];
        }

        // Generamos el archivo Excel
        $xlsx = SimpleXLSXGen::fromArray($filas);
        
        return (string)$xlsx;
    }
}
