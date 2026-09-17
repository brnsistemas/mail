/* Exchange the private fragment for a session proof. Never use browser storage. */
(() => {
    'use strict';
    if (document.querySelector('[data-invite-ended]')) {
        if (window.location.hash) window.history.replaceState(null, '', window.location.pathname);
        return;
    }
    const page = document.querySelector('[data-invite-open]');
    if (!page) return;
    const fragment = window.location.hash.slice(1);
    if (!fragment) return;
    const status = document.getElementById('invite-status');
    if (!/^[a-f0-9]{64}$/.test(fragment)) {
        status.textContent = 'O link está incompleto. Abra o link original compartilhado pelo administrador.';
        return;
    }
    status.textContent = 'Conferindo seu convite…';
    const form = page.querySelector('form');
    if (form) form.hidden = true;
    (async () => {
        try {
            const response = await fetch(page.dataset.inviteOpen, {
                method: 'POST', credentials: 'same-origin', redirect: 'error',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                body: new URLSearchParams({token: fragment}),
            });
            if (response.status !== 204) {
                if (response.status === 410 && typeof response.json === 'function') {
                    const result = await response.json();
                    if (['invite_used', 'invite_expired'].includes(result.code)) {
                        window.history.replaceState(null, '', window.location.pathname);
                        window.location.reload();
                        return;
                    }
                }
                status.textContent = response.status === 410
                    ? 'Este convite não é válido. Confira o link completo compartilhado pelo administrador.'
                    : 'Não foi possível conferir o convite. Abra novamente o link original e tente outra vez.';
                return;
            }
            // Strip the fragment only after the server has saved the proof.
            window.history.replaceState(null, '', window.location.pathname);
            window.location.reload();
        } catch {
            status.textContent = 'Não foi possível conferir o convite. Verifique a conexão e recarregue esta página.';
        }
    })();
})();
