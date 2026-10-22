<section class="panel" id="mailbox-overview" aria-labelledby="mailbox-overview-title">
    <h2 id="mailbox-overview-title">Todas as caixas cadastradas</h2>
    <p>Visão administrativa de todas as empresas e domínios. Consultar este cadastro não concede acesso às mensagens.</p>
    <p class="muted">O login pertence à pessoa. A data abaixo é o último login com 2FA entre os usuários com acesso efetivo à caixa; não indica leitura de mensagens. Horários em UTC.</p>
    <div class="table-wrap" role="region" aria-label="Inventário de caixas" tabindex="0">
        <table class="inventory-table" data-testid="mailbox-inventory">
            <thead><tr><th scope="col">Empresa / produto</th><th scope="col">Caixa / domínio</th><th scope="col">Estado</th><th scope="col">Usuários vinculados por permissão</th><th scope="col">Último login com 2FA (UTC)</th><th scope="col">Ações</th></tr></thead>
            <tbody>
            @forelse($inventory as $row)
                @php($box = $row['box'])
                <tr data-mailbox-id="{{ $box->id }}">
                    <td>{{ $box->domain->product->organization->name }}<br><small>{{ $box->domain->product->name }}</small></td>
                    <td><strong>{{ $box->name }}</strong><br>{{ $box->address }}<br><small>{{ $box->domain->domain }}</small></td>
                    <td>{{ $box->deleted_at ? 'Excluída' : ($box->active ? 'Ativa' : 'Inativa / bloqueada') }}@if($box->sensitive)<br><small>Correspondência sensível</small>@endif</td>
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
                    <td><div class="resource-actions">
                        @foreach(app(\App\Services\MailboxLifecycle::class)->available($box) as $operation)
                            <a class="button quiet" href="/admin/mailboxes/{{ $box->id }}/{{ $operation }}">{{ \App\Services\MailboxLifecycle::ACTIONS[$operation][0] }}</a>
                        @endforeach
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="6">Nenhuma caixa encontrada para os filtros informados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@include('admin.pagination', ['paginator' => $boxes, 'label' => 'Caixas'])
</section>
