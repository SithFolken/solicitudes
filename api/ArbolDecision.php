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
            'md_forzado' => null,
            'nota_aprobacion' => null
        ];

        // ==========================================
        // REGLA 0: FUERA DEL MIX (Validación básica)
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
        $pv6 = (float)($datos_sugerido['PV6'] ?? $datos_sugerido['pv6'] ?? 0);
        $disp_bod = (float)($datos_sugerido['disp_bod'] ?? 0);
        $pend_bod = (float)($datos_sugerido['pend_bod'] ?? 0); 
        $md_sugerido = strtoupper(trim($datos_sugerido['MD_sugerido'] ?? $datos_sugerido['md_defecto_sku'] ?? ''));

        // Variables para el Bypass de Emergencia
        $vta_sem_1 = (float)($datos_sugerido['vta_sem_1'] ?? $datos_sugerido['v1'] ?? 0);
        $vta_sem_2 = (float)($datos_sugerido['vta_sem_2'] ?? $datos_sugerido['v2'] ?? 0);
        $vta_sem_3 = (float)($datos_sugerido['vta_sem_3'] ?? $datos_sugerido['v3'] ?? 0);
        $lead_time = (float)($datos_sugerido['lead_time_total'] ?? $datos_sugerido['lt'] ?? 0);

        // Cálculos base
        $stock_total_tienda = $disp_tda + $pend_tda;
        $semanas_stock_actual = ($pv6 > 0) ? ($stock_total_tienda / $pv6) : 999;
        $stock_proyectado = $stock_total_tienda + $cantidad_solicitada;
        $semanas_stock_proyectadas = ($pv6 > 0) ? ($stock_proyectado / $pv6) : 999;
        
        // Cálculos de Tendencia y Riesgo para el Bypass
        $tendencia_venta = ($vta_sem_3 > 0) ? (($vta_sem_1 - $vta_sem_3) / $vta_sem_3) * 100 : 0;
        $stock_necesario_lt = ($pv6 > 0 && $lead_time > 0) ? ($pv6 * $lead_time) : 0;
        
        // Condiciones del Bypass Universal
        $es_tendencia_alza = $tendencia_venta > 20; 
        $riesgo_quiebre_lt = ($stock_total_tienda < $stock_necesario_lt) && ($pv6 > 0);
        $demanda_emergente = ($vta_sem_1 > 0 && $vta_sem_2 > 0 && $vta_sem_1 > ($vta_sem_2 * 1.5));
        
        $aplica_bypass = ($es_tendencia_alza && $riesgo_quiebre_lt) || ($demanda_emergente && $stock_total_tienda < ($pv6 * 2));


                // =========================================================================
        // ✅ REGLA 1: SIN STOCK EN BODEGA (disp_bod == 0)
        // EXCEPCIÓN: No aplica para Compra Local (CL) porque no pasan por bodega
        // =========================================================================
        
        // Si es Compra Local, NO validar stock en bodega (saltar esta regla)
        if ($md_sugerido === 'COMPRA_LOCAL' || $md_sugerido === 'CL') {
            // No hacer nada, continuar con las siguientes reglas
        } elseif ($disp_bod == 0) {
            // 🚨 Solo para TRF y XD: Verificar si aplica el Bypass de Emergencia
            if ($aplica_bypass && $cantidad_solicitada > 0 && $cantidad_solicitada <= ($stock_necesario_lt * 2)) {
                $resultado['puede_cargar'] = true;
                $resultado['cantidad_autorizada'] = $cantidad_solicitada;
                
                if ($es_tendencia_alza) {
                    $resultado['nota_aprobacion'] = "✅ BYPASS APROBADO (Sobreescribe Regla 1): Venta con tendencia al alza (" . round($tendencia_venta, 1) . "%). Stock actual ({$stock_total_tienda}) insuficiente para cubrir LT de {$lead_time} semanas. Se autoriza la carga (cualquier método: TRF/XD/CL) para evitar quiebre.";
                } else {
                    $resultado['nota_aprobacion'] = "✅ BYPASS APROBADO (Sobreescribe Regla 1): Detección de demanda emergente (Semana 1: {$vta_sem_1} vs Semana 2: {$vta_sem_2}). Stock bajo. Se autoriza la carga (cualquier método: TRF/XD/CL) para evitar quiebre.";
                }
                return $resultado;
            }

            // Si NO aplica el bypass, procedemos con el rechazo normal de la Regla 1
            if ($pend_bod > 0 && $md_sugerido == 'TRF') {
                $motivo_base = "Sin stock disponible en bodega actualmente, pero existen {$pend_bod} unidades pendientes en camino.";
                if ($pdo) {
                    try {
                        $sql_pendiente = "CALL sp_inf_sku_contenedor_pendiente(:sku)";
                        $stmt_pendiente = $pdo->prepare($sql_pendiente);
                        $stmt_pendiente->execute(['sku' => $sku]);
                        $registros_pendientes = $stmt_pendiente->fetchAll(PDO::FETCH_ASSOC);
                        while ($stmt_pendiente->nextRowset()) { }

                        if (!empty($registros_pendientes)) {
                            $total_detalle = 0;
                            $fecha_eta_mas_cercana = null;
                            $estado_mostrar = '';
                            
                            foreach ($registros_pendientes as $reg) {
                                $total_detalle += (float)($reg['cantidad'] ?? 0);
                                $fecha = $reg['ETA'] ?? $reg['fecha_programacion'] ?? $reg['fecha_disponible'] ?? null;
                                if ($fecha && (!$fecha_eta_mas_cercana || $fecha < $fecha_eta_mas_cercana)) {
                                    $fecha_eta_mas_cercana = $fecha;
                                }
                                $estado_mostrar = $reg['estado_embarque'] ?? $reg['estado_contenedor'] ?? $reg['estado_pedido'] ?? 'En camino';
                            }
                            
                            if ($total_detalle > 0) {
                                $fecha_formateada = $fecha_eta_mas_cercana ? date('d-m-Y', strtotime($fecha_eta_mas_cercana)) : 'por confirmar';
                                $resultado['puede_cargar'] = false;
                                $resultado['motivo_rechazo'] = "{$motivo_base} Detalle: {$total_detalle} unidades en estado '{$estado_mostrar}' con ETA {$fecha_formateada}. No se puede realizar nueva carga.";
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
            } else {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "Sin stock disponible en bodega y no hay unidades pendientes en camino. Se debe desconectar el código del mix o solicitar un producto de reemplazo.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            }
        }

        // ==========================================
        // ✅ REGLA 2: BYPASS DE EMERGENCIA UNIVERSAL
        // (Para casos donde SÍ hay stock en bodega, pero igual hay riesgo de quiebre por alza)
        // Aplica para cualquier método: TRF, XD, CL
        // ==========================================
        if ($aplica_bypass && $cantidad_solicitada > 0 && $cantidad_solicitada <= ($stock_necesario_lt * 2)) {
            $resultado['puede_cargar'] = true;
            $resultado['cantidad_autorizada'] = $cantidad_solicitada;
            
            if ($es_tendencia_alza) {
                $resultado['nota_aprobacion'] = "✅ APROBADO: Venta con tendencia al alza (" . round($tendencia_venta, 1) . "%). Stock actual ({$stock_total_tienda}) en riesgo de no cubrir LT de {$lead_time} semanas. Se autoriza la carga (cualquier método: TRF/XD/CL) para evitar quiebre.";
            } else {
                $resultado['nota_aprobacion'] = "✅ APROBADO: Detección de demanda emergente (Semana 1: {$vta_sem_1} vs Semana 2: {$vta_sem_2}). Stock bajo. Se autoriza la carga (cualquier método: TRF/XD/CL) para evitar quiebre.";
            }
            return $resultado;
        }

        // ==========================================
        // REGLA 3: SDS ACTUAL EXCESIVA (> 12 SEMANAS)
        // ==========================================
        if ($semanas_stock_actual > 12) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = "SDS actual de " . round($semanas_stock_actual, 1) . " semanas excede el máximo permitido (12 semanas). Stock actual en tienda: {$stock_total_tienda}, PV6: {$pv6}.";
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // ==========================================
        // REGLA 4: CANTIDAD SOLICITADA EXCESIVA (SDS PROYECTADA > 12)
        // ==========================================
        if ($semanas_stock_proyectadas > 12) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = "Cantidad excesiva: Con esta solicitud alcanzarías " . round($semanas_stock_proyectadas, 1) . " semanas de stock (máximo 12). Stock actual: {$stock_total_tienda}, Carga solicitada: {$cantidad_solicitada}. Verifique si hubo un error de tipeo.";
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // ==========================================
        // REGLA 5: STOCK ALTO + SIN SALDO EN BODEGA + TRANSFERENCIA (TRF)
        // ==========================================
        if ($semanas_stock_actual >= 10 && $md_sugerido === 'TRF' && $disp_bod == 0) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = "No hay saldo en bodega (disp_bod=0) y semanas de stock actuales >= 10. No se puede cargar por Transferencia (TRF).";
            $resultado['cantidad_autorizada'] = 0;
            $resultado['md_forzado'] = 'RECHAZADO';
            return $resultado;
        }

        // Si pasa todas las reglas, se aprueba normalmente
        return $resultado;
    }
}
?>