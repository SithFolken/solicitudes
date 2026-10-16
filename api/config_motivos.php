<?php
/**
 * Configuración centralizada de motivos de carga
 * Fuente única de verdad para todos los formularios
 */

$MOTIVOS_CARGA = [
    '' => 'Seleccione un motivo...',
    'APUESTA' => 'Apuesta',
    'APUESTA_PP' => 'Apuesta para PP',
    'VENTA_CLIENTE_CES' => 'Venta Cliente CES',
    'VENTA_CLIENTE_BLACK' => 'Venta Cliente Black',
    'VENTA_CLIENTE_CONTACTABILIDAD' => 'Venta Cliente Contactabilidad',
    'VENTA_CLIENTE_VE' => 'Venta Cliente VE',
    'VENTA_PUNTUAL' => 'Venta Puntual (Ventas que no pasaron por el módulo .COM)',
    'RELLENO_CAPACITY' => 'Relleno de Capacity',
    'APUESTA_MUEBLES' => 'Apuesta para Muebles de Proveedores',
    'APUESTA_CARROS' => 'Apuesta para carros de proyectos',
    'CHILECOMPRA' => 'chilecompra',
    'OTRO' => 'Otro (especificar en observaciones)'
];

/**
 * Genera el HTML del select de motivos
 * @param string $name Nombre del campo
 * @param string $selected Valor seleccionado por defecto
 * @param bool $required Si es obligatorio
 * @return string HTML del select
 */
function generarSelectMotivos($name = 'motivo', $selected = '', $required = true) {
    global $MOTIVOS_CARGA;
    
    $requiredAttr = $required ? 'required' : '';
    $html = "<select name=\"{$name}\" id=\"{$name}\" class=\"form-select\" {$requiredAttr}>\n";
    
    foreach ($MOTIVOS_CARGA as $valor => $texto) {
        $isSelected = ($valor === $selected) ? 'selected' : '';
        $disabled = ($valor === '') ? 'disabled' : '';
        $html .= "<option value=\"{$valor}\" {$isSelected} {$disabled}>{$texto}</option>\n";
    }
    
    $html .= "</select>";
    return $html;
}

/**
 * Valida que el motivo sea válido
 * @param string $motivo
 * @return bool
 */
function validarMotivo($motivo) {
    global $MOTIVOS_CARGA;
    return isset($MOTIVOS_CARGA[$motivo]) && $motivo !== '';
}

/**
 * Obtiene el texto descriptivo de un motivo
 * @param string $motivo
 * @return string
 */
function getMotivoTexto($motivo) {
    global $MOTIVOS_CARGA;
    return $MOTIVOS_CARGA[$motivo] ?? 'Motivo no válido';
}
?>