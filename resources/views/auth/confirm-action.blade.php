@extends('layout')
@section('title', $title)
@section('content')
<main id="main" class="admin-page security-page">
    <a href="{{ $back }}">← Voltar sem alterar</a>
    <section class="panel">
        <h1>{{ $title }}</h1><h2>{{ $resource }}</h2><p class="muted">{{ $context }}</p>
        <p>{{ $impact }}</p>
        <p>Esta ação não recupera mensagens já enviadas ao provedor.</p>
        <form method="post" action="{{ $target }}">@csrf
            <input type="hidden" name="state" value="{{ $state }}">
            <label><input type="checkbox" name="confirm" value="1" required>Conferi o item e o impacto desta ação.</label>
            <label>Confirme sua senha atual<input type="password" name="password" required autocomplete="current-password"></label>
            <div class="resource-actions"><button class="danger">{{ $title }}</button><a class="button quiet" href="{{ $back }}">Cancelar</a></div>
        </form>
    </section>
</main>
@endsection
