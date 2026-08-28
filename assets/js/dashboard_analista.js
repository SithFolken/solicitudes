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
        Swal.fire({ icon: 'error', title: 'Error', text: mensaje, confirmButtonColor: '#198754' });
    } else {
        alert('Error: ' + mensaje);
    }
}

// ==========================================
// 🎨 CONFIGURACIONES MODERNAS DE HIGHCHARTS
// ==========================================

// Paleta de colores moderna
const coloresModernos = {
    primario: '#667eea',
    secundario: '#764ba2',
    exito: '#11998e',
    exitoClaro: '#38ef7d',
    advertencia: '#f093fb',
    advertenciaOscuro: '#f5576c',
    info: '#4facfe',
    infoClaro: '#00f2fe',
    gris: '#6c757d',
    peligro: '#dc3545'
};

// Gradientes modernos
const gradientes = {
    primario: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#667eea'], [1, '#764ba2']]
    },
    exito: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#11998e'], [1, '#38ef7d']]
    },
    advertencia: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#f093fb'], [1, '#f5576c']]
    },
    info: {
        linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
        stops: [[0, '#4facfe'], [1, '#00f2fe']]
    }
};

// ==========================================
// Gráfico 1: Distribución por Estado (DONUT MODERNO)
// ==========================================
function crearGraficoEstado(datos) {
    const series = datos.map(item => ({
        name: item.estado_general,
        y: parseInt(item.total),
        color: obtenerColorPorEstado(item.estado_general)
    }));

    Highcharts.chart('chartEstado', {
        chart: {
            type: 'pie',
            backgroundColor: 'transparent',
            spacing: [20, 20, 20, 20]
        },
        title: {
            text: null
        },
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
                shadow: {
                    color: 'rgba(0, 0, 0, 0.1)',
                    offsetX: 0,
                    offsetY: 3,
                    width: 10
                },
                dataLabels: {
                    enabled: true,
                    distance: -30,
                    style: {
                        fontSize: '11px',
                        fontWeight: '600',
                        textOutline: 'none',
                        color: '#2c3e50'
                    },
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%'
                },
                startAngle: -90,
                endAngle: 270,
                center: ['50%', '55%'],
                size: '85%'
            }
        },
        series: [{
            name: 'Estado',
            colorByPoint: false,
            data: series,
            innerSize: '55%' // Donut en lugar de pie
        }]
    });
}

function obtenerColorPorEstado(estado) {
    const colores = {
        'PENDIENTE': '#6c757d',
        'PROCESADA': '#28a745',
        'PROCESADA_PARCIAL': '#ffc107',
        'RECHAZADA': '#dc3545',
        'APROBADA': '#17a2b8',
        'EN_PROCESO': '#ffc107'
    };
    return colores[estado] || '#667eea';
}

// ==========================================
// Gráfico 2: Solicitudes por Día (BARRAS MODERNAS)
// ==========================================
function crearGraficoDias(datos) {
    const categorias = datos.map(item => {
        const fecha = new Date(item.fecha);
        return fecha.toLocaleDateString('es-CL', { day: '2-digit', month: 'short' });
    });
    const valores = datos.map(item => parseInt(item.total));

    Highcharts.chart('chartDias', {
        chart: {
            type: 'column',
            backgroundColor: 'transparent',
            spacing: [20, 20, 20, 20]
        },
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
            labels: { style: { fontSize: '11px', fontWeight: '500', color: '#6c757d' } }
        },
        yAxis: {
            min: 0,
            title: { text: null },
            gridLineColor: '#f0f0f0',
            labels: { style: { fontSize: '11px', color: '#6c757d' } }
        },
        legend: { enabled: false },
        plotOptions: {
            column: {
                borderRadius: 8,
                borderWidth: 0,
                shadow: {
                    color: 'rgba(102, 126, 234, 0.3)',
                    offsetX: 0,
                    offsetY: 3,
                    width: 10
                },
                dataLabels: {
                    enabled: true,
                    style: {
                        fontSize: '11px',
                        fontWeight: '700',
                        color: '#667eea',
                        textOutline: 'none'
                    },
                    format: '{point.y}'
                },
                pointPadding: 0.3,
                groupPadding: 0.2
            }
        },
        series: [{
            name: 'Solicitudes Procesadas',
            data: valores,
            color: {
                linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
                stops: [[0, '#667eea'], [1, '#764ba2']]
            }
        }]
    });
}

// ==========================================
// Gráfico 3: Por Método de Compra (DONUT MODERNO)
// ==========================================
function crearGraficoMD(datos) {
    const series = datos.map(item => ({
        name: item.md || 'Sin MD',
        y: parseInt(item.total),
        color: obtenerColorPorMD(item.md)
    }));

    Highcharts.chart('chartMD', {
        chart: {
            type: 'pie',
            backgroundColor: 'transparent',
            spacing: [20, 20, 20, 20]
        },
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
                shadow: {
                    color: 'rgba(0, 0, 0, 0.1)',
                    offsetX: 0,
                    offsetY: 3,
                    width: 10
                },
                dataLabels: {
                    enabled: true,
                    distance: -30,
                    style: {
                        fontSize: '11px',
                        fontWeight: '600',
                        textOutline: 'none',
                        color: '#2c3e50'
                    },
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%'
                },
                startAngle: -90,
                endAngle: 270,
                center: ['50%', '55%'],
                size: '85%'
            }
        },
        series: [{
            name: 'Método',
            colorByPoint: false,
            data: series,
            innerSize: '55%'
        }]
    });
}

function obtenerColorPorMD(md) {
    const colores = {
        'TRANSFERENCIA': '#28a745',
        'CROSS_DOCKING': '#007bff',
        'COMPRA_LOCAL': '#ffc107'
    };
    return colores[md] || '#667eea';
}

// ==========================================
// Gráfico 4: Top Tiendas (BARRAS HORIZONTALES MODERNAS)
// ==========================================
// ==========================================
// Gráfico 4: Top Tiendas (BARRAS HORIZONTALES MODERNAS)
// ==========================================
function crearGraficoTiendas(datos) {
    // Asegurarnos de tener al menos 5 tiendas (rellenar con ceros si es necesario)
    while (datos.length < 5) {
        datos.push({ id_tienda: 0, total: 0 });
    }

    // Formatear como "Tienda XX"
    const categorias = datos.map(item => {
        if (item.id_tienda && item.id_tienda > 0) {
            return `Tienda ${item.id_tienda}`;
        } else {
            return 'Sin datos';
        }
    });
    const valores = datos.map(item => parseInt(item.total));

    Highcharts.chart('chartTiendas', {
        chart: {
            type: 'bar',
            backgroundColor: 'transparent',
            spacing: [20, 20, 20, 20]
        },
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
            labels: { 
                style: { 
                    fontSize: '11px', 
                    fontWeight: '500', 
                    color: '#6c757d',
                    textOverflow: 'ellipsis'
                } 
            }
        },
        yAxis: {
            min: 0,
            title: { text: null },
            gridLineColor: '#f0f0f0',
            labels: { style: { fontSize: '11px', color: '#6c757d' } },
            reversed: true
        },
        legend: { enabled: false },
        plotOptions: {
            bar: {
                borderRadius: 8,
                borderWidth: 0,
                shadow: {
                    color: 'rgba(245, 87, 108, 0.3)',
                    offsetX: 0,
                    offsetY: 3,
                    width: 10
                },
                dataLabels: {
                    enabled: true,
                    style: {
                        fontSize: '11px',
                        fontWeight: '700',
                        color: '#f5576c',
                        textOutline: 'none'
                    },
                    format: '{point.y}'
                },
                pointPadding: 0.3,
                groupPadding: 0.2
            }
        },
        series: [{
            name: 'Solicitudes',
            data: valores,
            color: {
                linearGradient: { x1: 0, y1: 0, x2: 1, y2: 0 },
                stops: [[0, '#f093fb'], [1, '#f5576c']]
            }
        }]
    });
}

function crearGraficoTopSKUs(datos) {
    console.log(" Datos Top SKUs:", datos);
    
    if (!datos || datos.length === 0) {
        console.error("❌ No hay datos");
        return;
    }

    // Preparar datos principales
    const dataPrincipal = datos.map((item, index) => {
        const desc = item.descripcion || 'Sin descripción';
        const label = desc.length > 40 ? desc.substring(0, 40) + '...' : desc;
        const medalla = index === 0 ? '🥇 ' : index === 1 ? '🥈 ' : index === 2 ? '🥉 ' : '';
        
        return {
            name: `${medalla}${item.sku}`,
            y: parseInt(item.total),
            drilldown: item.sku,
            color: obtenerColorPorIndex(index)
        };
    });

    // Preparar series de drilldown (tiendas por SKU)
    const seriesDrilldown = datos.map(item => {
        const tiendasData = (item.tiendas || []).map(t => ({
            name: `Tienda ${t.id_tienda}`,
            y: parseInt(t.total),
            color: '#667eea'
        }));
        
        return {
            id: item.sku,
            name: `Tiendas - ${item.sku}`,
            data: tiendasData,
            type: 'column'
        };
    });

    console.log(" Series drilldown:", seriesDrilldown);

    Highcharts.chart('chartTopSKUs', {
        chart: {
            type: 'column',
            height: 500,
            backgroundColor: 'transparent',
            events: {
                drilldown: function(e) {
                    console.log("🖱️ Drilldown clickeado:", e.point.drilldown);
                    if (!e.seriesOptions) {
                        const series = seriesDrilldown.find(s => s.id === e.point.drilldown);
                        if (series) {
                            this.addSingleSeriesAsDrilldown(e.point, series);
                            this.applyDrilldown();
                        }
                    }
                },
                drillup: function() {
                    console.log("⬆️ Drillup - volviendo");
                }
            }
        },
        title: { text: null },
        subtitle: {
            text: '👆 Haz clic en una barra para ver qué tiendas solicitan este SKU',
            align: 'left',
            style: { color: '#6c757d', fontSize: '13px', fontStyle: 'italic' }
        },
        xAxis: {
            type: 'category',
            labels: {
                rotation: -45,
                style: {
                    fontSize: '11px',
                    fontWeight: '600',
                    color: '#495057'
                }
            }
        },
        yAxis: {
            min: 0,
            title: {
                text: 'Solicitudes',
                style: { color: '#6c757d', fontSize: '12px' }
            }
        },
        tooltip: {
            headerFormat: '<b>{point.key}</b><br/>',
            pointFormat: '{point.y} solicitudes',
            backgroundColor: 'white',
            borderColor: '#ddd',
            borderRadius: 8,
            shadow: true
        },
        plotOptions: {
            column: {
                pointPadding: 0.2,
                borderWidth: 0,
                borderRadius: 8,
                dataLabels: {
                    enabled: true,
                    format: '{point.y}',
                    style: {
                        fontSize: '14px',
                        fontWeight: 'bold',
                        color: '#2c3e50'
                    }
                }
            }
        },
        series: [{
            name: 'SKUs',
            colorByPoint: true,
            data: dataPrincipal
        }],
        drilldown: {
            series: seriesDrilldown,
            activeDataLabelStyle: {
                color: '#667eea',
                textDecoration: 'none',
                fontWeight: 'bold'
            },
            drillUpButton: {
                relativeTo: 'spacingBox',
                position: { y: 10, x: 0 },
                theme: {
                    fill: '#667eea',
                    stroke: 'none',
                    r: 20,
                    padding: 10,
                    style: {
                        color: 'white',
                        fontWeight: 'bold',
                        fontSize: '13px'
                    },
                    states: {
                        hover: { fill: '#764ba2' }
                    }
                },
                text: '← Volver al Top SKUs'
            }
        },
        credits: { enabled: false }
    });
}

function obtenerColorPorIndex(index) {
    const colores = ['#667eea', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#a8edea', '#ff9a9e', '#ffecd2', '#a1c4fd', '#d4fc79'];
    return colores[index % colores.length];
}
