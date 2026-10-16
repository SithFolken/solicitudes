<?php
function validarAccesoPiloto($pdo, $id_tienda) {
    try {
        $sql = "SELECT COUNT(*) FROM Analisis_Procesos.tiendas_piloto WHERE id_tienda = :id_tienda AND activo = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id_tienda' => $id_tienda]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error validando piloto: " . $e->getMessage());
        return false;
    }
}
?>