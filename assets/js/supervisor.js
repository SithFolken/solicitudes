let chartMD = null;
let chartFamilias = null;
let chartMotivos = null;

document.addEventListener('DOMContentLoaded', () => {
    // Establecer fechas por defecto (últimos 30 días)
    const hoy = new Date();
    const hace30Dias = new Date();
    hace30Dias.setDate(hoy.getDate() - 30);
    
    document.getElementById('fechaFin').valueAsDate = hoy;
    document.getElementById('fechaInicio').valueAsDate = hace30Dias;
    
    cargarKPIs();
});

async function cargarKPIs() {
    const fechaInicio = document.getElementById('fechaInicio').value;
    const fechaFin = document.getElementById('fechaFin').value;
    
    console.log('📅 Cargando KPIs desde:', fechaInicio, 'hasta:', fechaFin);
    
    try {
        const response = await fetch(`../api/obtener_kpis_supervisor.php?fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}`);
        const data = await response.json();
        
        console.log('📊 Datos recibidos del servidor:', data);
        
        if (!data.success) {
            console.error('❌ Error cargando KPIs:', data.message);
            Swal.fire('Error', data.message, 'error');
            return;
        }
        
        // 1. Gráficos y tablas originales
        actualizarTarjetas(data.kpis_generales, data.solicitudes_retrasadas);
        renderizarGraficoMD(data.distribucion_md);
        renderizarGraficoFamilias(data.top_familias);
        renderizarGraficoMotivos(data.top_motivos);
        renderizarTablaAnalistas(data.desempeno_analistas);
        renderizarTablaTiendas(data.top_tiendas);
        renderizarTablaFamilias(data.top_familias);
        renderizarTablaSKUsProblema(data.skus_problema);
        
        // ✅ 2. NUEVOS GRÁFICOS - MOTIVOS DE RECHAZO GENERALIZADOS
        console.log('📈 Intentando renderizar gráficos de motivos...');
        
        if (data.motivos_categorias && data.motivos_categorias.length > 0) {
            console.log('✅ Motivos categorías:', data.motivos_categorias);
            renderizarMotivosGeneral(data.motivos_categorias);
            renderizarMotivosDistribucion(data.motivos_categorias);
        } else {
            console.warn('⚠️ No hay datos de motivos_categorias');
            document.getElementById('chartMotivosGeneral').innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No hay rechazos en este período</p></div>';
            document.getElementById('chartMotivosDistribucion').innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No hay rechazos en este período</p></div>';
        }
        
        if (data.motivos_por_tienda && data.motivos_por_tienda.length > 0) {
            console.log('✅ Motivos por tienda:', data.motivos_por_tienda);
            renderizarMotivosTienda(data.motivos_por_tienda);
        } else {
            console.warn('⚠️ No hay datos de motivos_por_tienda');
            document.getElementById('chartMotivosTienda').innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No hay rechazos por tienda en este período</p></div>';
        }
        
        if (data.motivos_por_familia && data.motivos_por_familia.length > 0) {
            console.log('✅ Motivos por familia:', data.motivos_por_familia);
            renderizarMotivosFamilia(data.motivos_por_familia);
        } else {
            console.warn('⚠️ No hay datos de motivos_por_familia');
            document.getElementById('chartMotivosFamilia').innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No hay rechazos por familia en este período</p></div>';
        }
        
    } catch (error) {
        console.error('❌ Error de red:', error);
        Swal.fire('Error', 'No se pudieron cargar los datos: ' + error.message, 'error');
    }
}

function actualizarTarjetas(kpis, retrasadas) {
    document.getElementById('kpiTotal').textContent = kpis.total || 0;
    document.getElementById('kpiPendientes').textContent = kpis.pendientes || 0;
    document.getElementById('kpiEnProceso').textContent = kpis.en_proceso || 0;
    document.getElementById('kpiProcesadas').textContent = kpis.procesadas || 0;
    document.getElementById('kpiRechazadas').textContent = kpis.rechazadas || 0;
    document.getElementById('kpiTasaRechazo').textContent = `${kpis.tasa_rechazo}% del total`;
    
    document.getElementById('kpiRetrasadas').textContent = retrasadas.total_retrasadas || 0;
    document.getElementById('kpiPromedioRetraso').textContent = `${Math.round(retrasadas.promedio_horas_retraso || 0)}h prom.`;
}

// ==========================================
// GRÁFICO 1: DISTRIBUCIÓN POR MÉTODO DE DESPACHO (Highcharts Pie)
// ==========================================
function renderizarGraficoMD(datos) {
    if (chartMD) {
        chartMD.destroy();
    }
    if (!datos || datos.length === 0) {
        document.getElementById('chartMD').innerHTML = '<div class="text-center py-5 text-muted">No hay datos</div>';
        return;
    }
    
    const labels = datos.map(d => d.md_grupo);
    const valores = datos.map(d => parseInt(d.cantidad));
    
    chartMD = Highcharts.chart('chartMD', {
        chart: {
            type: 'pie',
            backgroundColor: 'transparent'
        },
        title: {
            text: null
        },
        credits: {
            enabled: false
        },
        tooltip: {
            pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b> ({point.y})',
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            shadow: true
        },
        plotOptions: {
            pie: {
                allowPointSelect: true,
                cursor: 'pointer',
                dataLabels: {
                    enabled: true,
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%',
                    style: {
                        fontSize: '11px',
                        fontWeight: 'bold',
                        textOutline: 'none'
                    }
                },
                showInLegend: true,
                colors: ['#0d6efd', '#198754', '#fd7e14', '#6c757d', '#dc3545']
            }
        },
        series: [{
            name: 'Solicitudes',
            data: datos.map(d => ({
                name: d.md_grupo,
                y: parseInt(d.cantidad)
            }))
        }]
    });
}

// ==========================================
// GRÁFICO 2: TOP 5 FAMILIAS (Highcharts Column)
// ==========================================
function renderizarGraficoFamilias(datos) {
    if (chartFamilias) {
        chartFamilias.destroy();
    }
    if (!datos || datos.length === 0) {
        document.getElementById('chartFamilias').innerHTML = '<div class="text-center py-5 text-muted">No hay datos</div>';
        return;
    }
    
    const labels = datos.slice(0, 5).map(d => d.descripcion_familia || `Familia ${d.id_familia}`);
    const valores = datos.slice(0, 5).map(d => parseInt(d.total_solicitudes));
    
    chartFamilias = Highcharts.chart('chartFamilias', {
        chart: {
            type: 'column',
            backgroundColor: 'transparent'
        },
        title: {
            text: null
        },
        credits: {
            enabled: false
        },
        xAxis: {
            categories: labels,
            labels: {
                rotation: -45,
                style: {
                    fontSize: '10px'
                }
            }
        },
        yAxis: {
            min: 0,
            title: {
                text: 'Solicitudes'
            }
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            shadow: true,
            formatter: function() {
                return `<b>${this.x}</b><br>Solicitudes: ${this.y}`;
            }
        },
        plotOptions: {
            column: {
                pointPadding: 0.2,
                borderWidth: 0,
                colors: ['#0d6efd']
            }
        },
        series: [{
            name: 'Solicitudes',
            data: valores
        }]
    });
}

// ==========================================
// GRÁFICO 3: MOTIVOS MÁS COMUNES (Highcharts Donut)
// ==========================================
function renderizarGraficoMotivos(datos) {
    if (chartMotivos) {
        chartMotivos.destroy();
    }
    if (!datos || datos.length === 0) {
        document.getElementById('chartMotivos').innerHTML = '<div class="text-center py-5 text-muted">No hay datos</div>';
        return;
    }
    
    const chartData = datos.slice(0, 5).map(d => ({
        name: d.motivo_carga,
        y: parseInt(d.cantidad),
        porcentaje: parseFloat(d.porcentaje)
    }));
    
    chartMotivos = Highcharts.chart('chartMotivos', {
        chart: {
            type: 'pie',
            backgroundColor: 'transparent'
        },
        title: {
            text: null
        },
        credits: {
            enabled: false
        },
        tooltip: {
            pointFormat: '{series.name}: <b>{point.y}</b> ({point.porcentaje}%)',
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            shadow: true
        },
        plotOptions: {
            pie: {
                innerSize: '50%', // Efecto donut
                depth: 45,
                allowPointSelect: true,
                cursor: 'pointer',
                dataLabels: {
                    enabled: true,
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%',
                    style: {
                        fontSize: '10px',
                        fontWeight: 'bold',
                        textOutline: 'none'
                    }
                },
                showInLegend: true,
                colors: ['#0d6efd', '#198754', '#fd7e14', '#dc3545', '#6c757d', '#20c997', '#e83e8c']
            }
        },
        series: [{
            name: 'Cantidad',
            data: chartData
        }]
    });
}

// ==========================================
// TABLA: DESEMPEÑO POR ANALISTA (9 COLUMNAS EXACTAS)
// ==========================================
function renderizarTablaAnalistas(datos) {
    const tbody = document.getElementById('tbodyAnalistas');
    tbody.innerHTML = '';
    
    if (!datos || datos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No hay datos para este período</td></tr>';
        return;
    }
    
    datos.forEach(analista => {
        // 1. Extraer y asegurar que sean números
        const total = parseInt(analista.total_procesadas) || 0;
        const aprobadas = parseInt(analista.aprobadas) || 0;
        const rechazadas = parseInt(analista.rechazadas) || 0;
        const pendientes = parseInt(analista.pendientes) || 0;
        const enProceso = parseInt(analista.en_proceso) || 0;       // ✅ COLUMNA 6
        const conRetraso = parseInt(analista.con_retraso) || 0;     // ✅ COLUMNA 7
        const tasaAprobacion = total > 0 ? ((aprobadas / total) * 100).toFixed(1) : 0;
        const tiempoPromedio = parseFloat(analista.tiempo_promedio_horas) || 0;
        
        // 2. Definir colores de los badges
        let badgeTiempo = 'bg-success';
        if (tiempoPromedio > 24) badgeTiempo = 'bg-danger';
        else if (tiempoPromedio > 12) badgeTiempo = 'bg-warning text-dark';
        
        let badgePendientes = 'bg-success';
        if (pendientes > 10) badgePendientes = 'bg-danger';
        else if (pendientes > 5) badgePendientes = 'bg-warning text-dark';
        
        let badgeEnProceso = 'bg-info'; // Azul para En Proceso
        if (enProceso > 5) badgeEnProceso = 'bg-warning text-dark';
        
        let badgeRetraso = 'bg-success';
        if (conRetraso > 5) badgeRetraso = 'bg-danger';
        else if (conRetraso > 2) badgeRetraso = 'bg-warning text-dark';
        
        // 3. Crear la fila con EXACTAMENTE 9 <td>
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="fw-medium">${analista.nombre_analista}</td>                         <!-- 1 -->
            <td class="text-center fw-bold">${total}</td>                                 <!-- 2 -->
            <td class="text-center text-success">${aprobadas}</td>                        <!-- 3 -->
            <td class="text-center text-danger">${rechazadas}</td>                        <!-- 4 -->
            <td class="text-center"><span class="badge ${badgePendientes} rounded-pill">${pendientes}</span></td> <!-- 5 -->
            <td class="text-center"><span class="badge ${badgeEnProceso} rounded-pill">${enProceso}</span></td>   <!-- 6 ✅ -->
            <td class="text-center"><span class="badge ${badgeRetraso} rounded-pill">${conRetraso}</span></td>    <!-- 7 -->
            <td class="text-center"><span class="badge bg-primary rounded-pill">${tasaAprobacion}%</span></td>    <!-- 8 -->
            <td class="text-center"><span class="badge ${badgeTiempo} rounded-pill">${tiempoPromedio}h</span></td><!-- 9 -->
        `;
        tbody.appendChild(tr);
    });
}
// ==========================================
// TABLA: TOP 10 TIENDAS
// ==========================================
function renderizarTablaTiendas(datos) {
    const tbody = document.getElementById('tbodyTiendas');
    tbody.innerHTML = '';
    
    if (!datos || datos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No hay datos para este período</td></tr>';
        return;
    }
    
    datos.forEach(tienda => {
        const total = parseInt(tienda.total_solicitudes) || 0;
        const rechazadas = parseInt(tienda.rechazadas) || 0;
        const tasa = parseFloat(tienda.tasa_rechazo) || 0;
        
        let badgeClass = 'bg-success';
        let badgeText = 'Óptimo';
        
        if (tasa > 30) { 
            badgeClass = 'bg-danger'; 
            badgeText = 'Crítico'; 
        } else if (tasa > 15) { 
            badgeClass = 'bg-warning text-dark'; 
            badgeText = 'Revisar'; 
        }
        
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="fw-medium">${tienda.nombre_tienda || 'Tienda Desconocida'}</td>
            <td class="text-center fw-bold">${total}</td>
            <td class="text-center text-danger">${rechazadas}</td>
            <td class="text-center fw-bold">${tasa}%</td>
            <td class="text-center"><span class="badge ${badgeClass} rounded-pill">${badgeText}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

// ==========================================
// TABLA: TOP 10 FAMILIAS
// ==========================================
function renderizarTablaFamilias(datos) {
    const tbody = document.getElementById('tbodyFamilias');
    tbody.innerHTML = '';
    
    if (!datos || datos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No hay datos para este período</td></tr>';
        return;
    }
    
    datos.forEach(familia => {
        const total = parseInt(familia.total_solicitudes) || 0;
        const rechazadas = parseInt(familia.rechazadas) || 0;
        const tasa = parseFloat(familia.tasa_rechazo) || 0;
        
        let badgeClass = 'bg-success';
        if (tasa > 30) badgeClass = 'bg-danger';
        else if (tasa > 15) badgeClass = 'bg-warning text-dark';
        
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="fw-medium">${familia.descripcion_familia || `Familia ${familia.id_familia}`}</td>
            <td class="text-center fw-bold">${total}</td>
            <td class="text-center text-danger">${rechazadas}</td>
            <td class="text-center"><span class="badge ${badgeClass} rounded-pill">${tasa}%</span></td>
        `;
        tbody.appendChild(tr);
    });
}

// ==========================================
// TABLA: SKUs CON MÁS RECHAZOS
// ==========================================
function renderizarTablaSKUsProblema(datos) {
    const tbody = document.getElementById('tbodySKUsProblema');
    tbody.innerHTML = '';
    
    if (!datos || datos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No hay SKUs con rechazos en este período</td></tr>';
        return;
    }
    
    datos.forEach(sku => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><code class="bg-light px-2 py-1 rounded">${sku.sku}</code></td>
            <td class="text-truncate" style="max-width: 300px;" title="${sku.descripcion_producto || ''}">${sku.descripcion_producto || '-'}</td>
            <td class="text-center"><span class="badge bg-danger rounded-pill">${sku.veces_rechazado}</span></td>
            <td><small class="text-muted">${sku.motivos_rechazo || 'Sin motivo registrado'}</small></td>
        `;
        tbody.appendChild(tr);
    });
}

// ==========================================
// GRÁFICO 4: MOTIVOS DE RECHAZO GENERALES (Barras Horizontales)
// ==========================================
function renderizarMotivosGeneral(datos) {
    if (!datos || datos.length === 0) {
        document.getElementById('chartMotivosGeneral').innerHTML = '<div class="text-center py-4 text-muted">No hay datos de rechazos en este período</div>';
        return;
    }
    
    const categorias = datos.map(d => d.categoria_motivo);
    const cantidades = datos.map(d => parseInt(d.cantidad));
    
    Highcharts.chart('chartMotivosGeneral', {
        chart: {
            type: 'bar',
            backgroundColor: 'transparent',
            height: 350
        },
        title: { text: null },
        credits: { enabled: false },
        xAxis: {
            categories: categorias,
            labels: {
                style: { fontSize: '11px', fontWeight: 'bold' }
            }
        },
        yAxis: {
            min: 0,
            title: { text: 'Cantidad de Rechazos' }
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            formatter: function() {
                return `<b>${this.x}</b><br>Rechazos: <b>${this.y}</b>`;
            }
        },
        plotOptions: {
            bar: {
                dataLabels: {
                    enabled: true,
                    format: '{point.y}',
                    style: { fontWeight: 'bold' }
                }
            }
        },
        colors: ['#dc3545', '#fd7e14', '#ffc107', '#6c757d', '#20c997', '#17a2b8', '#6f42c1'],
        series: [{
            name: 'Rechazos',
            data: cantidades
        }]
    });
}

// ==========================================
// GRÁFICO 5: DISTRIBUCIÓN PORCENTUAL DE MOTIVOS (Donut)
// ==========================================
function renderizarMotivosDistribucion(datos) {
    if (!datos || datos.length === 0) {
        document.getElementById('chartMotivosDistribucion').innerHTML = '<div class="text-center py-4 text-muted">No hay datos de rechazos en este período</div>';
        return;
    }
    
    const chartData = datos.map(d => ({
        name: d.categoria_motivo,
        y: parseInt(d.cantidad)
    }));
    
    Highcharts.chart('chartMotivosDistribucion', {
        chart: {
            type: 'pie',
            backgroundColor: 'transparent',
            height: 350
        },
        title: { text: null },
        credits: { enabled: false },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            pointFormat: '{series.name}: <b>{point.y}</b> ({point.percentage:.1f}%)'
        },
        plotOptions: {
            pie: {
                innerSize: '50%',
                allowPointSelect: true,
                cursor: 'pointer',
                dataLabels: {
                    enabled: true,
                    format: '<b>{point.name}</b><br>{point.percentage:.1f}%',
                    style: {
                        fontSize: '10px',
                        fontWeight: 'bold',
                        textOutline: 'none'
                    }
                },
                showInLegend: true
            }
        },
        colors: ['#dc3545', '#fd7e14', '#ffc107', '#6c757d', '#20c997', '#17a2b8', '#6f42c1'],
        series: [{
            name: 'Rechazos',
            data: chartData
        }]
    });
}

// ==========================================
// GRÁFICO 6: MOTIVOS POR TIENDA (Barras Apiladas)
// ==========================================
function renderizarMotivosTienda(datos) {
    if (!datos || datos.length === 0) {
        document.getElementById('chartMotivosTienda').innerHTML = '<div class="text-center py-4 text-muted">No hay datos de rechazos por tienda</div>';
        return;
    }
    
    // Agrupar datos por tienda
    const tiendasMap = {};
    datos.forEach(d => {
        if (!tiendasMap[d.nombre_tienda]) {
            tiendasMap[d.nombre_tienda] = {};
        }
        tiendasMap[d.nombre_tienda][d.categoria_motivo] = parseInt(d.cantidad);
    });
    
    // Obtener top 5 tiendas por total de rechazos
    const tiendasConTotal = Object.keys(tiendasMap).map(tienda => ({
        nombre: tienda,
        total: Object.values(tiendasMap[tienda]).reduce((a, b) => a + b, 0)
    }));
    tiendasConTotal.sort((a, b) => b.total - a.total);
    const topTiendas = tiendasConTotal.slice(0, 5).map(t => t.nombre);
    
    // Obtener todas las categorías de motivos únicas
    const todasCategorias = [...new Set(datos.map(d => d.categoria_motivo))];
    
    // Crear series para cada categoría
    const series = todasCategorias.map(categoria => ({
        name: categoria,
        data: topTiendas.map(tienda => tiendasMap[tienda][categoria] || 0)
    }));
    
    Highcharts.chart('chartMotivosTienda', {
        chart: {
            type: 'bar',
            backgroundColor: 'transparent',
            height: 400
        },
        title: { text: null },
        credits: { enabled: false },
        xAxis: {
            categories: topTiendas,
            labels: {
                rotation: -45,
                style: { fontSize: '11px' }
            }
        },
        yAxis: {
            min: 0,
            title: { text: 'Cantidad de Rechazos' },
            stackLabels: {
                enabled: true,
                style: { fontWeight: 'bold', color: '#000' }
            }
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            formatter: function() {
                return `<b>${this.series.name}</b><br>${this.x}: <b>${this.y}</b>`;
            }
        },
        plotOptions: {
            series: {
                stacking: 'normal',
                pointPadding: 0.1,
                borderWidth: 0,
                dataLabels: {
                    enabled: true,
                    format: '{point.y}',
                    style: { fontSize: '10px' }
                }
            }
        },
        colors: ['#dc3545', '#fd7e14', '#ffc107', '#6c757d', '#20c997', '#17a2b8'],
        series: series
    });
}

// ==========================================
// GRÁFICO 7: MOTIVOS POR FAMILIA (Barras Apiladas)
// ==========================================
function renderizarMotivosFamilia(datos) {
    if (!datos || datos.length === 0) {
        document.getElementById('chartMotivosFamilia').innerHTML = '<div class="text-center py-4 text-muted">No hay datos de rechazos por familia</div>';
        return;
    }
    
    // Agrupar datos por familia
    const familiasMap = {};
    datos.forEach(d => {
        const familiaLabel = `Familia ${d.id_familia}`;
        if (!familiasMap[familiaLabel]) {
            familiasMap[familiaLabel] = {};
        }
        familiasMap[familiaLabel][d.categoria_motivo] = parseInt(d.cantidad);
    });
    
    // Obtener top 8 familias por total de rechazos
    const familiasConTotal = Object.keys(familiasMap).map(familia => ({
        nombre: familia,
        total: Object.values(familiasMap[familia]).reduce((a, b) => a + b, 0)
    }));
    familiasConTotal.sort((a, b) => b.total - a.total);
    const topFamilias = familiasConTotal.slice(0, 8).map(f => f.nombre);
    
    // Obtener todas las categorías de motivos únicas
    const todasCategorias = [...new Set(datos.map(d => d.categoria_motivo))];
    
    // Crear series para cada categoría
    const series = todasCategorias.map(categoria => ({
        name: categoria,
        data: topFamilias.map(familia => familiasMap[familia][categoria] || 0)
    }));
    
    Highcharts.chart('chartMotivosFamilia', {
        chart: {
            type: 'bar',
            backgroundColor: 'transparent',
            height: 400
        },
        title: { text: null },
        credits: { enabled: false },
        xAxis: {
            categories: topFamilias,
            labels: {
                rotation: -45,
                style: { fontSize: '11px' }
            }
        },
        yAxis: {
            min: 0,
            title: { text: 'Cantidad de Rechazos' },
            stackLabels: {
                enabled: true,
                style: { fontWeight: 'bold', color: '#000' }
            }
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderColor: '#ddd',
            borderRadius: 8,
            formatter: function() {
                return `<b>${this.series.name}</b><br>${this.x}: <b>${this.y}</b>`;
            }
        },
        plotOptions: {
            series: {
                stacking: 'normal',
                pointPadding: 0.1,
                borderWidth: 0,
                dataLabels: {
                    enabled: true,
                    format: '{point.y}',
                    style: { fontSize: '10px' }
                }
            }
        },
        colors: ['#dc3545', '#fd7e14', '#ffc107', '#6c757d', '#20c997', '#17a2b8'],
        series: series
    });
}