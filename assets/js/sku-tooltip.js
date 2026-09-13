/**
 * SKU Image Tooltip - Versión Corregida
 */
(function() {
    'use strict';

    // Crear tooltip al inicio
    let tooltip = null;
    
    function createTooltip() {
        if (tooltip) return tooltip;
        
        tooltip = document.createElement('div');
        tooltip.id = 'skuImageTooltip';
        tooltip.style.cssText = `
            position: fixed !important;
            display: none !important;
            background: white !important;
            border-radius: 8px !important;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3) !important;
            padding: 8px !important;
            z-index: 999999 !important;
            max-width: 220px !important;
            pointer-events: none !important;
            border: 2px solid #0056b3 !important;
        `;
        
        document.body.appendChild(tooltip);
        return tooltip;
    }

    function showTooltip(e, sku) {
        const tooltip = createTooltip();
        const imgUrl = `https://media.falabella.com/sodimacCL/${sku}`;
        
        tooltip.innerHTML = `
            <img src="${imgUrl}" 
                 alt="SKU ${sku}" 
                 style="width:100%; max-width:200px; max-height:200px; height:auto; border-radius:4px; display:block;"
                 onerror="this.parentElement.style.display='none'">
            <div style="text-align:center; margin-top:6px; font-size:11px; font-weight:700; color:#0056b3;">SKU: ${sku}</div>
        `;
        
        tooltip.style.display = 'block';
        updateTooltipPosition(e);
    }

    function updateTooltipPosition(e) {
        const tooltip = document.getElementById('skuImageTooltip');
        if (!tooltip) return;
        
        let x = e.clientX + 15;
        let y = e.clientY + 15;
        
        const rect = tooltip.getBoundingClientRect();
        if (x + rect.width > window.innerWidth) x = e.clientX - rect.width - 15;
        if (y + rect.height > window.innerHeight) y = e.clientY - rect.height - 15;
        
        tooltip.style.left = Math.max(10, x) + 'px';
        tooltip.style.top = Math.max(10, y) + 'px';
    }

    function hideTooltip() {
        const tooltip = document.getElementById('skuImageTooltip');
        if (tooltip) tooltip.style.display = 'none';
    }

    function initTooltips() {
        const tables = document.querySelectorAll('table');
        tables.forEach(table => {
            const cells = table.querySelectorAll('td');
            cells.forEach(cell => {
                const text = cell.textContent.trim();
                if (/^\d{6,10}$/.test(text) && !cell.dataset.skuInit) {
                    cell.classList.add('sku-hover-cell');
                    cell.dataset.skuInit = 'true';
                    cell.style.cursor = 'help';
                    
                    cell.addEventListener('mouseenter', (e) => showTooltip(e, text));
                    cell.addEventListener('mousemove', updateTooltipPosition);
                    cell.addEventListener('mouseleave', hideTooltip);
                }
            });
        });
    }

    // Inicializar cuando el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTooltips);
    } else {
        initTooltips();
    }

    // Observar cambios dinámicos
    const observer = new MutationObserver(() => setTimeout(initTooltips, 300));
    observer.observe(document.body, { childList: true, subtree: true });
})();