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

        // Índices para optimizar las búsquedas
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_fecha_hora ON asistencias (fechaHora)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_nombre ON asistencias (nombre)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_employee_no ON asistencias (employeeNo)");
    }

    /**
     * Guarda un registro individual.
     */
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

    /**
     * Guarda múltiples registros usando transacciones para máximo rendimiento.
     */
    public function guardarMultiples(array $registros): void
    {
        if (empty($registros)) {
            return;
        }

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

    /**
     * Busca y filtra los registros en la base de datos sqlite.
     */
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
            // Si la fecha solo es YYYY-MM-DD, le agregamos el final del día
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

    /**
     * Retorna el número de serie más alto de la base de datos.
     */
    public function obtenerUltimoSerial(): int
    {
        $sql = "SELECT MAX(serialNo) as max_serial FROM asistencias";
        $stmt = $this->pdo->query($sql);
        $res = $stmt->fetch();
        return (int)($res['max_serial'] ?? 0);
    }
}
