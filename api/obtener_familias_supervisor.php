<?php
/**
 * Obtiene las familias asignadas a un supervisor
 * Lógica: "100-200" significa familias 1xx Y 2xx (no rango 100 a 200)
 */
function obtenerFamiliasSupervisor($pdo, $id_supervisor) {
    try {
        $sql = "SELECT familias FROM Analisis_Procesos.supervisor_app_solicitudes_carga 
                WHERE id_supervisor = :id_supervisor";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id_supervisor' => $id_supervisor]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("DEBUG SUPERVISOR: Buscando id_supervisor = '$id_supervisor'. Resultado: " . print_r($resultado, true));
        
        if (!$resultado || empty($resultado['familias'])) {
            return [];
        }
        
        $familias_str = trim(strtoupper($resultado['familias']));
        
        if ($familias_str === 'TODAS') {
            return 'TODAS';
        }
        
        // ✅ NUEVA LÓGICA: "100-200" significa familias 1xx Y 2xx
        $partes = explode(',', $familias_str);
        $rangos = [];
        
        foreach ($partes as $parte) {
            $parte = trim($parte);
            if (empty($parte)) continue;
            
            if (strpos($parte, '-') !== false) {
                // Es un rango: "100-200" → familias 1xx Y 2xx
                list($inicio, $fin) = array_map('intval', explode('-', $parte));
                $inicio_centena = intval($inicio / 100);
                $fin_centena = intval($fin / 100);
                
                for ($i = $inicio_centena; $i <= $fin_centena; $i++) {
                    $rangos[] = $i;
                }
            } else {
                // Es un valor individual: "100" → familias 1xx
                $centena = intval($parte / 100);
                $rangos[] = $centena;
            }
        }
        
        // Eliminar duplicados
        $rangos = array_unique($rangos);
        sort($rangos);
        
        error_log("DEBUG SUPERVISOR: Rangos de centenas: " . implode(', ', $rangos));
        
        return $rangos; // Retorna array de centenas [1, 2] para "100-200"
        
    } catch (PDOException $e) {
        error_log("Error obtener familias supervisor: " . $e->getMessage());
        return [];
    }
}
?>