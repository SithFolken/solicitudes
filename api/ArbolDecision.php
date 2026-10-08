<?php
/**
 * Clase para evaluar las reglas de negocio (Árbol de Decisión)
 */
class ArbolDecision {

    public static function evaluar($sku, $cantidad_solicitada, $datos_sugerido, $pdo = null) {
        $cantidad_original = $cantidad_solicitada;
        
        $resultado = [
            'sku' => $sku,
            'puede_cargar' => true,
            'motivo_rechazo' => null,
            'cantidad_autorizada' => $cantidad_solicitada,
            'cantidad_original' => $cantidad_original,
            'md_forzado' => null,
            'nota_aprobacion' => null
        ];

        // REGLA 0: FUERA DEL MIX
        if (empty($datos_sugerido)) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = 'Fuera del mix: Se debe solicitar Conectar al JL para cargar.';
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // Extraer variables
        $disp_tda = (float)($datos_sugerido['disp_tda'] ?? 0);
        $pend_tda = (float)($datos_sugerido['pend_tda'] ?? 0);
        $pv6 = (float)($datos_sugerido['PV6'] ?? 0);
        $disp_bod = (float)($datos_sugerido['disp_bod'] ?? 0);
        $pend_bod = (float)($datos_sugerido['pend_bod'] ?? 0);
        $md_sugerido = strtoupper(trim($datos_sugerido['MD_sugerido'] ?? $datos_sugerido['md_defecto_sku'] ?? ''));
        
        // Leer MIN (con fallback a múltiples nombres)
        $min_despacho_raw = $datos_sugerido['min_despacho'] ?? $datos_sugerido['MIN'] ?? $datos_sugerido['min'] ?? 0;
        $min_despacho = (float)$min_despacho_raw;

        $vta_sem_1 = (float)($datos_sugerido['vta_sem_1'] ?? $datos_sugerido['v1'] ?? 0);
        $vta_sem_2 = (float)($datos_sugerido['vta_sem_2'] ?? $datos_sugerido['v2'] ?? 0);
        $vta_sem_3 = (float)($datos_sugerido['vta_sem_3'] ?? $datos_sugerido['v3'] ?? 0);
        $lead_time = (float)($datos_sugerido['lead_time_total'] ?? $datos_sugerido['lt'] ?? 0);

        // ✅ IMPORTANTE: Calcular stock y definir $es_quiebre ANTES de usarla
        $stock_total_tienda = $disp_tda + $pend_tda;
        $es_quiebre = ($stock_total_tienda == 0);
        
        // Log para depuración
        error_log("ARBOL: SKU=$sku | MIN=$min_despacho | PV6=$pv6 | Cant=$cantidad_solicitada | Quiebre=" . ($es_quiebre ? 'SI' : 'NO'));
        
        // PASO 1: REDONDEO AL MIN
        if ($min_despacho > 0 && $cantidad_solicitada < $min_despacho) {
            $multiplos = ceil($cantidad_solicitada / $min_despacho);
            $cantidad_solicitada = $multiplos * $min_despacho;
            $resultado['cantidad_original'] = $cantidad_original;
            $resultado['cantidad_autorizada'] = $cantidad_solicitada;
            $resultado['nota_aprobacion'] = "Cantidad ajustada al MIN ($min_despacho): $cantidad_original -> $cantidad_solicitada.";
        }

        // PASO 2: VALIDACIÓN DE QUIEBRE (ahora $es_quiebre ya está definida)
        if ($es_quiebre) {
            $limite_quiebre = 0;
            $tipo_limite = '';
            
            if ($min_despacho == 1) {
                $limite_quiebre = 3;
                $tipo_limite = "3x MIN (MIN=1)";
            } elseif ($min_despacho > 1) {
                $limite_quiebre = $min_despacho * 2;
                $tipo_limite = "2x MIN (MIN=$min_despacho)";
            } else {
                if ($pv6 > 0) {
                    $limite_quiebre = (int)ceil($pv6 * 12);
                    $tipo_limite = "12 semanas de stock (PV6=$pv6)";
                } else {
                    $limite_quiebre = 100;
                    $tipo_limite = "tope absoluto";
                }
            }
            
            error_log("ARBOL LIMITE: $limite_quiebre ($tipo_limite)");
            
            if ($cantidad_solicitada > $limite_quiebre) {
                $resultado['puede_cargar'] = false;
                $resultado['cantidad_autorizada'] = 0;
                $resultado['motivo_rechazo'] = "Producto en quiebre. La cantidad solicitada ($cantidad_solicitada) excede el maximo permitido: $limite_quiebre unidades ($tipo_limite). Reduzca la cantidad o contacte al analista.";
                return $resultado;
            }
            
            $resultado['nota_aprobacion'] .= " Quiebre aprobado ($cantidad_solicitada <= $limite_quiebre).";
        } else {
            // PASO 3: VALIDACIONES SDS (solo si NO es quiebre)
            $semanas_stock_actual = ($pv6 > 0) ? ($stock_total_tienda / $pv6) : 999;
            $stock_proyectado = $stock_total_tienda + $cantidad_solicitada;
            $semanas_stock_proyectadas = ($pv6 > 0) ? ($stock_proyectado / $pv6) : 999;

            if ($semanas_stock_actual > 12) {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "SDS actual de " . round($semanas_stock_actual, 1) . " semanas excede el maximo (12). Stock: $stock_total_tienda, PV6: $pv6.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            }

            if ($semanas_stock_proyectadas > 12) {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "Cantidad excesiva: alcanzarias " . round($semanas_stock_proyectadas, 1) . " semanas (max 12). Stock: $stock_total_tienda, Carga: $cantidad_solicitada.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            }
        }

        // PASO 4: REGLA 1 - SIN STOCK EN BODEGA (TRF)
        $tendencia_venta = ($vta_sem_3 > 0) ? (($vta_sem_1 - $vta_sem_3) / $vta_sem_3) * 100 : 0;
        $stock_necesario_lt = ($pv6 > 0 && $lead_time > 0) ? ($pv6 * $lead_time) : 0;
        $es_tendencia_alza = $tendencia_venta > 20;
        $riesgo_quiebre_lt = ($stock_total_tienda < $stock_necesario_lt) && ($pv6 > 0);
        $demanda_emergente = ($vta_sem_1 > 0 && $vta_sem_2 > 0 && $vta_sem_1 > ($vta_sem_2 * 1.5));
        $aplica_bypass = ($es_tendencia_alza && $riesgo_quiebre_lt) || ($demanda_emergente && $stock_total_tienda < ($pv6 * 2));

        if ($md_sugerido === 'COMPRA_LOCAL' || $md_sugerido === 'CL' || 
            $md_sugerido === 'CROSS_DOCKING' || $md_sugerido === 'XD') {
            // No validar stock en bodega
        } elseif ($disp_bod == 0) {
            if ($aplica_bypass && $cantidad_solicitada > 0 && $cantidad_solicitada <= ($stock_necesario_lt * 2)) {
                $resultado['puede_cargar'] = true;
                $resultado['nota_aprobacion'] .= " | BYPASS: " . ($es_tendencia_alza ? "Tendencia al alza" : "Demanda emergente") . ".";
                return $resultado;
            }

            if ($pend_bod > 0) {
                $motivo_base = "Sin stock en bodega, pero existen $pend_bod uds pendientes en camino.";
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
                                $resultado['motivo_rechazo'] = "$motivo_base Detalle: $total_detalle uds en estado '$estado_mostrar' con ETA $fecha_formateada.";
                                $resultado['cantidad_autorizada'] = 0;
                                return $resultado;
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Error SP pendientes: " . $e->getMessage());
                    }
                }
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "$motivo_base No se pudieron obtener detalles.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            } else {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "Sin stock en bodega y sin unidades en camino. Desconectar del mix o solicitar reemplazo.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            }
        }

        // PASO 5: BYPASS DE EMERGENCIA
        if ($aplica_bypass && $cantidad_solicitada > 0 && $cantidad_solicitada <= ($stock_necesario_lt * 2)) {
            $resultado['puede_cargar'] = true;
            $resultado['nota_aprobacion'] .= " | BYPASS: " . ($es_tendencia_alza ? "Tendencia al alza" : "Demanda emergente") . ".";
            return $resultado;
        }

        // PASO 6: REGLA 5 - STOCK ALTO + SIN BODEGA + TRF
        if (!$es_quiebre) {
            $semanas_stock_actual = ($pv6 > 0) ? ($stock_total_tienda / $pv6) : 999;
            if ($semanas_stock_actual >= 10 && $md_sugerido === 'TRF' && $disp_bod == 0) {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "Sin saldo en bodega y SDS >= 10. No se puede cargar por TRF.";
                $resultado['cantidad_autorizada'] = 0;
                $resultado['md_forzado'] = 'RECHAZADO';
                return $resultado;
            }
        }

        return $resultado;
    }
}
?>