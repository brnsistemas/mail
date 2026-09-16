@extends('layout')
@section('title','Entrar')
@section('content')
<main id="main" class="auth-card"><div class="eyebrow">SUA CORRESPONDÊNCIA, NO LUGAR CERTO</div><h1>Bom ter você aqui.</h1><p>Entre com sua conta individual. Suas caixas ficam separadas por empresa e permissão.</p>
<form method="post" action="/login">@csrf<label>E-mail<input name="email" type="email" autocomplete="username" required maxlength="254"></label><label>Senha<input name="password" type="password" autocomplete="current-password" required maxlength="200"></label><button class="primary wide">Continuar com segurança <span>→</span></button></form>
<div class="auth-foot"><strong>Segundo fator obrigatório</strong><p>Depois da senha, confirme seu código de autenticação. Acesso somente por convite.</p></div>
@if(app()->environment('local') && config('brnmail.local_demo'))<details><summary>Acesso à demonstração local</summary><p>Somente dados fictícios. Usuário <code>demo@brnmail.test</code>, senha de demonstração <code>Demo-local-only!2026</code>. Configure seu autenticador no próximo passo.</p></details>@endif
</main>@endsection
