<p class="muted">Selecione a empresa antes de escolher as caixas. Nenhum acesso é concedido ao abrir esta página.</p>
@include('admin.filters')
@if(empty($filters['organization']))
<section class="panel admin-empty"><h2>Escolha uma empresa</h2><p>As caixas da empresa aparecerão aqui para você selecionar.</p></section>
@elseif($boxes->isEmpty())
<section class="panel admin-empty"><h2>Nenhuma caixa encontrada</h2><p>Ajuste os filtros ou cadastre uma caixa nessa empresa.</p><a href="/admin/mailboxes">Ir para caixas de e-mail</a></section>
@else
@include('admin.forms.permission')
@endif
