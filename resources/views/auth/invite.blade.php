@extends('layout')
@section('title','Aceitar convite')
@push('head')<script src="/invite.js?v=20260917" defer></script>@endpush
@section('content')
<main id="main" class="auth-card" data-invite-open="/invite/{{ $id }}/open">
    <h1>{{ $ready ? 'Convite para '.$organization : 'Abra seu convite.' }}</h1>
    <p id="invite-status" role="status">{{ $ready ? 'Este convite é para '.$email.'.' : 'Abra o link completo compartilhado pelo administrador. O código é reconhecido automaticamente.' }}</p>
    <noscript><p>Ative o JavaScript e abra novamente o link completo do convite.</p></noscript>
    @if($ready)
        @if($reactivation)<p>Este é um convite de nova ativação. O acesso anterior foi revogado. Escolha uma nova senha e configure um novo autenticador. Sua conta e os vínculos autorizados serão preservados.</p>
        @elseif($mailboxAddress)<p>Sua caixa <strong>{{ $mailboxAddress }}</strong> já está preparada. Ao aceitar, você terá acesso para ler, enviar e responder depois de confirmar seu autenticador.</p>
        @else<p>Este convite antigo vincula sua conta à empresa. Peça ao administrador para confirmar sua caixa e as permissões.</p>@endif
        @if($existingAccount)<p>Você já tem uma conta no BRN Mail. Confirme sua senha atual; seu autenticador será mantido.</p>
        @else<p>Escolha sua senha e digite-a novamente para confirmar. Depois, você seguirá diretamente para configurar o autenticador.</p>@endif
        <form action="/invite/{{ $id }}" method="post">
            @csrf
            <label>E-mail de acesso<input name="email" type="email" value="{{ $email }}" autocomplete="username" readonly></label>
            <label>Nome<input name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}"></label>
            <label>{{ $existingAccount ? 'Sua senha atual do BRN Mail' : 'Crie uma senha (mínimo 14 caracteres)' }}<input type="password" name="password" required minlength="14" maxlength="150" autocomplete="{{ $existingAccount ? 'current-password' : 'new-password' }}"></label>
            <label>Confirme a senha<input type="password" name="password_confirmation" required autocomplete="{{ $existingAccount ? 'current-password' : 'new-password' }}"></label>
            <button class="primary">{{ $existingAccount ? 'Aceitar convite' : ($reactivation ? 'Definir minha senha e configurar o autenticador' : 'Criar minha conta e configurar o autenticador') }}</button>
        </form>
    @endif
</main>
@endsection
