@extends('layout')
@section('title','Convite criado')
@section('content')<main id="main" class="auth-card"><h1>Convite criado.</h1><p>Válido por 48 horas e de uso único. Nenhum e-mail foi enviado. Compartilhe somente com a pessoa convidada por um canal privado.</p><label>Link privado<input readonly value="{{ $link }}"></label><a class="button primary" href="{{ $link }}" rel="noreferrer">Abrir convite</a><p>Quem já tem conta confirma a senha atual e mantém o autenticador. O acesso às caixas será concedido separadamente.</p><a class="button" href="/admin">Voltar</a></main>@endsection
