@extends('layout')
@section('title', $sections[$section][0].' · Administração')
@push('head')
<script src="/admin.js?v=20261022-navigation" defer></script>
@endpush
@section('content')
<div class="management-shell">
    <aside class="management-sidebar">
        <a class="management-back" href="/mail">← Voltar às caixas</a>
        <div class="eyebrow">ADMINISTRAÇÃO</div>
        <nav aria-label="Administração" class="management-nav">
            @foreach(collect($sections)->groupBy(fn ($item) => $item[2], preserveKeys: true) as $group => $items)
                <div class="management-nav-group"><span>{{ $group }}</span>
                @foreach($items as $key => $item)
                    <a href="{{ $key === 'overview' ? '/admin' : '/admin/'.$key }}" @if($section === $key) aria-current="page" @endif>{{ $item[0] }}</a>
                @endforeach
                </div>
            @endforeach
        </nav>
        <a class="management-security" href="/security"><x-icon name="shield"/> Segurança da minha conta</a>
    </aside>
    <main id="main" class="management-content">
        <div class="page-heading">
            <div><div class="eyebrow">BRN MAIL · ADMINISTRAÇÃO</div><h1>{{ $section === 'overview' ? 'Administração' : $sections[$section][0] }}</h1><p>{{ $sections[$section][1] }}</p></div>
            <span class="status">Master</span>
        </div>
        @include('admin.sections.'.$section)
    </main>
</div>
@endsection
