@extends('layout')
@section('content')
<div class="management-shell security-shell">
    <aside class="management-sidebar">
        <a class="management-back" href="/mail"><x-icon name="back"/> Voltar às caixas</a>
        <div class="eyebrow">MINHA CONTA</div>
        <nav class="management-nav" aria-label="Segurança">
            <div class="management-nav-group">
                <a href="/security" @if(request()->is('security')) aria-current="page" @endif><x-icon name="shield"/> Visão geral</a>
            </div>
        </nav>
        @if(auth()->user()->master)<a class="management-security" href="/admin"><x-icon name="settings"/> Administração</a>@endif
        <p class="sidebar-help">Você controla quem pode acessar cada caixa.</p>
    </aside>
    <main id="main" class="management-content settings-content">
        <div class="page-heading"><div><div class="eyebrow">BRN MAIL · SEGURANÇA</div><h1>@yield('security-title')</h1><p>@yield('security-description')</p></div>@yield('security-action')</div>
        @yield('security-content')
    </main>
</div>
@endsection
