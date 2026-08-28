document.addEventListener('DOMContentLoaded', () => {
    cargarDashboardTienda();
});

async function cargarDashboardTienda() {
    try {
        const response = await fetch('../api/obtener_kpis_tienda.php');
        const data = await response.json();
        
        if (!data.success) {
            console.error('Error:', data.message);
            return;
        }

        // Actualizar KPIs
        document.getElementById('kpiPendientes').textContent = data.kpis.pendientes;
        document.getElementById('kpiAprobadas').textContent = data.kpis.aprobadas;
        document.getElementById('kpiProcesadas').textContent = data.kpis.procesadas;
        document.getElementById('kpiRechazadas').textContent = data.kpis.rechazadas;

        // Gráficos
        if (data.graficos.porEstado?.length > 0) crearGraficoEstado(data.graficos.porEstado);
        if (data.graficos.porMes?.length > 0) crearGraficoMensual(data.graficos.porMes);

    } catch (error) {
        console.error('Error cargando dashboard:', error);
    }
}

function crearGraficoEstado(datos) {
    Highcharts.chart('chartEstado', {
        chart: { type: 'pie', backgroundColor: 'transparent' },
        title: { text: null },
        tooltip: { pointFormat: '{series.name}: <b>{point.y}</b> ({point.percentage:.1f}%)' },
        plotOptions: {
            pie: {
                allowPointSelect: true,
                cursor: 'pointer',
                dataLabels: { enabled: true, format: '<b>{point.name}</b>: {point.percentage:.1f} %' }
            }
        },
        series: [{
            name: 'Estado',
            colorByPoint: true,
            data: datos.map(item => ({ name: item.estado_general, y: parseInt(item.total) })),
            colors: ['#6c757d', '#0dcaf0', '#198754', '#dc3545']
        }]
    });
}

function crearGraficoMensual(datos) {
    Highcharts.chart('chartMensual', {
        chart: { type: 'column', backgroundColor: 'transparent' },
        title: { text: null },
        xAxis: {
            categories: datos.map(item => item.mes),
            title: { text: null }
        },
        yAxis: {
            min: 0,
            title: { text: 'Solicitudes' }
        },
        plotOptions: {
            column: {
                dataLabels: { enabled: true },
                color: '#0d6efd'
            }
        },
        series: [{
            name: 'Solicitudes',
            data: datos.map(item => parseInt(item.total))
        }]
    });
}