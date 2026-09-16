@extends('layout')
@section('title','Códigos de recuperação')
@push('head')
<link rel="stylesheet" href="/auth-security.css">
<script src="/auth-security.js" defer></script>
@endpush
@section('content')
<main id="main" class="auth-card security-card">
    <span class="security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m12 3 8 3v6c0 4-4 7-8 9-4-2-8-5-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg></span>
    <div class="eyebrow">AUTENTICADOR ATIVADO</div>
    <h1>Seu acesso, mesmo sem o celular.</h1>
    <p>Guarde estes oito códigos no seu cofre, fora desta caixa de e-mail. Cada um permite entrar uma única vez.</p>
    <ul class="recovery recovery-grid" id="recovery-codes" aria-label="Códigos de recuperação de uso único">@foreach($codes as $code)<li><code>{{ $code }}</code></li>@endforeach</ul>
    <button type="button" id="download-recovery-codes" class="button wide recovery-download">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
        Baixar códigos de recuperação
    </button>
    <p id="security-action-status" class="security-action-status" role="status" aria-live="polite"></p>
    <p class="recovery-warning">O arquivo contém códigos secretos, sem criptografia. Mova-o para seu cofre e remova a cópia de Downloads. Eles não serão mostrados novamente.</p>
    <a class="button primary wide" href="/mail">Guardei os códigos. Abrir minhas caixas →</a>
</main>
@endsection
