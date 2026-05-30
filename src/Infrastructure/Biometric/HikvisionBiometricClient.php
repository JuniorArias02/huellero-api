<?php

namespace App\Infrastructure\Biometric;

use App\Domain\Model\Asistencia\ClienteBiometrico;

/**
 * Cliente de red que implementa la comunicación real con el biométrico Hikvision.
 */
class HikvisionBiometricClient implements ClienteBiometrico
{
    public function __construct(
        private string $url,
        private string $usuario,
        private string $password
    ) {}

    /**
     * Realiza la petición HTTP POST al dispositivo usando Digest Auth.
     */
    public function consultar(int $posicionActual, string $fechaInicio, string $fechaFin): array
    {
        $queryBody = [
            "AcsEventCond" => [
                "searchID" => "1",
                "searchResultPosition" => $posicionActual,
                "maxResults" => 100, // Límite en consulta, el dispositivo responderá con su límite interno (e.g. 30)
                "major" => 5,
                "minor" => 38,
                "startTime" => $fechaInicio,
                "endTime" => $fechaFin
            ]
        ];

        $intentos = 3;
        $espera = 1; // segundos de espera inicial

        for ($i = 1; $i <= $intentos; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryBody));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->usuario}:{$this->password}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $status === 200) {
                break;
            }

            // Si es el último intento, lanzamos la excepción correspondientes
            if ($i === $intentos) {
                if ($response === false) {
                    throw new \RuntimeException("Fallo de conexión cURL tras {$intentos} intentos: " . $error);
                }
                throw new \RuntimeException("El biométrico devolvió estado HTTP {$status} tras {$intentos} intentos: " . $response);
            }

            // Esperar con backoff antes del siguiente intento
            sleep($espera);
            $espera *= 2;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("La respuesta del biométrico no es un JSON válido: " . json_last_error_msg());
        }

        $acsEvent = $data['AcsEvent'] ?? null;
        if (!$acsEvent) {
            return [
                'numOfMatches' => 0,
                'responseStatusStrg' => 'OK',
                'infoList' => []
            ];
        }

        return [
            'numOfMatches' => $acsEvent['numOfMatches'] ?? 0,
            'responseStatusStrg' => $acsEvent['responseStatusStrg'] ?? 'OK',
            'infoList' => $acsEvent['InfoList'] ?? []
        ];
    }

    /**
     * Extrae el listado maestro de empleados directamente del biométrico.
     * Retorna un arreglo con [{employeeNo, nombre}]
     */
    public function obtenerEmpleados(): array
    {
        // Reemplazamos el endpoint en la URL base
        $baseUrl = preg_replace('#/ISAPI/.*#', '', $this->url);
        $searchUrl = $baseUrl . '/ISAPI/AccessControl/UserInfo/Search?format=json';

        $queryBody = [
            "UserInfoSearchCond" => [
                "searchID" => "1",
                "searchResultPosition" => 0,
                "maxResults" => 1000
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->usuario}:{$this->password}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return [];

        $data = json_decode($response, true);
        $userInfoList = $data['UserInfoSearch']['UserInfo'] ?? [];

        $empleados = [];
        foreach ($userInfoList as $user) {
            $empleados[] = [
                'employeeNo' => $user['employeeNo'],
                'nombre' => $user['name'] ?? 'Sin Nombre'
            ];
        }

        return $empleados;
    }

    /**
     * Intenta extraer la fotografía facial binaria del biométrico.
     * Retorna el binario JPEG o null si no tiene foto.
     */
    public function obtenerFotoEmpleado(string $employeeNo): ?string
    {
        $baseUrl = preg_replace('#/ISAPI/.*#', '', $this->url);
        $searchUrl = $baseUrl . '/ISAPI/AccessControl/FaceInfo/Search?format=json';

        $queryBody = [
            "FaceInfoSearchCond" => [
                "searchID" => "1",
                "searchResultPosition" => 0,
                "maxResults" => 1,
                "employeeNo" => $employeeNo
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->usuario}:{$this->password}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!$response) return null;

        if (strpos($contentType, 'multipart') !== false) {
            $jpegStart = strpos($response, "\xFF\xD8\xFF");
            if ($jpegStart !== false) {
                $boundaryPos = strpos($response, "\r\n--", $jpegStart);
                if ($boundaryPos !== false) {
                    return substr($response, $jpegStart, $boundaryPos - $jpegStart);
                }
                return substr($response, $jpegStart);
            }
        }

        return null;
    }

    /**
     * Modifica el nombre de un empleado directamente en el biométrico.
     */
    public function modificarNombreEmpleado(string $employeeNo, string $nuevoNombre): bool
    {
        $baseUrl = preg_replace('#/ISAPI/.*#', '', $this->url);
        // El endpoint estándar de Hikvision para modificar datos textuales del usuario
        $modifyUrl = $baseUrl . '/ISAPI/AccessControl/UserInfo/Modify?format=json';

        $payload = [
            "UserInfo" => [
                "employeeNo" => $employeeNo,
                "name" => $nuevoNombre,
                "userType" => "normal"
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $modifyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->usuario}:{$this->password}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status >= 200 && $status < 300;
    }
}
