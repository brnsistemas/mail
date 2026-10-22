<div class="admin-stats">
    @foreach(['companies' => 'Empresas', 'domains' => 'Domínios', 'mailboxes' => 'Caixas de e-mail', 'users' => 'Pessoas'] as $key => $label)
        <a class="panel admin-stat" href="/admin/{{ $key }}"><span>{{ $label }}</span><strong>{{ $counts[$key] }}</strong><span>Gerenciar →</span></a>
    @endforeach
</div>
<section class="panel"><h2>O que você deseja fazer?</h2>
    <div class="admin-shortcuts">
        <a href="/admin/mailboxes#new-mailbox"><strong>Criar uma caixa</strong><span>Adicione um endereço a um domínio cadastrado.</span></a>
        <a href="/admin/permissions"><strong>Liberar acesso a uma caixa</strong><span>Use a conta existente e escolha as permissões.</span></a>
        <a href="/admin/invites#new-invite"><strong>Convidar uma nova pessoa</strong><span>Prepare a caixa para o primeiro acesso.</span></a>
        <a href="/security"><strong>Segurança e dispositivos</strong><span>Gerencie as conexões da sua conta.</span></a>
    </div>
</section>
<section class="panel"><h2>Operação</h2><div class="info-grid"><div><strong>{{ $counts['active'] }}</strong><p>caixas ativas</p></div><div><strong>{{ $counts['pending'] }}</strong><p>envios aguardando processamento</p></div><div><strong>Permissões explícitas</strong><p>O perfil master não concede leitura automática das mensagens.</p></div></div><a class="button" href="/admin/activity">Consultar atividade</a></section>
