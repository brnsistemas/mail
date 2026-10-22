@include('admin.filters')
<section class="panel"><h2>Produtos cadastrados</h2><div class="admin-records">@forelse($records as $item)<article><div><strong>{{ $item->name }}</strong><small>{{ $item->organization->name }}</small></div></article>@empty<p>Nenhum produto encontrado.</p>@endforelse</div>@include('admin.pagination', ['paginator' => $records, 'label' => 'Produtos'])</section>
<details class="admin-create" id="new-product"><summary>Criar produto</summary>@include('admin.forms.product')</details>
