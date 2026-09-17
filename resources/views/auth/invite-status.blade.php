@extends('layout')
@section('title', $state === 'used' ? 'Convite já aceito' : 'Confira seu convite')
@push('head')<script src="/invite.js?v=20260917" defer></script>@endpush
@section('content')
<main id="main" class="auth-card" data-invite-ended>
    <h1>{{ $state === 'used' ? 'Seu convite já foi aceito.' : ($state === 'expired' ? 'Este convite expirou.' : 'Confira o link do convite.') }}</h1>
    <p role="status">{{ $message }}</p>
    @if($state === 'used')
        <p>Depois de entrar, configure seu autenticador, se ainda não fez isso. As caixas aparecem após o administrador conceder acesso.</p>
        <a class="button primary" href="/login">Entrar na minha conta</a>
    @else
        <p>Se você já possui uma conta no BRN Mail, pode entrar normalmente. O administrador pode vincular sua conta em Permissões por caixa.</p>
        <a class="button" href="/login">Já tenho conta</a>
    @endif
</main>
@endsection
