<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;
use App\Domain\Model\Asistencia\RegistroAsistencia;
use PDO;

/**
 * Implementación SQLite del Repositorio de Asistencias.
 */
class SqliteAsistenciaRepositorio implements AsistenciaRepositorio
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->inicializarTabla();
    }

    /**
     * Inicializa la estructura de la base de datos si no existe.
     */
    private function inicializarTabla(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS asistencias (
            serialNo INTEGER PRIMARY KEY,
            employeeNo TEXT,
            nombre TEXT,
            fechaHora TEXT,
            modoVerificacion TEXT,
            lectorNo INTEGER,
            puertaNo INTEGER,
            major INTEGER,
            minor INTEGER,
            mascarilla TEXT
        )";
        $this->pdo->exec($sql);

        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_fecha_hora ON asistencias (fechaHora)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_nombre ON asistencias (nombre)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_employee_no ON asistencias (employeeNo)");

        // Autocorrección del esquema: Migración a estructura JSON dinámica para jornadas
        try {
            $this->pdo->query("SELECT jornadas FROM empleados_config LIMIT 1");
        } catch (\Exception $e) {
            $this->pdo->exec("DROP TABLE IF EXISTS configuracion");
            $this->pdo->exec("DROP TABLE IF EXISTS empleados_config");
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
            clave TEXT PRIMARY KEY,
            valor TEXT
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS empleados_config (
            employeeNo TEXT PRIMARY KEY,
            nombre TEXT,
            dias_laborables TEXT,
            jornadas TEXT
        )");

        // Configuración por defecto: Lunes a Sábado (1 a 6)
        $this->pdo->exec("INSERT OR IGNORE INTO configuracion (clave, valor) VALUES ('dias_laborables', '1,2,3,4,5,6')");
        $this->pdo->exec("INSERT OR IGNORE INTO configuracion (clave, valor) VALUES ('tolerancia_minutos', '20')");
        
        // Jornadas por defecto (Mañana, Tarde, y una Noche inactiva como ejemplo)
        $jornadasDefecto = json_encode([
            ['id' => 'manana', 'nombre' => 'Mañana', 'entrada' => '07:30', 'salida' => '11:30', 'activa' => true],
            ['id' => 'tarde', 'nombre' => 'Tarde', 'entrada' => '14:00', 'salida' => '18:00', 'activa' => true],
            ['id' => 'noche', 'nombre' => 'Noche', 'entrada' => '', 'salida' => '', 'activa' => false]
        ]);
        $this->pdo->exec("INSERT OR IGNORE INTO configuracion (clave, valor) VALUES ('jornadas', '{$jornadasDefecto}')");
    }

    public function guardar(RegistroAsistencia $registro): void
    {
        $sql = "INSERT OR REPLACE INTO asistencias (
            serialNo, employeeNo, nombre, fechaHora, modoVerificacion, lectorNo, puertaNo, major, minor, mascarilla
        ) VALUES (
            :serialNo, :employeeNo, :nombre, :fechaHora, :modoVerificacion, :lectorNo, :puertaNo, :major, :minor, :mascarilla
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($registro->toArray());
    }

    public function guardarMultiples(array $registros): void
    {
        if (empty($registros)) return;
        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT OR REPLACE INTO asistencias (
                serialNo, employeeNo, nombre, fechaHora, modoVerificacion, lectorNo, puertaNo, major, minor, mascarilla
            ) VALUES (
                :serialNo, :employeeNo, :nombre, :fechaHora, :modoVerificacion, :lectorNo, :puertaNo, :major, :minor, :mascarilla
            )";
            $stmt = $this->pdo->prepare($sql);
            foreach ($registros as $registro) {
                $stmt->execute($registro->toArray());
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function obtenerTodos(?string $fechaInicio = null, ?string $fechaFin = null, ?string $busqueda = null): array
    {
        $sql = "SELECT * FROM asistencias WHERE 1=1";
        $params = [];

        if ($fechaInicio && trim($fechaInicio) !== '') {
            $sql .= " AND fechaHora >= :fechaInicio";
            $params[':fechaInicio'] = $fechaInicio;
        }

        if ($fechaFin && trim($fechaFin) !== '') {
            $fechaFin = trim($fechaFin);
            if (strlen($fechaFin) === 10) {
                $fechaFin .= 'T23:59:59';
            }
            $sql .= " AND fechaHora <= :fechaFin";
            $params[':fechaFin'] = $fechaFin;
        }

        if ($busqueda && trim($busqueda) !== '') {
            $sql .= " AND (nombre LIKE :busqueda OR employeeNo LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }

        $sql .= " ORDER BY fechaHora DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $filas = $stmt->fetchAll();

        $entidades = [];
        foreach ($filas as $fila) {
            $entidades[] = new RegistroAsistencia(
                (int)$fila['serialNo'],
                $fila['employeeNo'] ?? '',
                $fila['nombre'] ?? 'Sin Nombre',
                $fila['fechaHora'] ?? '',
                $fila['modoVerificacion'] ?? 'desconocido',
                (int)($fila['lectorNo'] ?? 1),
                (int)($fila['puertaNo'] ?? 1),
                (int)($fila['major'] ?? 0),
                (int)($fila['minor'] ?? 0),
                $fila['mascarilla'] ?? 'unknown'
            );
        }
        return $entidades;
    }

    public function obtenerUltimoSerial(): int
    {
        $sql = "SELECT MAX(serialNo) as max_serial FROM asistencias";
        $stmt = $this->pdo->query($sql);
        $res = $stmt->fetch();
        return (int)($res['max_serial'] ?? 0);
    }

    public function obtenerGeneralConfig(): array
    {
        $stmt = $this->pdo->query("SELECT clave, valor FROM configuracion");
        $filas = $stmt->fetchAll();
        
        $config = [
            'dias_laborables' => [1,2,3,4,5,6], // array nativo
            'tolerancia_minutos' => 20,
            'jornadas' => []
        ];
        
        foreach ($filas as $fila) {
            if ($fila['clave'] === 'tolerancia_minutos') {
                $config['tolerancia_minutos'] = (int)$fila['valor'];
            } elseif ($fila['clave'] === 'dias_laborables') {
                $config['dias_laborables'] = array_map('intval', explode(',', $fila['valor']));
            } elseif ($fila['clave'] === 'jornadas') {
                $config['jornadas'] = json_decode($fila['valor'], true) ?? [];
            }
        }
        
        return $config;
    }

    public function guardarGeneralConfig(array $configData): void
    {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO configuracion (clave, valor) VALUES (:clave, :valor)");
        
        if (isset($configData['dias_laborables'])) {
            $dias = is_array($configData['dias_laborables']) ? implode(',', $configData['dias_laborables']) : $configData['dias_laborables'];
            $stmt->execute([':clave' => 'dias_laborables', ':valor' => $dias]);
        }
        if (isset($configData['tolerancia_minutos'])) {
            $stmt->execute([':clave' => 'tolerancia_minutos', ':valor' => (string)$configData['tolerancia_minutos']]);
        }
        if (isset($configData['jornadas'])) {
            $jornadas = is_array($configData['jornadas']) ? json_encode($configData['jornadas']) : $configData['jornadas'];
            $stmt->execute([':clave' => 'jornadas', ':valor' => $jornadas]);
        }
    }

    public function obtenerEmpleadosConfig(): array
    {
        $sql = "SELECT DISTINCT a.employeeNo, a.nombre, ec.dias_laborables, ec.jornadas 
                FROM asistencias a 
                LEFT JOIN empleados_config ec ON a.employeeNo = ec.employeeNo
                WHERE a.employeeNo IS NOT NULL AND a.employeeNo != ''
                ORDER BY a.nombre ASC";
                
        $stmt = $this->pdo->query($sql);
        $resultados = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Formatear JSONs a arrays
        foreach ($resultados as &$res) {
            if (!empty($res['dias_laborables'])) {
                $res['dias_laborables'] = array_map('intval', explode(',', $res['dias_laborables']));
            } else {
                $res['dias_laborables'] = null;
            }
            
            if (!empty($res['jornadas'])) {
                $res['jornadas'] = json_decode($res['jornadas'], true);
            } else {
                $res['jornadas'] = null;
            }
        }
        return $resultados;
    }

    public function guardarEmpleadosConfig(array $empleados): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM empleados_config");
            
            $sql = "INSERT INTO empleados_config (employeeNo, nombre, dias_laborables, jornadas) 
                    VALUES (:employeeNo, :nombre, :dias, :jornadas)";
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($empleados as $emp) {
                // Solo insertamos si tiene alguna customización
                if (!empty($emp['dias_laborables']) || !empty($emp['jornadas'])) {
                    $dias = is_array($emp['dias_laborables']) ? implode(',', $emp['dias_laborables']) : null;
                    $jornadas = is_array($emp['jornadas']) ? json_encode($emp['jornadas']) : null;
                    
                    $stmt->execute([
                        ':employeeNo' => $emp['employeeNo'],
                        ':nombre' => $emp['nombre'] ?? '',
                        ':dias' => $dias,
                        ':jornadas' => $jornadas
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
