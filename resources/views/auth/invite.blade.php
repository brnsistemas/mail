@extends('layout')
@section('title','Aceitar convite')
@push('head')<script src="/invite.js?v=20260911" defer></script>@endpush
@section('content')
<main id="main" class="auth-card" data-invite-open="/invite/{{ $id }}/open">
    <h1>{{ $ready ? 'Convite para '.$organization : 'Abra seu convite.' }}</h1>
    <p id="invite-status" role="status">{{ $ready ? 'Este convite é para '.$email.'.' : 'Abra o link completo compartilhado pelo administrador. O código é reconhecido automaticamente.' }}</p>
    <noscript><p>Ative o JavaScript e abra novamente o link completo do convite.</p></noscript>
    @if($ready)
        <p>O convite vincula sua conta à empresa. O acesso a cada caixa será concedido separadamente.</p>
        @if($existingAccount)<p>Você já tem uma conta no BRN Mail. Confirme sua senha atual; seu autenticador será mantido.</p>@endif
        <form action="/invite/{{ $id }}" method="post">
            @csrf
            <label>Nome<input name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}"></label>
            <label>{{ $existingAccount ? 'Sua senha atual do BRN Mail' : 'Crie uma senha (mínimo 14 caracteres)' }}<input type="password" name="password" required minlength="14" maxlength="150" autocomplete="{{ $existingAccount ? 'current-password' : 'new-password' }}"></label>
            <label>Confirme a senha<input type="password" name="password_confirmation" required autocomplete="{{ $existingAccount ? 'current-password' : 'new-password' }}"></label>
            <button class="primary">Aceitar convite</button>
        </form>
    @endif
</main>
@endsection
