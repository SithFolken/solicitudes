<?php
/**
 * Clase para evaluar las reglas de negocio (Árbol de Decisión)
 */
class ArbolDecision {

    public static function evaluar($sku, $cantidad_solicitada, $datos_sugerido, $pdo = null) {
        $resultado = [
            'sku' => $sku,
            'puede_cargar' => true,
            'motivo_rechazo' => null,
            'cantidad_autorizada' => $cantidad_solicitada,
            'md_forzado' => null
        ];

        // ==========================================
        // REGLA 0: FUERA DEL MIX
        // ==========================================
        if (empty($datos_sugerido)) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = 'Fuera del mix: SKU no encontrado en sugerido diario.';
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // Extraer variables con valores por defecto seguros
        $disp_tda = (float)($datos_sugerido['disp_tda'] ?? 0);
        $pend_tda = (float)($datos_sugerido['pend_tda'] ?? 0);
        $pv6 = (float)($datos_sugerido['PV6'] ?? 0);
        $disp_bod = (float)($datos_sugerido['disp_bod'] ?? 0);
        $pend_bod = (float)($datos_sugerido['pend_bod'] ?? 0); 
        
        $md_sugerido = strtoupper(trim($datos_sugerido['MD_sugerido'] ?? ''));

        // Calcular Semanas de Stock ACTUALES (SDS)
        $stock_total_tienda = $disp_tda + $pend_tda;
        $semanas_stock_actual = ($pv6 > 0) ? ($stock_total_tienda / $pv6) : 999;
        
        // Calcular Semanas que REPRESENTA la cantidad solicitada
        $semanas_solicitadas = ($pv6 > 0) ? ($cantidad_solicitada / $pv6) : 9999;

        // ==========================================
        // ✅ NUEVA REGLA 1: SDS ACTUAL EXCESIVA (> 12 SEMANAS)
        // ==========================================
        if ($semanas_stock_actual > 12) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = "SDS actual de " . round($semanas_stock_actual, 1) . " semanas excede el máximo permitido (12 semanas). Stock actual en tienda: {$stock_total_tienda}, PV6: {$pv6}.";
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // ==========================================
        // REGLA 2: SIN STOCK EN BODEGA (disp_bod == 0)
        // ==========================================
        if ($disp_bod == 0) {
            
            // CASO A: No hay stock, pero SÍ hay pendiente en camino (pend_bod > 0)
            if ($pend_bod > 0) {
                $motivo_base = "Sin stock disponible en bodega actualmente, pero existen {$pend_bod} unidades pendientes en camino.";
                
                if ($pdo) {
                    try {
                        $sql_pendiente = "CALL sp_inf_sku_contenedor_pendiente(:sku)";
                        $stmt_pendiente = $pdo->prepare($sql_pendiente);
                        $stmt_pendiente->execute(['sku' => $sku]);
                        $registros_pendientes = $stmt_pendiente->fetchAll(PDO::FETCH_ASSOC);
                        
                        while ($stmt_pendiente->nextRowset()) { }

                        if (!empty($registros_pendientes)) {
                            $programados = [];
                            $asignados = [];
                            $otros = []; 
                            
                            foreach ($registros_pendientes as $reg) {
                                $estado = strtolower(trim($reg['estado_embarque'] ?? $reg['estado_contenedor'] ?? $reg['estado_pedido'] ?? ''));
                                if (strpos($estado, 'programado') !== false) {
                                    $programados[] = $reg;
                                } elseif (strpos($estado, 'asignado') !== false) {
                                    $asignados[] = $reg;
                                } else {
                                    $otros[] = $reg;
                                }
                            }
                            
                            $fecha_eta_mas_cercana = null;
                            $total_detalle = 0;
                            $estado_mostrar = '';
                            
                            if (!empty($programados)) {
                                foreach ($programados as $reg) {
                                    $total_detalle += (float)($reg['cantidad'] ?? 0);
                                    $fecha = $reg['ETA'] ?? $reg['fecha_programacion'] ?? $reg['fecha_disponible'] ?? null;
                                    if ($fecha && (!$fecha_eta_mas_cercana || $fecha < $fecha_eta_mas_cercana)) {
                                        $fecha_eta_mas_cercana = $fecha;
                                    }
                                }
                                $estado_mostrar = $programados[0]['estado_embarque'] ?? $programados[0]['estado_contenedor'] ?? 'Programado';
                            } elseif (!empty($asignados)) {
                                foreach ($asignados as $reg) {
                                    $total_detalle += (float)($reg['cantidad'] ?? 0);
                                    $fecha = $reg['ETA'] ?? $reg['fecha_programacion'] ?? $reg['fecha_disponible'] ?? null;
                                    if ($fecha && (!$fecha_eta_mas_cercana || $fecha < $fecha_eta_mas_cercana)) {
                                        $fecha_eta_mas_cercana = $fecha;
                                    }
                                }
                                $estado_mostrar = $asignados[0]['estado_embarque'] ?? $asignados[0]['estado_contenedor'] ?? 'Asignado';
                            } elseif (!empty($otros)) {
                                foreach ($otros as $reg) {
                                    $total_detalle += (float)($reg['cantidad'] ?? 0);
                                    $fecha = $reg['ETA'] ?? $reg['fecha_programacion'] ?? $reg['fecha_disponible'] ?? null;
                                    if ($fecha && (!$fecha_eta_mas_cercana || $fecha < $fecha_eta_mas_cercana)) {
                                        $fecha_eta_mas_cercana = $fecha;
                                    }
                                }
                                $estado_mostrar = $otros[0]['estado_embarque'] ?? $otros[0]['estado_contenedor'] ?? $otros[0]['estado_pedido'] ?? 'En camino';
                            }
                            
                            if ($total_detalle > 0) {
                                if ($fecha_eta_mas_cercana) {
                                    $fecha_formateada = date('d-m-Y', strtotime($fecha_eta_mas_cercana));
                                    $resultado['puede_cargar'] = false;
                                    $resultado['motivo_rechazo'] = "{$motivo_base} Detalle: {$total_detalle} unidades en estado '{$estado_mostrar}' con ETA estimada para el {$fecha_formateada}. No se puede realizar nueva carga.";
                                } else {
                                    $resultado['puede_cargar'] = false;
                                    $resultado['motivo_rechazo'] = "{$motivo_base} Detalle: {$total_detalle} unidades en estado '{$estado_mostrar}' (fecha de llegada por confirmar). No se puede realizar nueva carga.";
                                }
                                $resultado['cantidad_autorizada'] = 0;
                                return $resultado;
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Error al consultar SP pendientes: " . $e->getMessage());
                    }
                }
                
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "{$motivo_base} No se pudieron obtener detalles de la fecha de llegada. No se puede cargar.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            } 
            
            // CASO B: No hay stock Y TAMPOCO hay pendiente en camino (pend_bod == 0)
            else {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "Sin stock disponible en bodega y no hay unidades pendientes en camino. Se debe desconectar el código del mix o solicitar un producto de reemplazo.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            }
        }

        // ==========================================
        // REGLA 3: CANTIDAD SOLICITADA EXCESIVA
        // ==========================================
        if ($semanas_solicitadas > 12) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = "Cantidad excesiva: Solicitas {$cantidad_solicitada} unidades, lo que representa " . round($semanas_solicitadas, 1) . " semanas de venta (> 12 semanas). Verifique si hubo un error de tipeo.";
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // ==========================================
        // REGLA 4: STOCK ALTO + SIN SALDO EN BODEGA + TRANSFERENCIA (TRF)
        // ==========================================
        if ($semanas_stock_actual >= 10 && $md_sugerido === 'TRF' && $disp_bod == 0) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = "No hay saldo en bodega (disp_bod=0) y semanas de stock actuales >= 10. No se puede cargar por Transferencia (TRF).";
            $resultado['cantidad_autorizada'] = 0;
            $resultado['md_forzado'] = 'RECHAZADO';
            return $resultado;
        }

        return $resultado;
    }
}
?>