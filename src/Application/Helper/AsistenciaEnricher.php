<?php

namespace App\Application\Helper;

use App\Domain\Model\Asistencia\AsistenciaRepositorio;
use App\Domain\Model\Asistencia\RegistroAsistencia;

/**
 * Servicio para enriquecer los registros de asistencia con métricas de puntualidad
 * (entradas a tiempo, llegadas tardes, salidas tempranas y cálculo de horas extras)
 * e inyectar inasistencias (faltas) dinámicas en los rangos consultados.
 */
class AsistenciaEnricher
{
    /**
     * Enriquece una lista de RegistroAsistencia con información de puntualidad de dos turnos
     * e integra las inasistencias de manera virtual.
     * 
     * @param RegistroAsistencia[] $registros
     * @param AsistenciaRepositorio $repositorio
     * @param string|null $fechaInicio
     * @param string|null $fechaFin
     * @param string|null $busqueda
     * @return array Array de arreglos asociativos enriquecidos
     */
    public static function enriquecer(
        array $registros, 
        AsistenciaRepositorio $repositorio, 
        ?string $fechaInicio = null, 
        ?string $fechaFin = null, 
        ?string $busqueda = null
    ): array {
        $config = $repositorio->obtenerGeneralConfig();
        $diasGlobales = $config['dias_laborables'] ?? [1,2,3,4,5,6];
        $tolerancia = (int)($config['tolerancia_minutos'] ?? 20);
        $toleranciaExtra = (int)($config['tolerancia_extra_minutos'] ?? 20);
        $jornadasGlobales = array_filter($config['jornadas'] ?? [], fn($j) => !empty($j['activa']));

        $empleadosConfig = $repositorio->obtenerEmpleadosConfig();
        $empleadosMap = [];
        foreach ($empleadosConfig as $emp) {
            $empleadosMap[$emp['employeeNo']] = [
                'dias_laborables' => $emp['dias_laborables'] ?? $diasGlobales,
                'jornadas' => array_filter($emp['jornadas'] ?? $jornadasGlobales, fn($j) => !empty($j['activa']))
            ];
        }

        $aMinutos = function(string $horaStr): int {
            if (empty($horaStr)) return 0;
            $parts = explode(':', $horaStr);
            return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
        };

        $resultado = [];
        $asistenciasExistentes = [];
        
        foreach ($registros as $reg) {
            $empNo = $reg->getEmployeeNo();
            $fechaHora = $reg->getFechaHora();
            $data = $reg->toArray();
            
            if ($empNo && $fechaHora) {
                $fechaSolo = substr($fechaHora, 0, 10);
                $asistenciasExistentes[$empNo][$fechaSolo] = true;
            }

            $data['tipoRegistro'] = 'Marcación';
            $data['estado'] = 'N/A';
            $data['retrasoMinutos'] = 0;
            $data['horasExtrasMinutos'] = 0;
            $data['salidaTempranaMinutos'] = 0;
            $data['horaProgramada'] = '';

            if ($empNo && $fechaHora) {
                try {
                    $horaMarcacion = substr($fechaHora, 11, 8);
                    if (empty($horaMarcacion)) {
                        $dt = new \DateTime($fechaHora);
                        $dt->setTimezone(new \DateTimeZone('America/Bogota'));
                        $horaMarcacion = $dt->format('H:i:s');
                    }
                    $c = $aMinutos($horaMarcacion);
                    
                    // Obtener jornadas activas del empleado
                    $jornadas = $empleadosMap[$empNo]['jornadas'] ?? $jornadasGlobales;
                    
                    if (!empty($jornadas)) {
                        $diaSemanaMarcacion = (int)(new \DateTime($fechaHora))->format('N');
                        
                        // Filtrar solo las jornadas que aplican a este día de la semana
                        $diasLaborablesEmp = $empleadosMap[$empNo]['dias_laborables'];
                        $jornadasDelDia = array_filter($jornadas, function($j) use ($diaSemanaMarcacion, $diasLaborablesEmp) {
                            $dias = $j['dias'] ?? $diasLaborablesEmp; // Usa los días del empleado en lugar del estático
                            return in_array($diaSemanaMarcacion, $dias);
                        });
                        
                        // Si marca un día que "no trabaja" (ej. Domingo), usamos todas las jornadas para intentar adivinar el turno extra
                        $jornadasEvaluadas = empty($jornadasDelDia) ? $jornadas : $jornadasDelDia;

                        $hitos = [];
                        foreach ($jornadasEvaluadas as $j) {
                            $nombre = $j['nombre'] ?? 'Jornada';
                            if (!empty($j['entrada'])) {
                                $hitos[] = ['tipo' => 'Entrada ' . $nombre, 'hora' => $aMinutos($j['entrada']), 'expected' => $j['entrada'], 'is_entrada' => true];
                            }
                            if (!empty($j['salida'])) {
                                $hitos[] = ['tipo' => 'Salida ' . $nombre, 'hora' => $aMinutos($j['salida']), 'expected' => $j['salida'], 'is_entrada' => false];
                            }
                        }
                        
                        usort($hitos, fn($a, $b) => $a['hora'] <=> $b['hora']);
                        
                        $asignado = null;
                        for ($i = 0; $i < count($hitos); $i++) {
                            $current = $hitos[$i];
                            $next = $hitos[$i + 1] ?? null;
                            if ($next) {
                                $mid = ($current['hora'] + $next['hora']) / 2;
                                if ($c < $mid) {
                                    $asignado = $current;
                                    break;
                                }
                            } else {
                                $asignado = $current;
                                break;
                            }
                        }
                        
                        if ($asignado) {
                            $data['tipoRegistro'] = $asignado['tipo'];
                            $data['horaProgramada'] = $asignado['expected'];
                            $diff = $c - $asignado['hora'];
                            
                            if ($asignado['is_entrada']) {
                                if ($diff > $tolerancia) {
                                    $data['estado'] = 'Tarde';
                                    $data['retrasoMinutos'] = $diff;
                                } else {
                                    $data['estado'] = 'A tiempo';
                                }
                            } else {
                                if ($diff < 0) {
                                    $data['estado'] = 'Salida Temprana';
                                    $data['salidaTempranaMinutos'] = abs($diff);
                                } elseif ($diff > $toleranciaExtra) {
                                    $data['estado'] = 'Horas Extras';
                                    // Se cuentan todos los minutos extra desde la hora oficial de salida,
                                    // o si prefieres descontar los 20 min, sería $diff - $toleranciaExtra.
                                    // Generalmente se pagan todos si se aprueba el quedarse.
                                    $data['horasExtrasMinutos'] = $diff;
                                } else {
                                    $data['estado'] = 'Normal';
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            $resultado[] = $data;
        }

        // --- DETECCION DE INASISTENCIAS VIRTUALES ---
        if ($fechaInicio && $fechaFin) {
            try {
                $startStr = substr($fechaInicio, 0, 10);
                $endStr = substr($fechaFin, 0, 10);
                $startDate = new \DateTime($startStr);
                $endDate = new \DateTime($endStr);
                $hoy = date('Y-m-d');
                
                $interval = $startDate->diff($endDate);
                if ($interval->days > 62) {
                    $endDate = clone $startDate;
                    $endDate->modify('+62 days');
                }

                $inasistencias = [];
                $serialVirtual = -1;
                $festivosService = new \App\Infrastructure\Service\FestivosColombiaService($repositorio);
                
                // Obtener todas las novedades para evitar consultas N+1
                $novedadesDB = $repositorio->obtenerTodasLasNovedades();
                $novedadesPorEmp = [];
                foreach ($novedadesDB as $nov) {
                    $novedadesPorEmp[$nov['employeeNo']][] = $nov;
                }

                foreach ($empleadosConfig as $emp) {
                    $empNo = $emp['employeeNo'];
                    $nombre = $emp['nombre'] ?: 'Empleado ' . $empNo;

                    if ($busqueda && trim($busqueda) !== '') {
                        $coincide = (stripos($nombre, $busqueda) !== false) || (stripos($empNo, $busqueda) !== false);
                        if (!$coincide) continue;
                    }

                    $jornadasEmp = $empleadosMap[$empNo]['jornadas'] ?? $jornadasGlobales;

                    $d = clone $startDate;
                    while ($d <= $endDate) {
                        $currStr = $d->format('Y-m-d');
                        if ($currStr < $hoy) {
                            // Ignorar si el día es festivo nacional en Colombia
                            if ($festivosService->esFestivo($currStr)) {
                                $d->modify('+1 day');
                                continue;
                            }

                            $diaSemana = (int)$d->format('N'); // 1=Lunes, 7=Domingo
                            
                            // Verificar si el empleado tiene al menos un turno activo en este día de la semana
                            $tieneTurno = false;
                            $diasLaborablesEmp = $empleadosMap[$empNo]['dias_laborables'];
                            foreach ($jornadasEmp as $j) {
                                $diasValidos = $j['dias'] ?? $diasLaborablesEmp;
                                if (in_array($diaSemana, $diasValidos)) {
                                    $tieneTurno = true;
                                    break;
                                }
                            }
                            
                            if ($tieneTurno) {
                                if (!isset($asistenciasExistentes[$empNo][$currStr])) {
                                    // Verificar si el empleado tiene una novedad (Vacaciones, Permiso, etc.) en este día
                                    $tipoNovedad = null;
                                    if (isset($novedadesPorEmp[$empNo])) {
                                        foreach ($novedadesPorEmp[$empNo] as $nov) {
                                            if ($currStr >= $nov['fechaInicio'] && $currStr <= $nov['fechaFin']) {
                                                $tipoNovedad = $nov['tipo']; // Ej: 'Vacaciones', 'Incapacidad'
                                                break;
                                            }
                                        }
                                    }

                                    $inasistencias[] = [
                                        'serialNo' => $serialVirtual--,
                                        'employeeNo' => $empNo,
                                        'nombre' => $nombre,
                                        'fechaHora' => $currStr . 'T00:00:00-05:00',
                                        'modoVerificacion' => 'Ninguno',
                                        'lectorNo' => 0,
                                        'puertaNo' => 0,
                                        'major' => 0,
                                        'minor' => 0,
                                        'mascarilla' => 'No',
                                        'tipoRegistro' => $tipoNovedad ? 'Novedad' : 'Inasistencia',
                                        'estado' => $tipoNovedad ?: 'Falta',
                                        'retrasoMinutos' => 0,
                                        'horasExtrasMinutos' => 0,
                                        'salidaTempranaMinutos' => 0,
                                        'horaProgramada' => 'N/A'
                                    ];
                                }
                            }
                        }
                        $d->modify('+1 day');
                    }
                }

                if (!empty($inasistencias)) {
                    $resultado = array_merge($resultado, $inasistencias);
                }
            } catch (\Exception $e) {
            }
        }

        usort($resultado, function ($a, $b) {
            return strcmp($b['fechaHora'], $a['fechaHora']);
        });

        return $resultado;
    }
}
