// ============================================
// Nimvo - Main JavaScript
// ============================================

// Clock
function updateClock() {
    const now = new Date();
    const dateEl = document.getElementById('currentDate');
    const timeEl = document.getElementById('currentTime');
    if (dateEl) dateEl.textContent = now.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short' });
    if (timeEl) timeEl.textContent = now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
setInterval(updateClock, 1000);
updateClock();

// Sidebar toggle
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');

    document.getElementById('sidebarToggle')?.addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });

    document.getElementById('topbarToggle')?.addEventListener('click', (e) => {
        e.stopPropagation();
        sidebar.classList.toggle('mobile-open');
    });

    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 &&
            !e.target.closest('#sidebar') &&
            !e.target.closest('#topbarToggle')) {
            sidebar.classList.remove('mobile-open');
        }
    });
});

// ── Toast ─────────────────────────────────────────────────────────────────
function showToast(message, type = 'info') {
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info} toast-icon"></i><span class="toast-text">${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(20px)'; setTimeout(() => toast.remove(), 300); }, 3500);
}

// ── Modal system ──────────────────────────────────────────────────────────
function openModal(id) {
    document.getElementById(id)?.classList.add('open');
    document.getElementById('modalBackdrop')?.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
    const anyOpen = document.querySelectorAll('.modal.open').length > 0;
    if (!anyOpen) { document.getElementById('modalBackdrop')?.classList.remove('open'); document.body.style.overflow = ''; }
}
function closeAllModals() {
    document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
    document.getElementById('modalBackdrop')?.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllModals(); });

// ── Modal de Confirmação próprio ──────────────────────────────────────────
(function injectConfirmModal() {
    if (document.getElementById('_confirmModal')) return;
    const div = document.createElement('div');
    div.innerHTML = `
        <div class="modal modal-sm" id="_confirmModal" style="z-index:9999">
            <div class="modal-box" style="max-width:420px">
                <div class="modal-body" style="padding:32px 28px 20px;text-align:center">
                    <div id="_confirmIcon" style="font-size:44px;margin-bottom:14px"></div>
                    <div id="_confirmTitle" style="font-size:17px;font-weight:800;color:var(--text-primary);margin-bottom:8px"></div>
                    <div id="_confirmMsg"   style="font-size:14px;color:var(--text-secondary);line-height:1.5"></div>
                </div>
                <div style="display:flex;gap:10px;justify-content:center;padding:0 28px 28px">
                    <button id="_confirmCancel" class="btn btn-outline" style="min-width:120px;height:42px"></button>
                    <button id="_confirmOk"     class="btn btn-danger"  style="min-width:120px;height:42px"></button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(div.firstElementChild);
})();

function showConfirm({ title = 'Confirmar', message = '', confirmText = 'Confirmar', cancelText = 'Cancelar', type = 'danger', icon = null } = {}) {
    return new Promise(resolve => {
        const emojis = { danger: '🗑️', warning: '⚠️', info: 'ℹ️', success: '✅' };
        document.getElementById('_confirmIcon').textContent = icon || emojis[type] || '⚠️';
        document.getElementById('_confirmTitle').textContent = title;
        document.getElementById('_confirmMsg').innerHTML = message;

        const okBtn = document.getElementById('_confirmOk');
        const canBtn = document.getElementById('_confirmCancel');
        okBtn.textContent = confirmText;
        canBtn.textContent = cancelText;
        okBtn.className = `btn btn-${type}`;
        okBtn.style.minWidth = canBtn.style.minWidth = '120px';
        okBtn.style.height = canBtn.style.height = '42px';

        // Troca nós para limpar listeners
        const newOk = okBtn.cloneNode(true);
        const newCan = canBtn.cloneNode(true);
        okBtn.replaceWith(newOk);
        canBtn.replaceWith(newCan);

        const close = r => {
            document.getElementById('_confirmModal').classList.remove('open');
            document.getElementById('modalBackdrop')?.classList.remove('open');
            document.body.style.overflow = '';
            resolve(r);
        };
        document.getElementById('_confirmOk').addEventListener('click', () => close(true));
        document.getElementById('_confirmCancel').addEventListener('click', () => close(false));

        document.getElementById('_confirmModal').classList.add('open');
        document.getElementById('modalBackdrop')?.classList.add('open');
        document.body.style.overflow = 'hidden';
    });
}

// ── API helper ────────────────────────────────────────────────────────────
async function apiCall(url, data = null, method = null) {
    try {
        const opts = {
            method: method || (data ? 'POST' : 'GET'),
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (data) opts.body = JSON.stringify(data);
        const res = await fetch(url, opts);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Resposta não-JSON:', text.substring(0, 600));
            return { success: false, message: 'Erro no servidor. Veja o console (F12) para detalhes.' };
        }
    } catch (e) {
        return { success: false, message: 'Erro de comunicação: ' + e.message };
    }
}

// Format currency
function formatMoney(v) {
    return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

// confirmAction — substitui confirm() nativo em qualquer página
async function confirmAction(msg, cb) {
    const ok = await showConfirm({ title: 'Confirmar', message: msg, type: 'warning', icon: '⚠️', confirmText: 'Sim, continuar' });
    if (ok) cb();
}

// ── Delete record ─────────────────────────────────────────────────────────
async function deleteRecord(path, rowId, label) {
    const ok = await showConfirm({
        title: `Excluir ${label}?`,
        message: 'Esta ação <strong>não pode ser desfeita</strong>.',
        type: 'danger',
        icon: '🗑️',
        confirmText: 'Excluir',
    });
    if (!ok) return;

    // Garante BASE_PATH na URL
    const base = (typeof BASE_PATH !== 'undefined') ? BASE_PATH : '';
    const url = base + '/' + path.replace(/^\//, '');

    const res = await apiCall(url, null, 'DELETE');
    if (res.success) {
        document.getElementById(rowId)?.remove();
        showToast(res.message || 'Excluído com sucesso!', 'success');
    } else {
        showToast(res.message || 'Erro ao excluir.', 'error');
    }
}

// Search/filter table
function filterTable(inputId, tableId, col = null) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', () => {
        const term = input.value.toLowerCase();
        document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
            const text = col !== null ? row.cells[col]?.textContent?.toLowerCase() : row.textContent.toLowerCase();
            row.style.display = text?.includes(term) ? '' : 'none';
        });
    });
}

// Mask: money input
document.addEventListener('input', function (e) {
    if (e.target.classList.contains('money-input')) {
        let v = e.target.value.replace(/\D/g, '');
        v = (parseInt(v) / 100).toFixed(2);
        if (!isNaN(v)) e.target.value = v;
    }
});

// Auto-calculate profit margin
function calcMargin(costId, priceId, marginId, marginPctId) {
    const cost = parseFloat(document.getElementById(costId)?.value) || 0;
    const price = parseFloat(document.getElementById(priceId)?.value) || 0;
    const profit = price - cost;
    const pct = cost > 0 ? (profit / cost * 100) : 0;
    if (marginId) {
        const el = document.getElementById(marginId);
        if (el) { el.textContent = formatMoney(profit); el.className = profit >= 0 ? 'profit-positive' : 'profit-negative'; }
    }
    if (marginPctId) {
        const el = document.getElementById(marginPctId);
        if (el) { el.textContent = pct.toFixed(1) + '%'; el.className = pct >= 0 ? 'profit-positive' : 'profit-negative'; }
    }
}