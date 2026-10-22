<form method="get" class="admin-filters" role="search" aria-label="Filtrar {{ $sections[$section][0] }}">
    <label>Buscar<input name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Nome ou endereço"></label>
    @if(isset($organizations) && $section !== 'users')
        <label>Empresa<select name="organization"><option value="">{{ $section === 'permissions' ? 'Escolha uma empresa' : 'Todas as empresas' }}</option>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected((string) ($filters['organization'] ?? '') === (string) $organization->id)>{{ $organization->name }}</option>@endforeach</select></label>
    @endif
    @if(in_array($section, ['users', 'mailboxes']))
        <label>Estado<select name="state"><option value="">Todos</option><option value="active" @selected(($filters['state'] ?? '') === 'active')>Ativas</option><option value="inactive" @selected(($filters['state'] ?? '') === 'inactive')>Inativas</option>@if($section === 'mailboxes')<option value="deleted" @selected(($filters['state'] ?? '') === 'deleted')>Excluídas</option>@endif</select></label>
    @endif
    <button class="primary">Filtrar</button><a class="button quiet" href="/admin/{{ $section }}">Limpar</a>
</form>
