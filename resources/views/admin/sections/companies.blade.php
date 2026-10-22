@include('admin.filters')
<section class="panel"><h2>Empresas cadastradas</h2><div class="admin-records">@forelse($records as $item)<article><strong>{{ $item->name }}</strong><a href="/admin/products?organization={{ $item->id }}">Ver produtos →</a></article>@empty<p>Nenhuma empresa encontrada.</p>@endforelse</div>@include('admin.pagination', ['paginator' => $records, 'label' => 'Empresas'])</section>
<details class="admin-create" id="new-company"><summary>Criar empresa</summary>@include('admin.forms.company')</details>
