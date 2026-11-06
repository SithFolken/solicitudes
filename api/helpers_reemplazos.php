<?php
/**
 * helpers_reemplazos.php
 * Funciones para manejar reemplazos temporales de analistas (vacaciones/licencias)
 */

if (!defined('REEMPLAZOS_LOADED')) {
    define('REEMPLAZOS_LOADED', true);

    /**
     * Verifica si un analista tiene reemplazo activo HOY.
     * Si tiene, devuelve el username del reemplazo.
     * Si no tiene, devuelve el username original.
     */
    function verificarYAplicarReemplazo($pdo, $usuario_original) {
        if (empty($usuario_original)) return $usuario_original;

        try {
            $sql = "SELECT usuario_reemplazo 
                    FROM Analisis_Procesos.config_reemplazos_analistas 
                    WHERE usuario_original = :usuario 
                      AND activo = 1 
                      AND fecha_inicio <= CURDATE() 
                      AND fecha_fin >= CURDATE()
                    LIMIT 1";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['usuario' => $usuario_original]);
            $reemplazo = $stmt->fetchColumn();

            if ($reemplazo) {
                error_log("REEMPLAZO ACTIVO: $usuario_original → $reemplazo");
                return $reemplazo;
            }
        } catch (PDOException $e) {
            error_log("Error en verificarYAplicarReemplazo: " . $e->getMessage());
        }

        return $usuario_original;
    }

    /**
     * Obtiene los usernames efectivos de un analista:
     * - Él mismo
     * - + Todos los analistas que está reemplazando HOY
     */
    function obtenerAnalistasEfectivos($pdo, $usuario_actual) {
        if (empty($usuario_actual)) return [];

        $analistas = [$usuario_actual]; 
        
        try {
            $sql = "SELECT usuario_original 
                    FROM Analisis_Procesos.config_reemplazos_analistas 
                    WHERE usuario_reemplazo = :usuario 
                      AND activo = 1 
                      AND fecha_inicio <= CURDATE() 
                      AND fecha_fin >= CURDATE()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['usuario' => $usuario_actual]);
            $reemplazados = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($reemplazados)) {
                $analistas = array_merge($analistas, $reemplazados);
            }
        } catch (PDOException $e) {
            error_log("Error en obtenerAnalistasEfectivos: " . $e->getMessage());
        }
        
        return array_unique($analistas);
    }

    /**
     * ✅ NUEVA: Obtiene TODOS los reemplazos activos del usuario actual con sus fechas
     * Devuelve array como: ['gluengo' => ['fecha_inicio' => '2026-11-02', 'fecha_fin' => '2026-11-16'], ...]
     */
    function obtenerReemplazosActivosConFechas($pdo, $usuario_reemplazo) {
        try {
            $sql = "SELECT usuario_original, fecha_inicio, fecha_fin 
                    FROM Analisis_Procesos.config_reemplazos_analistas 
                    WHERE usuario_reemplazo = :reemplazo 
                      AND activo = 1 
                      AND fecha_inicio <= CURDATE() 
                      AND fecha_fin >= CURDATE()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['reemplazo' => $usuario_reemplazo]);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $reemplazos = [];
            foreach ($resultados as $row) {
                $reemplazos[$row['usuario_original']] = [
                    'fecha_inicio' => $row['fecha_inicio'],
                    'fecha_fin' => $row['fecha_fin']
                ];
            }
            return $reemplazos;
        } catch (PDOException $e) {
            error_log("Error en obtenerReemplazosActivosConFechas: " . $e->getMessage());
            return [];
        }
    }
}
?>