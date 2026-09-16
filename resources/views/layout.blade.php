<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Caixa de entrada') · BRN Mail</title><link rel="stylesheet" href="/mail.css?v=20260910b"><script src="/mail.js?v=20260911-invite" defer></script>@stack('head')</head>
<body class="@yield('body-class')"><a class="skip" href="#main">Pular para conteúdo</a>
<header class="top">
    <a href="/mail" class="brand" aria-label="BRN Mail · minhas caixas"><span class="mark"><x-icon name="mail"/></span><strong>BRN<span>Mail</span></strong></a>
    @yield('header-search')
    <div class="top-actions">
        <span class="environment" title="{{ config('brnmail.transport') === 'local' ? 'Envio externo bloqueado: nenhuma mensagem sai deste ambiente' : 'Ambiente de homologação: somente destinatários autorizados' }}">{{ config('brnmail.transport') === 'local' ? 'Local' : 'Homologação' }}</span>
        @yield('header-compose')
        <button type="button" id="theme" class="icon-button" aria-label="Alternar tema" title="Alternar tema"><x-icon name="moon"/></button>
        @auth
        <details class="account-menu" data-popover><summary class="account-avatar" aria-label="Menu da conta: {{ auth()->user()->name }}" title="Minha conta">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,1)) }}</summary>
            <div class="account-panel"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small><a href="/security"><x-icon name="shield"/> Segurança da conta</a>@if(auth()->user()->master)<a href="/admin"><x-icon name="settings"/> Administração</a>@endif<form action="/logout" method="post">@csrf<button class="quiet"><x-icon name="logout"/> Sair desta conta</button></form></div>
        </details>
        @endauth
    </div>
</header>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Confira antes de continuar</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</body></html>
