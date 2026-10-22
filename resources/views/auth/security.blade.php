@extends('layout')
@section('title', 'Segurança')
@section('content')
<main id="main" class="admin-page security-page">
    <a href="/mail">← Voltar às caixas</a>
    <div class="page-heading"><div><div class="eyebrow">MINHA CONTA</div><h1>Segurança da conta</h1><p>Gerencie as sessões de acesso ao webmail.</p></div></div>
    <section class="panel"><h2>Sua conta está protegida</h2><p>{{ auth()->user()->email }} · Autenticação em duas etapas configurada.</p><p class="muted">Suas caixas continuam separadas por empresa e concessão explícita.</p></section>
    <section class="panel"><h2>Sessões da conta</h2><p>Se precisar encerrar os acessos atuais, confirme sua senha e o segundo fator. Todas as sessões, inclusive esta, precisarão confirmar novamente o 2FA.</p>
        <details class="security-revoke"><summary>Revogar sessões</summary>
            <form method="post" action="/security/revoke">@csrf
                <label>Senha atual<input name="password" type="password" required autocomplete="current-password"></label>
                <label>Código TOTP ou recuperação<input name="code" required autocomplete="one-time-code"></label>
                <button class="danger">Revogar e sair</button>
            </form>
        </details>
    </section>
</main>
@endsection
