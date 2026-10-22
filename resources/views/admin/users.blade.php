<section class="panel" id="user-overview" aria-labelledby="user-overview-title">
    <h2 id="user-overview-title">Contas de acesso</h2>
    <p>Identidades cadastradas no BRN Mail, inclusive quem ainda não tem permissão em uma caixa. “Sem registro” significa que não há confirmação de 2FA no histórico disponível.</p>
    <div class="table-wrap" role="region" aria-label="Contas e último login" tabindex="0">
        <table class="inventory-table responsive-inventory" role="table" data-testid="user-inventory">
            <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Pessoa / login</th><th role="columnheader" scope="col">Conta</th><th role="columnheader" scope="col">Perfil</th><th role="columnheader" scope="col">Autenticador</th><th role="columnheader" scope="col">Último login com 2FA (UTC)</th></tr></thead>
            <tbody role="rowgroup">
            @forelse($users as $person)
                <tr role="row" data-user-id="{{ $person->id }}">
                    <td role="cell" data-label="Pessoa / login"><strong>{{ $person->name }}</strong><br>{{ $person->email }}</td>
                    <td role="cell" data-label="Conta">{{ $person->active ? 'Ativa' : 'Desativada' }}</td>
                    <td role="cell" data-label="Perfil">{{ $person->master ? 'Master' : 'Usuário' }}</td>
                    <td role="cell" data-label="Autenticador">{{ $person->totp_confirmed_at ? 'Configurado' : 'Pendente' }}</td>
                    <td role="cell" data-label="Último login com 2FA (UTC)">@if($person->last_login_at)<time datetime="{{ $person->last_login_at->toIso8601String() }}">{{ $person->last_login_at->utc()->format('d/m/Y H:i:s') }}</time>@else Sem registro @endif</td>
                </tr>
            @empty
                <tr role="row"><td colspan="5">Nenhuma conta cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@include('admin.pagination', ['paginator' => $users, 'label' => 'Pessoas'])
</section>
