<?php
/**
 * Clase de validaciones de negocio reutilizables
 */
class Validaciones {
    
    /**
     * Verifica si el usuario ya tiene una solicitud en estado PENDIENTE o EN_PROCESO
     * @param PDO $pdo
     * @param string $usuario_tienda
     * @return array ['bloqueado' => bool, 'mensaje' => string, 'id_solicitud' => int|null]
     */
    public static function tieneSolicitudPendiente($pdo, $usuario_tienda) {
        try {
            $sql = "SELECT id_solicitud, estado_general, DATE_FORMAT(fecha_solicitud, '%d-%m-%Y %H:%i') as fecha
                    FROM Analisis_Procesos.solicitudes 
                    WHERE usuario_tienda = :usuario 
                      AND estado_general IN ('PENDIENTE', 'EN_PROCESO')
                    ORDER BY fecha_solicitud DESC 
                    LIMIT 1";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['usuario' => $usuario_tienda]);
            $solicitud = $stmt->fetch();
            
            if ($solicitud) {
                return [
                    'bloqueado' => true,
                    'id_solicitud' => $solicitud['id_solicitud'],
                    'estado' => $solicitud['estado_general'],
                    'fecha' => $solicitud['fecha'],
                    'mensaje' => "Ya tienes una solicitud **#{$solicitud['id_solicitud']}** en estado **{$solicitud['estado_general']}** (creada el {$solicitud['fecha']}). Espera a que sea procesada por el analista antes de crear una nueva solicitud."
                ];
            }
            
            return ['bloqueado' => false, 'mensaje' => ''];
            
        } catch (PDOException $e) {
            error_log("Error en Validaciones::tieneSolicitudPendiente: " . $e->getMessage());
            return ['bloqueado' => false, 'mensaje' => ''];
        }
    }
}
?>