@include('admin.filters')
<section class="panel"><h2>Aliases cadastrados</h2><div class="admin-records">@forelse($records as $item)<article><div><strong>{{ $item->address }}</strong><small>Recebe em {{ $item->destination }}</small></div></article>@empty<p>Nenhum alias encontrado.</p>@endforelse</div>@include('admin.pagination', ['paginator' => $records, 'label' => 'Aliases'])</section>
<details class="admin-create"><summary>Criar alias de recebimento</summary>@include('admin.forms.alias')</details>
