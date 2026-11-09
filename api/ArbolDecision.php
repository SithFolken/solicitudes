<?php
// Forzar limpieza de caché en producción
if (function_exists('opcache_reset')) {
    opcache_reset();
}

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

        $notas_aprobacion = [];

        // ==========================================
        // REGLA 0: FUERA DEL MIX
        // ==========================================
        if (empty($datos_sugerido)) {
            $resultado['puede_cargar'] = false;
            $resultado['motivo_rechazo'] = 'Fuera del mix: Se debe solicitar Conectar al JL para cargar.';
            $resultado['cantidad_autorizada'] = 0;
            return $resultado;
        }

        // ==========================================
        // ✅ REGLA 0.1: VALIDACIÓN DE CASEPACK ACTIVO
        // ==========================================
        if ($pdo) {
            $sql_casepack = "SELECT 1 FROM sodimac_grt.case_pack_maestro WHERE sku = :sku AND CP_activo = 1 LIMIT 1";
            $stmt_casepack = $pdo->prepare($sql_casepack);
            $stmt_casepack->execute(['sku' => $sku]);
            $tiene_casepack_activo = $stmt_casepack->fetch();
            
            if (!$tiene_casepack_activo) {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "📦 CASEPACK INACTIVO: Este producto no tiene un casepack activo configurado para ningún proveedor. No es posible cargarlo por ninguna plataforma. Contacte al administrador para activar el casepack.";
                $resultado['cantidad_autorizada'] = 0;
                return $resultado;
            }
        }

                // ==========================================
        // ✅ REGLA 0.2: VALIDACIÓN DE CAPACIDAD MÁXIMA (CAMIÓN COMPLETO) CON PROYECCIÓN DE CONSUMO
        // ==========================================
        $id_proveedor = $datos_sugerido['id_proveedor'] ?? null;
        
        if ($pdo && $id_proveedor) {
            $sql_prov_camion = "SELECT 1 FROM Analisis_Procesos.config_proveedores_camion_completo 
                                WHERE id_proveedor = :id_proveedor AND activo = 1 LIMIT 1";
            $stmt_prov = $pdo->prepare($sql_prov_camion);
            $stmt_prov->execute(['id_proveedor' => $id_proveedor]);
            $es_proveedor_camion = $stmt_prov->fetch();

            if ($es_proveedor_camion) {
                $limite_maximo = 0;
                $id_tienda_evaluar = (int)($datos_sugerido['id_tienda'] ?? 0);
                
                // A. Prioridad: 'maxduro' en levantamiento_tienda
                if ($id_tienda_evaluar > 0) {
                    $sql_maxduro = "SELECT maxduro FROM sodimac_grt.levantamiento_tienda 
                                    WHERE sku = :sku AND id_tienda = :id_tienda LIMIT 1";
                    $stmt_maxduro = $pdo->prepare($sql_maxduro);
                    $stmt_maxduro->execute(['sku' => $sku, 'id_tienda' => $id_tienda_evaluar]);
                    $row_maxduro = $stmt_maxduro->fetch(PDO::FETCH_ASSOC);
                    
                    if ($row_maxduro && isset($row_maxduro['maxduro']) && $row_maxduro['maxduro'] > 0) {
                        $limite_maximo = (int)$row_maxduro['maxduro'];
                    }
                }
                
                // B. Fallback: 'capacity' de sugerido_diario
                if ($limite_maximo == 0 && isset($datos_sugerido['capacity']) && $datos_sugerido['capacity'] > 0) {
                    $limite_maximo = (int)$datos_sugerido['capacity'];
                }

                // C. Aplicar la restricción con proyección inteligente de consumo
                if ($limite_maximo > 0) {
                    $disp_tda = (float)($datos_sugerido['disp_tda'] ?? 0);
                    $pend_tda = (float)($datos_sugerido['pend_tda'] ?? 0);
                    $inventario_proyectado_hoy = $disp_tda + $pend_tda + $cantidad_solicitada;
                    
                    // Calcular demanda base
                    $pv6 = (float)($datos_sugerido['PV6'] ?? 0);
                    $pv3 = (float)($datos_sugerido['pv3'] ?? $datos_sugerido['PV3'] ?? 0);
                    $demanda_base = max($pv6, $pv3);
                    
                    // LT viene en DÍAS, convertir a SEMANAS para multiplicar por demanda semanal
                    $lead_time_dias = (float)($datos_sugerido['lead_time_total'] ?? $datos_sugerido['lt'] ?? 0);
                    $lead_time_semanas = $lead_time_dias / 7;
                    $consumo_estimado_lt = ($demanda_base > 0 && $lead_time_semanas > 0) ? ($demanda_base * $lead_time_semanas) : 0;
                    
                    // Inventario real esperado cuando el camión llegue a la tienda
                    $inventario_al_llegada = max(0, $inventario_proyectado_hoy - $consumo_estimado_lt);
                    
                    if ($inventario_al_llegada > $limite_maximo) {
                        // ❌ RECHAZO REAL
                        $resultado['puede_cargar'] = false;
                        $resultado['motivo_rechazo'] = "🚚 RESTRICCIÓN CAMIÓN COMPLETO: El inventario al llegar (" . round($inventario_al_llegada) . " uds) superará el límite ({$limite_maximo} uds), incluso descontando el consumo del LT segun VTA. Prom. (" . round($consumo_estimado_lt) . " uds).";
                        $resultado['cantidad_autorizada'] = 0;
                        return $resultado;
                        
                    } elseif ($inventario_proyectado_hoy > $limite_maximo) {
                        // ✅ APROBACIÓN CON ADVERTENCIA
                        $notas_aprobacion[] = "⚠️ CAMIÓN COMPLETO: Se autoriza porque, aunque hoy se supera el límite ({$inventario_proyectado_hoy} uds), el consumo durante el LT segun VTA. Prom. (" . round($consumo_estimado_lt) . " uds) dejará el stock en " . round($inventario_al_llegada) . " uds al llegar.";
                    }
                }
            }
        }

        // ==========================================
        // 1. EXTRAER VARIABLES (Necesarias para las reglas siguientes)
        // ==========================================
        $id_tienda_evaluar = (int)($datos_sugerido['id_tienda'] ?? 0);
        $disp_tda = (float)($datos_sugerido['disp_tda'] ?? 0);
        $pend_tda = (float)($datos_sugerido['pend_tda'] ?? 0);
        $pv6 = (float)($datos_sugerido['PV6'] ?? 0);
        
        $pv3 = (float)($datos_sugerido['pv3'] ?? $datos_sugerido['PV3'] ?? 0);
        $demanda_base = max($pv6, $pv3);
        
        $disp_bod = (float)($datos_sugerido['disp_bod'] ?? 0);
        $pend_bod = (float)($datos_sugerido['pend_bod'] ?? 0);
        $md_sugerido = strtoupper(trim($datos_sugerido['MD_sugerido'] ?? $datos_sugerido['md_defecto_sku'] ?? ''));
        
        $min_despacho_raw = $datos_sugerido['min_despacho'] ?? $datos_sugerido['MIN'] ?? $datos_sugerido['min'] ?? 0;
        $min_despacho = (float)$min_despacho_raw;

        $vta_sem_1 = (float)($datos_sugerido['vta_sem_1'] ?? $datos_sugerido['v1'] ?? 0);
        $vta_sem_2 = (float)($datos_sugerido['vta_sem_2'] ?? $datos_sugerido['v2'] ?? 0);
        $vta_sem_3 = (float)($datos_sugerido['vta_sem_3'] ?? $datos_sugerido['v3'] ?? 0);
        $lead_time = (float)($datos_sugerido['lead_time_total'] ?? $datos_sugerido['lt'] ?? 0);

        $stock_total_tienda = $disp_tda + $pend_tda;
        $es_quiebre = ($stock_total_tienda == 0);
        $stock_bajo_min = ($min_despacho > 0 && $stock_total_tienda < $min_despacho);

        // ==========================================
        // REGLA 0.5: VALIDACIÓN DE PESO MÁXIMO POR CLASE DE PELIGRO EN TIENDA
        // ==========================================
        $sql_peligroso = "SELECT clase FROM sodimac_grt.codigos_peligrosos WHERE sku = :sku LIMIT 1";
        $stmt_peligroso = $pdo->prepare($sql_peligroso);
        $stmt_peligroso->execute(['sku' => $sku]);
        $info_peligro = $stmt_peligroso->fetch(PDO::FETCH_ASSOC);

        if ($info_peligro && !empty($info_peligro['clase'])) {
            $clase_peligro = (int)$info_peligro['clase'];
            
            if ($id_tienda_evaluar > 0) {
                $sql_restriccion = "SELECT restriccion_kg, descripcion_clase FROM sodimac_grt.clases_peligrosos WHERE id_clase = :clase LIMIT 1";
                $stmt_rest = $pdo->prepare($sql_restriccion);
                $stmt_rest->execute(['clase' => $clase_peligro]);
                $restriccion = $stmt_rest->fetch(PDO::FETCH_ASSOC);
                
                $limite_kg = $restriccion ? (float)$restriccion['restriccion_kg'] : 0;
                $nombre_clase = $restriccion['descripcion_clase'] ?? "Clase $clase_peligro";
                
                if ($limite_kg > 0) {
                    $sql_peso = "SELECT UNIT_WT FROM sodimac_grt.MAEMED WHERE SKU = :sku LIMIT 1";
                    $stmt_peso = $pdo->prepare($sql_peso);
                    $stmt_peso->execute(['sku' => $sku]);
                    $peso_unitario = (float)($stmt_peso->fetchColumn() ?? 0);
                    
                    if ($peso_unitario > 0) {
                        $peso_solicitado = $cantidad_solicitada * $peso_unitario;
                        
                        $sql_peso_actual = "
                            SELECT SUM((COALESCE(sd.disp, 0) + COALESCE(sd.pend, 0)) * COALESCE(m.UNIT_WT, 0)) as peso_total_clase
                            FROM rct.sugerido_diario sd
                            INNER JOIN sodimac_grt.codigos_peligrosos cp ON sd.sku = cp.sku
                            INNER JOIN sodimac_grt.MAEMED m ON sd.sku = m.SKU
                            WHERE sd.id_tienda = :id_tienda 
                              AND cp.clase = :clase
                              AND m.UNIT_WT > 0
                        ";
                        $stmt_peso_actual = $pdo->prepare($sql_peso_actual);
                        $stmt_peso_actual->execute([
                            'id_tienda' => $id_tienda_evaluar,
                            'clase' => $clase_peligro
                        ]);
                        $peso_actual_tienda = (float)($stmt_peso_actual->fetchColumn() ?? 0);
                        
                        $peso_proyectado = $peso_actual_tienda + $peso_solicitado;
                        $limite_con_tolerancia = $limite_kg * 1.10; // 10% de tolerancia
                        
                        $consumo_estimado_lt_peso = ($pv6 > 0 && $lead_time > 0) ? ($pv6 * $lead_time * $peso_unitario) : 0;
                        $peso_estimado_al_llegar = max(0, $peso_proyectado - $consumo_estimado_lt_peso);
                        
                        if ($peso_proyectado > $limite_con_tolerancia) {
                            $resultado['puede_cargar'] = false;
                            $resultado['motivo_rechazo'] = "🚨 RESTRICCIÓN DE SEGURIDAD ($nombre_clase): El peso proyectado de la Clase $clase_peligro en esta tienda (" . round($peso_proyectado, 2) . " kg) supera el límite máximo de seguridad con tolerancia del 10% (" . round($limite_con_tolerancia, 2) . " kg). Límite estricto: " . round($limite_kg, 2) . " kg.";
                            $resultado['cantidad_autorizada'] = 0;
                            return $resultado;
                        }
                        
                        if ($peso_estimado_al_llegar <= $limite_kg && $peso_proyectado > $limite_kg) {
                            $notas_aprobacion[] = "⚠️ SEGURIDAD ($nombre_clase): Peso proyectado (" . round($peso_proyectado, 2) . " kg) supera el límite (" . round($limite_kg, 2) . " kg), pero se aprueba porque el consumo estimado durante el LT (" . round($consumo_estimado_lt_peso, 2) . " kg) lo dejará bajo el límite al momento de la llegada (" . round($peso_estimado_al_llegar, 2) . " kg).";
                        } elseif ($peso_proyectado > $limite_kg && $peso_proyectado <= $limite_con_tolerancia) {
                            $notas_aprobacion[] = "⚠️ SEGURIDAD ($nombre_clase): Peso proyectado (" . round($peso_proyectado, 2) . " kg) supera el límite estricto (" . round($limite_kg, 2) . " kg), pero se aprueba dentro de la tolerancia del 10% (" . round($limite_con_tolerancia, 2) . " kg).";
                        } else {
                            $notas_aprobacion[] = "✅ SEGURIDAD ($nombre_clase): Peso proyectado (" . round($peso_proyectado, 2) . " kg) dentro del límite permitido (" . round($limite_kg, 2) . " kg).";
                        }
                    } else {
                        $notas_aprobacion[] = "⚠️ SEGURIDAD ($nombre_clase): No se pudo validar el peso porque el SKU no tiene UNIT_WT registrado en MAEMED.";
                    }
                }
            }
        }

        error_log("ARBOL: SKU=$sku | MIN=$min_despacho | DEMANDA_BASE=$demanda_base (PV6=$pv6, PV3=$pv3) | Cant=$cantidad_solicitada | Stock=$stock_total_tienda | Quiebre=" . ($es_quiebre ? 'SI' : 'NO') . " | BajoMIN=" . ($stock_bajo_min ? 'SI' : 'NO'));
        
        // ==========================================
        // PASO 1: REDONDEO AL MIN
        // ==========================================
        if ($min_despacho > 0 && $cantidad_solicitada < $min_despacho) {
            $multiplos = ceil($cantidad_solicitada / $min_despacho);
            $cantidad_solicitada = $multiplos * $min_despacho;
            $resultado['cantidad_original'] = $cantidad_original;
            $resultado['cantidad_autorizada'] = $cantidad_solicitada;
            $notas_aprobacion[] = "Cantidad ajustada al MIN ($min_despacho): $cantidad_original -> $cantidad_solicitada.";
        }

        // ==========================================
        // PASO 2: VALIDACIÓN DE QUIEBRE (con ajuste automático al máximo permitido)
        // ==========================================
        if ($es_quiebre) {
            $limite_quiebre = 0;
            $tipo_limite = '';
            
            if ($min_despacho > 0 && $demanda_base > 0) {
                $limite_min = $min_despacho * 2;
                $limite_demanda = (int)ceil($demanda_base * 2);
                $limite_quiebre = max($limite_min, $limite_demanda);
                $tipo_limite = "Mayor entre 2x MIN ($limite_min) y 2x Demanda ($limite_demanda)";
            } elseif ($min_despacho > 0) {
                $limite_quiebre = $min_despacho * 2;
                $tipo_limite = "2x MIN (MIN=$min_despacho, sin ventas)";
            } elseif ($demanda_base > 0) {
                $limite_quiebre = (int)ceil($demanda_base * 2);
                $tipo_limite = "2x Demanda (Mayor de PV3/PV6)";
            } else {
                $limite_quiebre = 100;
                $tipo_limite = "tope absoluto (sin datos)";
            }
            
            if ($min_despacho > 0) {
                $limite_redondeado = (int)(floor($limite_quiebre / $min_despacho) * $min_despacho);
                if ($limite_redondeado < $min_despacho) {
                    $limite_redondeado = $min_despacho;
                }
            } else {
                $limite_redondeado = $limite_quiebre;
            }
            
            if ($cantidad_solicitada > $limite_redondeado) {
                $cantidad_original_quiebre = $cantidad_solicitada;
                $cantidad_solicitada = $limite_redondeado;
                $resultado['cantidad_autorizada'] = $limite_redondeado; 
                $notas_aprobacion[] = "Cantidad ajustada de $cantidad_original_quiebre a $limite_redondeado (límite por quiebre basado en demanda máxima).";
            }
            
            $notas_aprobacion[] = "Quiebre aprobado ($cantidad_solicitada <= $limite_redondeado).";
        } else {
            
            // ==========================================
            // PASO 3: VALIDACIÓN DE STOCK EN BODEGA (PRIORIDAD ALTA)
            // ==========================================
            if ($md_sugerido === 'COMPRA_LOCAL' || $md_sugerido === 'CL' || 
                $md_sugerido === 'CROSS_DOCKING' || $md_sugerido === 'XD') {
                // No validar stock en bodega para estos métodos
            } elseif ($disp_bod == 0) {
                
                $tendencia_venta = ($vta_sem_3 > 0) ? (($vta_sem_1 - $vta_sem_3) / $vta_sem_3) * 100 : 0;
                $stock_necesario_lt = ($demanda_base > 0 && $lead_time > 0) ? ($demanda_base * $lead_time) : 0;
                $es_tendencia_alza = $tendencia_venta > 20;
                $riesgo_quiebre_lt = ($stock_total_tienda < $stock_necesario_lt) && ($demanda_base > 0);
                $demanda_emergente = ($vta_sem_1 > 0 && $vta_sem_2 > 0 && $vta_sem_1 > ($vta_sem_2 * 1.5));
                $aplica_bypass = ($es_tendencia_alza && $riesgo_quiebre_lt) || ($demanda_emergente && $stock_total_tienda < ($demanda_base * 2));

                if ($aplica_bypass && $cantidad_solicitada > 0 && $cantidad_solicitada <= ($stock_necesario_lt * 2)) {
                    $resultado['puede_cargar'] = true;
                    $notas_aprobacion[] = "BYPASS: " . ($es_tendencia_alza ? "Tendencia al alza" : "Demanda emergente") . " (Sin stock en bodega, pero se autoriza por emergencia).";
                    $resultado['nota_aprobacion'] = implode(' | ', $notas_aprobacion);
                    return $resultado;
                }

                if ($pend_bod > 0) {
                    $motivo_base = "RECHAZO: Sin stock disponible en bodega actualmente. Existen $pend_bod uds pendientes en camino.";
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
                                    $resultado['motivo_rechazo'] = "$motivo_base Detalle: $total_detalle uds en estado '$estado_mostrar' con ETA estimada para el $fecha_formateada.";
                                    $resultado['cantidad_autorizada'] = 0;
                                    return $resultado;
                                }
                            }
                        } catch (PDOException $e) {
                            error_log("Error SP pendientes: " . $e->getMessage());
                        }
                    }
                    $resultado['puede_cargar'] = false;
                    $resultado['motivo_rechazo'] = "$motivo_base (No se pudieron obtener los detalles de la programación).";
                    $resultado['cantidad_autorizada'] = 0;
                    return $resultado;
                } else {
                    $resultado['puede_cargar'] = false;
                    $resultado['motivo_rechazo'] = "RECHAZO TOTAL: Sin saldo disponible en CD y sin unidades en camino. Desconectar del mix o solicitar reemplazo.";
                    $resultado['cantidad_autorizada'] = 0;
                    return $resultado;
                }
            }

            // ==========================================
            // PASO 4: VALIDACIONES SDS Y EXCEPCIÓN DE MIN
            // ==========================================
            if (!$stock_bajo_min) {
                $semanas_stock_actual = ($demanda_base > 0) ? ($stock_total_tienda / $demanda_base) : 999;
                $stock_proyectado = $stock_total_tienda + $cantidad_solicitada;
                $semanas_stock_proyectadas = ($demanda_base > 0) ? ($stock_proyectado / $demanda_base) : 999;

                if ($semanas_stock_actual > 12) {
                    $resultado['puede_cargar'] = false;
                    $resultado['motivo_rechazo'] = "SDS actual de " . round($semanas_stock_actual, 1) . " semanas excede el maximo (12). Stock: $stock_total_tienda, Demanda Base: $demanda_base.";
                    $resultado['cantidad_autorizada'] = 0;
                    return $resultado;
                }

                if ($semanas_stock_proyectadas > 12) {
                    $resultado['puede_cargar'] = false;
                    $resultado['motivo_rechazo'] = "Cantidad excesiva: alcanzarías " . round($semanas_stock_proyectadas, 1) . " semanas (max 12). Stock: $stock_total_tienda, Carga: $cantidad_solicitada, Demanda Base: $demanda_base.";
                    $resultado['cantidad_autorizada'] = 0;
                    return $resultado;
                }
            } else {
                error_log("ARBOL: EXCEPCIÓN APLICADA - Stock ($stock_total_tienda) < MIN ($min_despacho). Se omite validación de SDS para permitir reposición.");
                $notas_aprobacion[] = "Excepción: Stock bajo MIN ($stock_total_tienda < $min_despacho), se permite carga sin validar límite de SDS.";
            }
        }

        // ==========================================
        // PASO 5: BYPASS DE EMERGENCIA
        // ==========================================
        $tendencia_venta = ($vta_sem_3 > 0) ? (($vta_sem_1 - $vta_sem_3) / $vta_sem_3) * 100 : 0;
        $stock_necesario_lt = ($demanda_base > 0 && $lead_time > 0) ? ($demanda_base * $lead_time) : 0;
        $es_tendencia_alza = $tendencia_venta > 20;
        $riesgo_quiebre_lt = ($stock_total_tienda < $stock_necesario_lt) && ($demanda_base > 0);
        $demanda_emergente = ($vta_sem_1 > 0 && $vta_sem_2 > 0 && $vta_sem_1 > ($vta_sem_2 * 1.5));
        $aplica_bypass = ($es_tendencia_alza && $riesgo_quiebre_lt) || ($demanda_emergente && $stock_total_tienda < ($demanda_base * 2));

        if ($aplica_bypass && $cantidad_solicitada > 0 && $cantidad_solicitada <= ($stock_necesario_lt * 2)) {
            if (strpos(implode(' | ', $notas_aprobacion), 'BYPASS') === false) {
                $notas_aprobacion[] = "BYPASS: " . ($es_tendencia_alza ? "Tendencia al alza" : "Demanda emergente") . ".";
            }
        }

        // ==========================================
        // PASO 6: REGLA 5 - STOCK ALTO + SIN BODEGA + TRF
        // ==========================================
        if (!$es_quiebre && !$stock_bajo_min) {
            $semanas_stock_actual = ($demanda_base > 0) ? ($stock_total_tienda / $demanda_base) : 999;
            if ($semanas_stock_actual >= 10 && $md_sugerido === 'TRF' && $disp_bod == 0) {
                $resultado['puede_cargar'] = false;
                $resultado['motivo_rechazo'] = "Sin saldo en bodega y SDS >= 10. No se puede cargar por TRF.";
                $resultado['cantidad_autorizada'] = 0;
                $resultado['md_forzado'] = 'RECHAZADO';
                return $resultado;
            }
        }

        $resultado['nota_aprobacion'] = !empty($notas_aprobacion) ? implode(' | ', $notas_aprobacion) : null;

        return $resultado;
    }
}
?>