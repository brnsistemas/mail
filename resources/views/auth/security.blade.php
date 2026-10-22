@extends('auth.security-layout')
@section('title', 'Segurança')
@section('security-title', 'Segurança da conta')
@section('security-description', 'Gerencie a segurança e as sessões da sua conta.')
@section('security-content')
    <section class="panel protection-card"><span class="protection-icon"><x-icon name="shield"/></span><div><h2>Sua conta está protegida</h2><p>{{ auth()->user()->email }} · Autenticação em duas etapas configurada.</p><p class="muted">Suas caixas continuam separadas por empresa e concessão explícita.</p></div><span class="status-badge is-active">2FA ativo</span></section>
    <section class="panel"><h2>Sessões da conta</h2><p>Se precisar encerrar os acessos atuais, confirme sua senha e o segundo fator. Todas as sessões, inclusive esta, precisarão confirmar novamente o 2FA.</p>
        <details class="security-revoke"><summary>Revogar sessões</summary>
            <form method="post" action="/security/revoke">@csrf
                <label>Senha atual<input name="password" type="password" required autocomplete="current-password"></label>
                <label>Código TOTP ou recuperação<input name="code" required autocomplete="one-time-code"></label>
                <button class="danger">Revogar e sair</button>
            </form>
        </details>
    </section>

@endsection
