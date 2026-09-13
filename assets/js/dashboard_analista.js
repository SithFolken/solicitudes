// assets/js/dashboard_analista.js

document.addEventListener('DOMContentLoaded', () => {
    cargarDashboardAnalista();
});

async function cargarDashboardAnalista() {
    try {
        const response = await fetch('../api/obtener_kpis_analista.php');
        const data = await response.json();

        ocultarLoading();

        if (!data.success) {
            console.error('Error cargando dashboard:', data.message);
            mostrarError('No se pudieron cargar los datos del dashboard: ' + (data.message || 'Error desconocido'));
            return;
        }

        // Actualizar KPIs
        document.getElementById('kpiPendientes').textContent = data.kpis.pendientes || 0;
        document.getElementById('kpiProcesadasHoy').textContent = data.kpis.procesadasHoy || 0;
        document.getElementById('kpiTasaAprobacion').textContent = (data.kpis.tasaAprobacion || 0) + '%';
        document.getElementById('kpiTotalSKUs').textContent = data.kpis.totalSKUs || 0;

        document.getElementById('kpiSlaTiempo').textContent = data.kpis.sla_a_tiempo || 0;
        document.getElementById('kpiSlaUrgente').textContent = data.kpis.sla_por_cumplirse || 0;
        document.getElementById('kpiSlaVencidas').textContent = data.kpis.sla_vencidas || 0;

        // Gráficos con datos validados
        if (data.graficos.porEstado?.length > 0) crearGraficoEstado(data.graficos.porEstado);
        if (data.graficos.porDias?.length > 0) crearGraficoDias(data.graficos.porDias);
        if (data.graficos.porMD?.length > 0) crearGraficoMD(data.graficos.porMD);
        if (data.graficos.topTiendas?.length > 0) crearGraficoTiendas(data.graficos.topTiendas);
        
        // Gráfico 5: Top SKUs con drill-down
        if (data.graficos.topSKUs?.length > 0) {
            crearGraficoTopSKUs(data.graficos.topSKUs);
        }

    } catch (error) {
        console.error('Error cargando dashboard:', error);
        ocultarLoading();
        mostrarError('Error de conexión: ' + error.message);
    }
}

function ocultarLoading() {
    const loading = document.getElementById('loadingOverlay');
    if (loading) loading.style.display = 'none';
}

function mostrarError(mensaje) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'error', title: 'Error', text: mensaje, confirmButtonColor: '#0056b3' });
    } else {
        alert('Error: ' + mensaje);
    }
}

// ==========================================
// 🎨 PALETA DE COLORES CORPORATIVOS HIGHCHARTS
// ==========================================
const coloresCorp = {
    azul: '#0056b3',
    azulOscuro: '#003d82',
    amarillo: '#ffc107',
    rojo: '#dc3545',
    verde: '#198754',
    gris: '#6c757d',
    azulClaro: '#4dabf7'
};

const gradientesCorp = {
    primario: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#0056b3'], [1, '#003d82']]
    },
    exito: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#198754'], [1, '#146c43']]
    },
    advertencia: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#ffc107'], [1, '#e0a800']]
    },
    info: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#4dabf7'], [1, '#0056b3']]
    }
};

// ==========================================
// Gráfico 1: Distribución por Estado (DONUT CORPORATIVO)
// ==========================================
function crearGraficoEstado(datos) {
    const series = datos.map(item => ({
        name: item.estado_general,
        y: parseInt(item.total),
        color: obtenerColorPorEstado(item.estado_general)
    }));

    Highcharts.chart('chartEstado', {
        chart: { type: 'pie', backgroundColor: 'transparent', spacing: [20, 20, 20, 20] },
        title: { text: null },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#e0e0e0',
            borderRadius: 10,
            shadow: true,
            style: { fontSize: '12px', fontWeight: '500' },
            pointFormat: '{series.name}: <b>{point.y}</b> ({point.percentage:.1f}%)'
        },
        accessibility: { point: { valueSuffix: '' } },
        plotOptions: {
            pie: {
                allowPointSelect: true,
                cursor: 'pointer',
                borderRadius: 8,
                borderWidth: 3,
                borderColor: '#ffffff',
                shadow: { color: 'rgba(0, 0, 0, 0.1)', offsetX: 0, offsetY: 3, width: 10 },
                dataLabels: {
                    enabled: true,
                    distance: -30,
                    style: { fontSize: '11px', fontWeight: '600', textOutline: 'none', color: '#2c3e50' },
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%'
                },
                startAngle: -90,
                endAngle: 270,
                center: ['50%', '55%'],
                size: '85%'
            }
        },
        series: [{ name: 'Estado', colorByPoint: false, data: series, innerSize: '55%' }]
    });
}

function obtenerColorPorEstado(estado) {
    const colores = {
        'PENDIENTE': coloresCorp.azul,
        'EN_PROCESO': coloresCorp.amarillo,
        'PROCESADA': coloresCorp.verde,
        'PROCESADA_PARCIAL': coloresCorp.amarillo,
        'RECHAZADA': coloresCorp.rojo,
        'APROBADA': coloresCorp.verde
    };
    return colores[estado] || coloresCorp.gris;
}

// ==========================================
// Gráfico 2: Solicitudes por Día (BARRAS CORPORATIVAS)
// ==========================================
function crearGraficoDias(datos) {
    const categorias = datos.map(item => {
        const fecha = new Date(item.fecha);
        return fecha.toLocaleDateString('es-CL', { day: '2-digit', month: 'short' });
    });
    const valores = datos.map(item => parseInt(item.total));

    Highcharts.chart('chartDias', {
        chart: { type: 'column', backgroundColor: 'transparent', spacing: [20, 20, 20, 20] },
        title: { text: null },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#e0e0e0',
            borderRadius: 10,
            shadow: true,
            style: { fontSize: '12px', fontWeight: '500' },
            headerFormat: '<b>{point.key}</b><br/>',
            pointFormat: 'Solicitudes: <b>{point.y}</b>'
        },
        xAxis: {
            categories: categorias,
            title: { text: null },
            gridLineWidth: 0,
            minorGridLineWidth: 0,
            labels: { style: { fontSize: '11px', fontWeight: '500', color: coloresCorp.gris } }
        },
        yAxis: {
            min: 0,
            title: { text: null },
            gridLineColor: '#f0f0f0',
            labels: { style: { fontSize: '11px', color: coloresCorp.gris } }
        },
        legend: { enabled: false },
        plotOptions: {
            column: {
                borderRadius: 8,
                borderWidth: 0,
                shadow: { color: 'rgba(0, 86, 179, 0.2)', offsetX: 0, offsetY: 3, width: 10 },
                dataLabels: {
                    enabled: true,
                    style: { fontSize: '11px', fontWeight: '700', color: coloresCorp.azul, textOutline: 'none' },
                    format: '{point.y}'
                },
                pointPadding: 0.3,
                groupPadding: 0.2
            }
        },
        series: [{
            name: 'Solicitudes Procesadas',
            data: valores,
            color: gradientesCorp.primario // ✅ Usa el gradiente azul corporativo
        }]
    });
}

// ==========================================
// Gráfico 3: Por Método de Compra (DONUT CORPORATIVO)
// ==========================================
function crearGraficoMD(datos) {
    const series = datos.map(item => ({
        name: item.md || 'Sin MD',
        y: parseInt(item.total),
        color: obtenerColorPorMD(item.md)
    }));

    Highcharts.chart('chartMD', {
        chart: { type: 'pie', backgroundColor: 'transparent', spacing: [20, 20, 20, 20] },
        title: { text: null },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#e0e0e0',
            borderRadius: 10,
            shadow: true,
            style: { fontSize: '12px', fontWeight: '500' },
            pointFormat: '{series.name}: <b>{point.y}</b> ({point.percentage:.1f}%)'
        },
        plotOptions: {
            pie: {
                allowPointSelect: true,
                cursor: 'pointer',
                borderRadius: 8,
                borderWidth: 3,
                borderColor: '#ffffff',
                shadow: { color: 'rgba(0, 0, 0, 0.1)', offsetX: 0, offsetY: 3, width: 10 },
                dataLabels: {
                    enabled: true,
                    distance: -30,
                    style: { fontSize: '11px', fontWeight: '600', textOutline: 'none', color: '#2c3e50' },
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%'
                },
                startAngle: -90,
                endAngle: 270,
                center: ['50%', '55%'],
                size: '85%'
            }
        },
        series: [{ name: 'Método', colorByPoint: false, data: series, innerSize: '55%' }]
    });
}

function obtenerColorPorMD(md) {
    const colores = {
        'TRANSFERENCIA': coloresCorp.azul,
        'CROSS_DOCKING': coloresCorp.amarillo,
        'COMPRA_LOCAL': coloresCorp.rojo
    };
    return colores[md] || coloresCorp.gris;
}

// ==========================================
// Gráfico 4: Top Tiendas (BARRAS HORIZONTALES CORPORATIVAS)
// ==========================================
function crearGraficoTiendas(datos) {
    while (datos.length < 5) {
        datos.push({ id_tienda: 0, total: 0 });
    }

    const categorias = datos.map(item => {
        if (item.id_tienda && item.id_tienda > 0) {
            return `Tienda ${item.id_tienda}`;
        } else {
            return 'Sin datos';
        }
    });
    const valores = datos.map(item => parseInt(item.total));

    Highcharts.chart('chartTiendas', {
        chart: { type: 'bar', backgroundColor: 'transparent', spacing: [20, 20, 20, 20] },
        title: { text: null },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#e0e0e0',
            borderRadius: 10,
            shadow: true,
            style: { fontSize: '12px', fontWeight: '500' },
            headerFormat: '<b>{point.key}</b><br/>',
            pointFormat: 'Solicitudes: <b>{point.y}</b>'
        },
        xAxis: {
            categories: categorias,
            title: { text: null },
            gridLineWidth: 0,
            labels: { style: { fontSize: '11px', fontWeight: '500', color: coloresCorp.gris, textOverflow: 'ellipsis' } }
        },
        yAxis: {
            min: 0,
            title: { text: null },
            gridLineColor: '#f0f0f0',
            labels: { style: { fontSize: '11px', color: coloresCorp.gris } },
            reversed: true
        },
        legend: { enabled: false },
        plotOptions: {
            bar: {
                borderRadius: 8,
                borderWidth: 0,
                shadow: { color: 'rgba(0, 86, 179, 0.2)', offsetX: 0, offsetY: 3, width: 10 },
                dataLabels: {
                    enabled: true,
                    style: { fontSize: '11px', fontWeight: '700', color: coloresCorp.azul, textOutline: 'none' },
                    format: '{point.y}'
                },
                pointPadding: 0.3,
                groupPadding: 0.2
            }
        },
        series: [{
            name: 'Solicitudes',
            data: valores,
            color: gradientesCorp.info // ✅ Usa el gradiente azul corporativo
        }]
    });
}

// ==========================================
// Gráfico 5: Top SKUs Más Solicitados (BARRAS HORIZONTALES CON DESCRIPCIÓN)
// ==========================================
function crearGraficoTopSKUs(datos) {
    console.log("📊 Datos Top SKUs:", datos);
    
    if (!datos || datos.length === 0) {
        console.error("❌ No hay datos");
        return;
    }

    const dataPrincipal = datos.map((item, index) => {
        const desc = item.descripcion || 'Sin descripción';
        const sku = item.sku;
        const label = desc.length > 50 ? desc.substring(0, 50) + '...' : desc;
        
        return {
            name: label,
            y: parseInt(item.total),
            drilldown: sku,
            color: obtenerColorPorIndex(index),
            custom: { sku: sku, descripcion: desc }
        };
    });

    const seriesDrilldown = datos.map(item => {
        const tiendasData = (item.tiendas || []).map(t => ({
            name: `Tienda ${t.id_tienda}`,
            y: parseInt(t.total),
            color: coloresCorp.azul // ✅ Color corporativo para drilldown
        }));
        
        return {
            id: item.sku,
            name: `Tiendas - ${item.sku}`,
            data: tiendasData,
            type: 'bar'
        };
    });

    Highcharts.chart('chartTopSKUs', {
        chart: {
            type: 'bar',
            height: 500,
            backgroundColor: 'transparent',
            events: {
                drilldown: function(e) {
                    if (!e.seriesOptions) {
                        const series = seriesDrilldown.find(s => s.id === e.point.drilldown);
                        if (series) {
                            this.addSingleSeriesAsDrilldown(e.point, series);
                            this.applyDrilldown();
                        }
                    }
                },
                drillup: function() { console.log("⬆️ Drillup - volviendo"); }
            }
        },
        title: { text: null },
        subtitle: {
            text: '👆 Haz clic en una barra para ver qué tiendas solicitan este producto',
            align: 'left',
            style: { color: coloresCorp.gris, fontSize: '13px', fontStyle: 'italic' }
        },
        xAxis: {
            type: 'category',
            title: { text: 'Producto', style: { color: coloresCorp.gris, fontSize: '12px', fontWeight: '600' } },
            labels: { style: { fontSize: '11px', fontWeight: '500', color: '#495057' } },
            gridLineWidth: 0
        },
        yAxis: {
            min: 0,
            title: { text: 'Solicitudes', style: { color: coloresCorp.gris, fontSize: '12px', fontWeight: '600' } },
            gridLineColor: '#f0f0f0',
            labels: { style: { fontSize: '11px', color: coloresCorp.gris } }
        },
        tooltip: {
            backgroundColor: 'white',
            borderColor: '#ddd',
            borderRadius: 8,
            shadow: true,
            headerFormat: '<b>{point.key}</b><br/>',
            pointFormat: `<strong>SKU:</strong> {point.custom.sku}<br/><strong>Solicitudes:</strong> {point.y}<br/><em style="color: #6c757d; font-size: 11px;">Clic para ver detalle por tienda</em>`,
            useHTML: true
        },
        plotOptions: {
            bar: {
                borderRadius: 6,
                borderWidth: 0,
                pointPadding: 0.2,
                groupPadding: 0.1,
                dataLabels: {
                    enabled: true,
                    format: '{point.y}',
                    style: { fontSize: '12px', fontWeight: 'bold', color: '#2c3e50', textOutline: 'none' }
                },
                shadow: { color: 'rgba(0, 0, 0, 0.1)', offsetX: 0, offsetY: 2, width: 5 }
            }
        },
        series: [{ name: 'SKUs', colorByPoint: true, data: dataPrincipal }],
        drilldown: {
            series: seriesDrilldown,
            activeDataLabelStyle: { color: coloresCorp.azul, textDecoration: 'none', fontWeight: 'bold' },
            drillUpButton: {
                relativeTo: 'spacingBox',
                position: { y: 10, x: 0 },
                theme: {
                    fill: coloresCorp.azul, // ✅ Botón de volver azul corporativo
                    stroke: 'none',
                    r: 20,
                    padding: 10,
                    style: { color: 'white', fontWeight: 'bold', fontSize: '13px' },
                    states: { hover: { fill: coloresCorp.azulOscuro } }
                },
                text: '← Volver al Top SKUs'
            }
        },
        credits: { enabled: false }
    });
}

function obtenerColorPorIndex(index) {
    // Paleta corporativa variada para las barras del top 10
    const colores = [
        coloresCorp.azul, 
        coloresCorp.amarillo, 
        coloresCorp.rojo, 
        coloresCorp.verde, 
        coloresCorp.azulClaro,
        '#20c997', // Teal
        '#fd7e14', // Naranja
        '#6f42c1'  // Un toque de púrpura para variedad
    ];
    return colores[index % colores.length];
}