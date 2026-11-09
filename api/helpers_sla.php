<?php
/**
 * helpers_sla.php
 * Funciones para calcular horas hábiles (excluyendo fines de semana y feriados)
 * Usa la tabla sodimac_grt.CALENDARIO para obtener los feriados oficiales
 */

if (!defined('SLA_LOADED')) {
    define('SLA_LOADED', true);

    /**
     * Obtiene la lista de feriados desde la tabla CALENDARIO de sodimac_grt
     * Fallback: array hardcoded por si la consulta falla
     */
    function obtenerFeriados($anio, $pdo = null) {
        // Fallback por si no hay conexión o falla la consulta
        $feriados_fallback = [
            '2026-01-01', '2026-04-03', '2026-04-04', '2026-05-01', '2026-05-21',
            '2026-06-29', '2026-07-16', '2026-08-15', '2026-09-18', '2026-09-19',
            '2026-10-12', '2026-10-31', '2026-11-01', '2026-12-08', '2026-12-25',
            '2027-01-01', '2027-03-26', '2027-03-27', '2027-05-01', '2027-05-21',
            '2027-06-28', '2027-07-16', '2027-08-16', '2027-09-18', '2027-09-20',
            '2027-10-11', '2027-10-29', '2027-11-01', '2027-12-08', '2027-12-25'
        ];

        if (!$pdo) {
            // ✅ CORREGIDO: Función anónima clásica compatible con PHP 7.0+
            // y strpos en lugar de str_starts_with (que es PHP 8.0+)
            return array_filter($feriados_fallback, function($f) use ($anio) {
                return strpos($f, $anio . '-') === 0;
            });
        }

        try {
            $sql = "SELECT Fecha FROM sodimac_grt.CALENDARIO 
                    WHERE Feriado = 1 AND Ano = :anio";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['anio' => $anio]);
            $feriados_db = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($feriados_db)) {
                return $feriados_db;
            }
        } catch (PDOException $e) {
            error_log("Error obteniendo feriados desde CALENDARIO: " . $e->getMessage());
        }

        // Fallback si la consulta falla o no devuelve datos
        return array_filter($feriados_fallback, function($f) use ($anio) {
            return strpos($f, $anio . '-') === 0;
        });
    }

    /**
     * Calcula las horas hábiles transcurridas desde una fecha hasta ahora.
     * Excluye Sábados (6), Domingos (0) y Feriados (desde CALENDARIO).
     */
    function calcularHorasHabiles($fecha_inicio_str, $pdo = null) {
        try {
            $inicio = new DateTime($fecha_inicio_str);
            $ahora = new DateTime();
            
            // Si la fecha es futura o inválida
            if ($inicio >= $ahora) return 0;

            $anio_actual = (int)$ahora->format('Y');
            $feriados = obtenerFeriados($anio_actual, $pdo);
            $horas_habiles = 0;

            // Iteramos hora por hora (para SLA de 48-72h, son pocas iteraciones)
            $intervalo = new DateInterval('PT1H');
            $periodo = new DatePeriod($inicio, $intervalo, $ahora);

            foreach ($periodo as $hora) {
                $dia_semana = (int)$hora->format('w'); // 0 = Domingo, 6 = Sábado
                $fecha_dia = $hora->format('Y-m-d');

                // Si NO es sábado, NI domingo, NI feriado, sumamos la hora
                if ($dia_semana != 0 && $dia_semana != 6 && !in_array($fecha_dia, $feriados)) {
                    $horas_habiles++;
                }
            }

            return $horas_habiles;
        } catch (Exception $e) {
            error_log("Error calculando horas hábiles: " . $e->getMessage());
            return 0;
        }
    }
}
?>