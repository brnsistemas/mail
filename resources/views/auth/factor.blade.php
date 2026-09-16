@extends('layout')
@section('title','Verificação em duas etapas')
@push('head')
<link rel="stylesheet" href="/auth-security.css">
<script src="/auth-security.js" defer></script>
@endpush
@section('content')
<main id="main" class="auth-card security-card">
    <span class="security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m12 3 8 3v6c0 4-4 7-8 9-4-2-8-5-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg></span>
    <div class="eyebrow">PROTEÇÃO DA CONTA</div>
    <h1>{{ $setup ? 'Uma camada a mais de segurança.' : 'Confirme que é você.' }}</h1>
    @if($setup)
        <p>Abra seu autenticador e escaneie o QR code para adicionar sua conta BRN Mail.</p>
        <div class="totp-qr" role="img" aria-label="QR code para configurar o autenticador. Como alternativa, use a chave de configuração abaixo.">{!! $qr !!}</div>
        <p class="qr-caption">Gerado aqui, sem compartilhar sua chave com serviços externos.</p>
        <details class="manual-key">
            <summary>Não consegue escanear? Use a chave de configuração</summary>
            <label for="totp-key">Chave de configuração</label>
            <div class="secret-copy-row">
                <input id="totp-key" value="{{ $secret }}" readonly autocomplete="off" spellcheck="false" aria-label="Chave de configuração do autenticador">
                <button type="button" id="copy-totp-key" aria-label="Copiar chave de configuração">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>
                    Copiar chave
                </button>
            </div>
            <p class="muted">Escolha código baseado em tempo (TOTP). Não compartilhe esta chave; ela só aparece durante a configuração.</p>
        </details>
        <p id="security-action-status" class="security-action-status" role="status" aria-live="polite"></p>
    @else
        <p>Digite o código de seis dígitos do autenticador. Se estiver sem o aparelho, use um código de recuperação.</p>
    @endif
    <form method="post" action="/two-factor" class="factor-form">
        @csrf
        <label for="factor-code">{{ $setup ? 'Código de seis dígitos' : 'Código de verificação ou recuperação' }}</label>
        <input id="factor-code" name="code" autocomplete="one-time-code" required autofocus maxlength="100" spellcheck="false" @if($setup) inputmode="numeric" pattern="[0-9]{6}" @endif>
        <button class="primary wide">Verificar e entrar</button>
    </form>
    <p class="security-footnote">Sua senha e o segundo fator protegem todas as empresas que você tem autorização para acessar.</p>
</main>
@endsection
