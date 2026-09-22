<section class="panel" id="mailbox-overview" aria-labelledby="mailbox-overview-title">
    <h2 id="mailbox-overview-title">Todas as caixas cadastradas</h2>
    <p>Visão administrativa de todas as empresas e domínios. Consultar este cadastro não concede acesso às mensagens.</p>
    <div class="info-grid">
        <div><strong>Total de caixas</strong><p>{{ $boxes->count() }}</p></div>
        <div><strong>Caixas ativas</strong><p>{{ $boxes->where('active', true)->count() }}</p></div>
        <div><strong>Caixas inativas</strong><p>{{ $boxes->where('active', false)->count() }}</p></div>
    </div>
    <p class="muted">O login pertence à pessoa. A data abaixo é o último login com 2FA entre os usuários com acesso efetivo à caixa; não indica leitura de mensagens. Horários em UTC.</p>
    <div class="table-wrap" role="region" aria-label="Inventário de caixas" tabindex="0">
        <table class="inventory-table" data-testid="mailbox-inventory">
            <thead><tr><th scope="col">Empresa / produto</th><th scope="col">Caixa / domínio</th><th scope="col">Estado</th><th scope="col">Usuários vinculados por permissão</th><th scope="col">Último login com 2FA (UTC)</th></tr></thead>
            <tbody>
            @forelse($inventory as $row)
                @php($box = $row['box'])
                <tr data-mailbox-id="{{ $box->id }}">
                    <td>{{ $box->domain->product->organization->name }}<br><small>{{ $box->domain->product->name }}</small></td>
                    <td><strong>{{ $box->name }}</strong><br>{{ $box->address }}<br><small>{{ $box->domain->domain }}</small></td>
                    <td>{{ $box->active ? 'Ativa' : 'Inativa' }}@if($box->sensitive)<br><small>Correspondência sensível</small>@endif</td>
                    <td>
                        @forelse($row['operators'] as $operator)
                            <p><strong>{{ $operator['user']->name }}</strong><br>{{ $operator['user']->email }}<br>
                                <small>{{ $operator['read'] ? 'Leitura' : '' }}{{ $operator['read'] && $operator['send'] ? ' · ' : '' }}{{ $operator['send'] ? 'Envio' : '' }}{{ ! $operator['read'] && ! $operator['send'] ? 'Sem acesso efetivo' : '' }}</small>
                            </p>
                        @empty
                            Nenhum usuário com permissão cadastrada.
                        @endforelse
                    </td>
                    <td>@if($row['last_login_at'])<time datetime="{{ $row['last_login_at']->toIso8601String() }}">{{ $row['last_login_at']->utc()->format('d/m/Y H:i:s') }}</time>@else Sem registro @endif</td>
                </tr>
            @empty
                <tr><td colspan="5">Nenhuma caixa cadastrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
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
</section>
