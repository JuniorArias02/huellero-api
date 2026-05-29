<?php

namespace App\Infrastructure\Biometric;

use App\Domain\Model\Asistencia\ClienteBiometrico;

/**
 * Cliente biométrico simulado para pruebas locales sin el dispositivo físico.
 */
class SimulatedBiometricClient implements ClienteBiometrico
{
    private array $nombres = [
        "Vanessa Rodriguez", "GARDENIA ROJAS", "VILLABONA CALDERON CARLOS ALEXANDER",
        "LILIANA PERALTA", "YELITZA TRUJILLO", "GRISMALDO VILLAMIZAR MARIA FERNANDA",
        "MARIA NELLA SANJUAN", "JUAN CARLOS CASTRO OLIVEROS", "Dilan Bulding",
        "LEIDDY VIVINA RAMIREZ", "Junior Herrera", "Alexis angel",
        "JUNIOR EDIMER ARIAS CASTELLANOS", "LEYDY PATRICIA LEAL SANCHEZ ", "LEIDY IBARRA",
        "JORGE VASQUEZ"
    ];

    private array $ids = [
        "Vanessa Rodriguez" => "3",
        "GARDENIA ROJAS" => "11",
        "VILLABONA CALDERON CARLOS ALEXANDER" => "1053871677",
        "LILIANA PERALTA" => "31",
        "YELITZA TRUJILLO" => "1004818695",
        "GRISMALDO VILLAMIZAR MARIA FERNANDA" => "1010128851",
        "MARIA NELLA SANJUAN" => "39",
        "JUAN CARLOS CASTRO OLIVEROS" => "1193606130",
        "Dilan Bulding" => "40",
        "LEIDDY VIVINA RAMIREZ" => "32",
        "Junior Herrera" => "4",
        "Alexis angel" => "5",
        "JUNIOR EDIMER ARIAS CASTELLANOS" => "1093904696",
        "LEYDY PATRICIA LEAL SANCHEZ " => "33",
        "LEIDY IBARRA" => "8",
        "JORGE VASQUEZ" => "16"
    ];

    public function consultar(int $posicionActual, string $fechaInicio, string $fechaFin): array
    {
        // Simulamos 4 páginas de 25 registros para un total de 100 registros.
        $totalSimulado = 100;
        $limitePagina = 25;

        if ($posicionActual >= $totalSimulado) {
            return [
                'numOfMatches' => 0,
                'responseStatusStrg' => 'OK',
                'infoList' => []
            ];
        }

        $infoList = [];
        $fin = min($posicionActual + $limitePagina, $totalSimulado);

        // Convertimos fechas a timestamps para distribuir los logs
        $tInicio = strtotime($fechaInicio);
        $tFin = strtotime($fechaFin);
        if ($tInicio === false) $tInicio = strtotime('2026-05-01T00:00:00-05:00');
        if ($tFin === false) $tFin = strtotime('2026-05-31T23:59:59-05:00');
        
        $duracion = $tFin - $tInicio;
        $intervalo = $duracion / $totalSimulado;

        for ($i = $posicionActual; $i < $fin; $i++) {
            $nombre = $this->nombres[$i % count($this->nombres)];
            $id = $this->ids[$nombre] ?? "99";
            
            // Calculamos un timestamp simulado distribuido uniformemente
            $timeLog = (int)($tInicio + ($i * $intervalo));
            // Formatear en ISO 8601 con zona horaria de Colombia/Bogota (-05:00)
            $timeIso = date('Y-m-d\TH:i:s-05:00', $timeLog);

            $infoList[] = [
                'major' => 5,
                'minor' => 38,
                'time' => $timeIso,
                'cardType' => 1,
                'name' => $nombre,
                'cardReaderNo' => 1,
                'doorNo' => 1,
                'employeeNoString' => $id,
                'type' => 0,
                'serialNo' => 76500 + $i,
                'userType' => 'normal',
                'currentVerifyMode' => ($i % 3 === 0) ? 'fp' : (($i % 3 === 1) ? 'face' : 'card'),
                'mask' => 'unknown'
            ];
        }

        $tieneMas = ($fin < $totalSimulado);

        return [
            'numOfMatches' => count($infoList),
            'responseStatusStrg' => $tieneMas ? 'MORE' : 'OK',
            'infoList' => $infoList
        ];
    }
}
