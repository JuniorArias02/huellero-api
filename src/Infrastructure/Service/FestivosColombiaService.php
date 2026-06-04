<?php

namespace App\Infrastructure\Service;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;

/**
 * Servicio para consultar los días festivos de Colombia.
 * Descarga los festivos de una API gratuita y los cachea en la base de datos
 * para evitar hacer consultas HTTP innecesarias.
 */
class FestivosColombiaService
{
    public function __construct(
        private AsistenciaRepositorio $repositorio
    ) {}

    public function esFestivo(string $fecha): bool
    {
        $year = substr($fecha, 0, 4);
        $festivos = $this->obtenerFestivosDelAño($year);
        return isset($festivos[$fecha]);
    }

    public function obtenerFestivosDelAño(string $year): array
    {
        $festivosDB = $this->repositorio->obtenerFestivos($year);
        
        // Si ya los tenemos cacheados, los devolvemos
        if (!empty($festivosDB)) {
            return $festivosDB;
        }

        // Descargar de la API (gratuita, sin key)
        $url = "https://date.nager.at/api/v3/PublicHolidays/{$year}/CO";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $festivosArray = [];
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    $fecha = $item['date'];
                    $nombre = $item['localName'] ?? $item['name'];
                    $festivosArray[$fecha] = $nombre;
                }
            }
            
            if (!empty($festivosArray)) {
                $this->repositorio->guardarFestivos($year, $festivosArray);
            }
        }

        return $festivosArray;
    }
}
