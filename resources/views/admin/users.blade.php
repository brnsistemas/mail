<section class="panel" id="user-overview" aria-labelledby="user-overview-title">
    <h2 id="user-overview-title">Contas de acesso</h2>
    <p>Identidades cadastradas no BRN Mail, inclusive quem ainda não tem permissão em uma caixa. “Sem registro” significa que não há confirmação de 2FA no histórico disponível.</p>
    <div class="table-wrap" role="region" aria-label="Contas e último login" tabindex="0">
        <table class="inventory-table" data-testid="user-inventory">
            <thead><tr><th scope="col">Pessoa / login</th><th scope="col">Conta</th><th scope="col">Perfil</th><th scope="col">Autenticador</th><th scope="col">Último login com 2FA (UTC)</th></tr></thead>
            <tbody>
            @forelse($users as $person)
                <tr data-user-id="{{ $person->id }}">
                    <td><strong>{{ $person->name }}</strong><br>{{ $person->email }}</td>
                    <td>{{ $person->active ? 'Ativa' : 'Desativada' }}</td>
                    <td>{{ $person->master ? 'Master' : 'Usuário' }}</td>
                    <td>{{ $person->totp_confirmed_at ? 'Configurado' : 'Pendente' }}</td>
                    <td>@if($person->last_login_at)<time datetime="{{ $person->last_login_at->toIso8601String() }}">{{ $person->last_login_at->utc()->format('d/m/Y H:i:s') }}</time>@else Sem registro @endif</td>
                </tr>
            @empty
                <tr><td colspan="5">Nenhuma conta cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@include('admin.pagination', ['paginator' => $users, 'label' => 'Pessoas'])
</section>
