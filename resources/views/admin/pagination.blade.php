@if($paginator->total() > 0)
<nav class="admin-pagination" aria-label="Paginação de {{ $label }}">
    <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
    <div>
        @if($paginator->onFirstPage())<span aria-disabled="true">Anterior</span>@else<a class="button" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>@endif
        <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
        @if($paginator->hasMorePages())<a class="button" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima</a>@else<span aria-disabled="true">Próxima</span>@endif
    </div>
</nav>
@endif
