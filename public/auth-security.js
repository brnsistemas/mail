/* MFA secrets stay in the current page only. No storage, analytics or network calls. */
(() => {
    'use strict';
    const status = document.getElementById('security-action-status');
    const announce = (message) => { if (status) status.textContent = message; };
    const copyButton = document.getElementById('copy-totp-key');
    const keyInput = document.getElementById('totp-key');

    copyButton?.addEventListener('click', async () => {
        if (!keyInput) return;
        let copied = false;
        try {
            if (window.isSecureContext && navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(keyInput.value);
                copied = true;
            }
        } catch { /* A denied clipboard permission falls back to explicit selection. */ }
        if (!copied) {
            keyInput.focus();
            keyInput.select();
            keyInput.setSelectionRange(0, keyInput.value.length);
            try { copied = document.execCommand('copy'); } catch { copied = false; }
        }
        announce(copied
            ? 'Chave copiada. Cole somente no seu autenticador.'
            : 'A cópia automática não está disponível. A chave foi selecionada: use Copiar no menu do dispositivo.');
        if (copied) copyButton.focus();
    });

    let downloadUrl = null;
    let releaseTimer = null;
    const releaseDownload = () => {
        if (downloadUrl) URL.revokeObjectURL(downloadUrl);
        downloadUrl = null;
        if (releaseTimer) window.clearTimeout(releaseTimer);
        releaseTimer = null;
    };
    document.getElementById('download-recovery-codes')?.addEventListener('click', () => {
        const codes = [...document.querySelectorAll('#recovery-codes code')].map((node) => node.textContent.trim());
        if (codes.length !== 8 || codes.some((code) => !/^[a-f0-9]{20}$/.test(code))) {
            announce('Não foi possível preparar o arquivo. Guarde os códigos exibidos no seu cofre.');
            return;
        }
        releaseDownload();
        const documentText = [
            'BRN Mail — códigos de recuperação',
            '',
            'SEGREDO: guarde este arquivo em seu cofre, fora da caixa de e-mail.',
            'Cada código substitui o autenticador uma única vez.',
            'Não compartilhe este arquivo. Remova a cópia de Downloads depois de guardá-lo no cofre.',
            '',
            ...codes,
            '',
        ].join('\r\n');
        try {
            downloadUrl = URL.createObjectURL(new Blob([documentText], { type: 'text/plain;charset=utf-8' }));
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = 'brnmail-codigos-recuperacao.txt';
            link.hidden = true;
            document.body.append(link);
            link.click();
            link.remove();
            releaseTimer = window.setTimeout(releaseDownload, 1000);
            announce('Download solicitado. Confira o arquivo e guarde-o no seu cofre.');
        } catch {
            releaseDownload();
            announce('O navegador não permitiu o download. Guarde os códigos exibidos no seu cofre.');
        }
    });
    window.addEventListener('pagehide', releaseDownload);
})();
