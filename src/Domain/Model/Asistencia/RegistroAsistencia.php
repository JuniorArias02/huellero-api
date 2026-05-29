<?php

namespace App\Domain\Model\Asistencia;

/**
 * Entidad del Dominio que representa un registro de asistencia del biométrico.
 */
class RegistroAsistencia
{
    public function __construct(
        private int $serialNo,
        private string $employeeNo,
        private string $nombre,
        private string $fechaHora,
        private string $modoVerificacion,
        private int $lectorNo,
        private int $puertaNo,
        private int $major,
        private int $minor,
        private string $mascarilla
    ) {}

    public function getSerialNo(): int
    {
        return $this->serialNo;
    }

    public function getEmployeeNo(): string
    {
        return $this->employeeNo;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getFechaHora(): string
    {
        return $this->fechaHora;
    }

    public function getModoVerificacion(): string
    {
        return $this->modoVerificacion;
    }

    public function getLectorNo(): int
    {
        return $this->lectorNo;
    }

    public function getPuertaNo(): int
    {
        return $this->puertaNo;
    }

    public function getMajor(): int
    {
        return $this->major;
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getMascarilla(): string
    {
        return $this->mascarilla;
    }

    /**
     * Convierte la entidad a un arreglo nativo de PHP.
     */
    public function toArray(): array
    {
        return [
            'serialNo' => $this->serialNo,
            'employeeNo' => $this->employeeNo,
            'nombre' => $this->nombre,
            'fechaHora' => $this->fechaHora,
            'modoVerificacion' => $this->modoVerificacion,
            'lectorNo' => $this->lectorNo,
            'puertaNo' => $this->puertaNo,
            'major' => $this->major,
            'minor' => $this->minor,
            'mascarilla' => $this->mascarilla
        ];
    }
}
