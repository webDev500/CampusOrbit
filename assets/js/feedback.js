/* ============================================================
   CampusOrbit — feedback.js
   Lightweight feedback UI:
     - CampusOrbit.toast({success, message}) -> top-right toast, auto-dismiss 3s
     - CampusOrbit.modal({success, message}) -> centered modal,  auto-dismiss 5s
     - CampusOrbit.setSilentClick(button)    -> suppress the "Submitting…" auto-ack
     - On page load, server-rendered flash banners are replayed as a centered modal.
   ============================================================ */

(function () {
    'use strict';

    /* ---- DOM injection (once) ---- */
    function ensureContainers() {
        if (document.getElementById('co-toast-container')) return;

        const toastBox = document.createElement('div');
        toastBox.id = 'co-toast-container';
        toastBox.style.cssText = [
            'position:fixed',
            'top:1rem',
            'right:1rem',
            'z-index:2000',
            'display:flex',
            'flex-direction:column',
            'gap:.5rem',
            'pointer-events:none'
        ].join(';');
        document.body.appendChild(toastBox);

        const modal = document.createElement('div');
        modal.id = 'co-feedback-modal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body text-center py-4">
                        <div id="co-feedback-icon" class="mb-2" style="font-size:2.5rem;"></div>
                        <div id="co-feedback-msg" class="fw-semibold"></div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    /* ---- Toast (top-right, 3s) ---- */
    function showToast(opts) {
        ensureContainers();
        const { success = true, message = 'Done' } = opts || {};
        const box = document.getElementById('co-toast-container');

        const el = document.createElement('div');
        el.className = 'co-toast ' + (success ? 'co-toast-ok' : 'co-toast-err');
        el.setAttribute('role', 'alert');
        const icon = success ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        el.innerHTML = `<i class="bi ${icon}"></i><span>${escapeHtml(message)}</span>`;

        box.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));

        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 350);
        }, 3000);
    }

    /* ---- Modal (centered, 5s) ---- */
    function showModal(opts) {
        ensureContainers();
        const { success = true, message = 'Done' } = opts || {};
        const modalEl = document.getElementById('co-feedback-modal');
        const iconEl  = document.getElementById('co-feedback-icon');
        const msgEl   = document.getElementById('co-feedback-msg');

        iconEl.innerHTML = success
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<i class="bi bi-exclamation-triangle-fill text-danger"></i>';
        msgEl.textContent = message;

        const m = bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true });
        m.show();

        clearTimeout(showModal._t);
        showModal._t = setTimeout(() => m.hide(), 3000);
    }

    /**
     * Mark a button so any future click on it is ignored by the global
     * auto-ack handler (used by pages that manage their own feedback,
     * e.g. create_event shows its own success modal after AJAX).
     */
    function setSilentClick(btn) {
        if (!btn) return;
        btn.dataset.silentClick = '1';
    }

    /* ============================================================
       Auto-detect server-rendered flash banners on page load and
       replay them as a centered modal (login/register/logout etc.).
       ============================================================ */
    function autoShowFlashes() {
        const candidates = document.querySelectorAll(
            '.alert-success, .alert-danger, .alert-warning, .alert-info, .co-flash-success, .co-flash-error'
        );
        candidates.forEach(el => {
            if (el.classList.contains('d-none')) return;
            // Inline form banners handle their own UX; skip them
            if (el.id === 'client-error' || el.id === 'client-success') return;
            if (el.id === 'flash-success' || el.id === 'flash-error') return;

            const success = el.classList.contains('alert-success') ||
                            el.classList.contains('co-flash-success') ||
                            el.classList.contains('alert-info');
            const message = (el.textContent || '').trim();
            if (!message) return;
            showModal({ success, message });
            // Hide the inline banner — its content has been replayed
            el.classList.add('d-none');
        });
    }

    /* ============================================================
       Global submit-guard: suppress native form submission of forms
       that want AJAX. We DON'T auto-show feedback anymore — pages
       that need it call CampusOrbit.toast/modal explicitly.
       ============================================================ */
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.classList.contains('js-ajax-form')) {
            e.preventDefault();
        }
    }, true);

    /* ---- Public API ---- */
    window.CampusOrbit                = window.CampusOrbit || {};
    window.CampusOrbit.toast          = showToast;
    window.CampusOrbit.modal          = showModal;
    window.CampusOrbit.setSilentClick = setSilentClick;

    document.addEventListener('DOMContentLoaded', () => {
        ensureContainers();
        autoShowFlashes();
    });
})();