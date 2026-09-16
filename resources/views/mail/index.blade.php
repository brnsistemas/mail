@extends('layout')
@section('title',$box?->name ?? 'Minhas caixas')
@section('body-class','mail-page '.($selected ? 'message-open' : 'mail-overview'))
@section('header-search')
@if($box)
<form class="search header-search" action="/mail/search" method="post">@csrf
    <input type="hidden" name="box" value="{{ $box->id }}"><input type="hidden" name="folder" value="{{ $folder }}">
    <x-icon name="search"/><label class="sr-only" for="search-mail">Pesquisar nesta caixa</label>
    <input id="search-mail" name="q" value="{{ $search }}" placeholder="Pesquisar em {{ $box->name }}" maxlength="100">
    <button class="icon-button" aria-label="Pesquisar" title="Pesquisar"><x-icon name="back" class="search-submit"/></button>
</form>
@endif
@endsection
@section('header-compose')
@if($canSend)<form method="post" action="/drafts">@csrf<input type="hidden" name="mailbox_id" value="{{ $box->id }}"><button class="icon-button compose-button" aria-label="Escrever e-mail" title="Escrever e-mail por {{ $box->address }}"><x-icon name="compose"/></button></form>@endif
@endsection
@section('content')
@php
$folders=['inbox'=>'Entrada','drafts'=>'Rascunhos','sent'=>'Enviados','archive'=>'Arquivados','trash'=>'Lixeira'];
$folderIcons=['inbox'=>'inbox','drafts'=>'draft','sent'=>'send','archive'=>'archive','trash'=>'trash'];
$labels=['received'=>'Recebido','draft'=>'Rascunho','queued'=>'Na fila','simulated'=>'Processado localmente · não enviado','accepted'=>'Aceito pelo provedor','delivered'=>'Entregue','bounced'=>'Devolvido','complained'=>'Reclamação','failed'=>'Falha','uncertain'=>'Resultado incerto','delayed'=>'Entrega atrasada'];
$rowLabels=array_merge($labels,['simulated'=>'Simulado','accepted'=>'Aceito','uncertain'=>'Verificar envio']);
$organizations=$boxes->groupBy(fn($b)=>$b->domain->product->organization_id);
@endphp
<div class="mail-shell">
    <aside class="sidebar">
        <details class="workspace-picker" data-popover data-testid="workspace-switcher">
            <summary class="workspace-trigger" title="Alternar empresa, domínio e caixa">
                <span class="workspace-avatar"><x-icon name="building"/></span>
                <span class="workspace-current"><strong>{{ $box?->domain->product->organization->name ?? 'Minhas empresas' }}</strong><small>{{ $box?->name ?? 'Selecionar caixa' }}</small></span>
                <x-icon name="chevron"/>
            </summary>
            <div class="workspace-panel">
                <div class="switcher-heading"><strong>Alternar caixa</strong><span>Seu login, suas empresas.</span></div>
                <label class="switcher-search"><span class="sr-only">Filtrar empresas, domínios e caixas</span><x-icon name="search"/><input id="workspace-filter" placeholder="Empresa, domínio ou e-mail" autocomplete="off"></label>
                <div class="workspace-options">
                @forelse($organizations as $organizationBoxes)
                    <section class="workspace-group">
                        <h2>{{ $organizationBoxes->first()->domain->product->organization->name }}</h2>
                        @foreach($organizationBoxes->groupBy('mail_domain_id') as $domainBoxes)
                        <div class="domain-group"><h3>{{ $domainBoxes->first()->domain->domain }}<span>{{ $domainBoxes->first()->domain->product->name }}</span></h3>
                        @foreach($domainBoxes as $b)
                        <a href="/mail?box={{ $b->id }}&folder=inbox" class="workspace-option {{ $box?->id===$b->id?'selected':'' }}" data-testid="workspace-option" data-mailbox-id="{{ $b->id }}" data-search="{{ mb_strtolower($b->name.' '.$b->address.' '.$b->domain->product->organization->name.' '.$b->domain->product->name) }}" @if($box?->id===$b->id) aria-current="true" @endif>
                            <span class="avatar">{{ mb_strtoupper(mb_substr($b->name,0,1)) }}</span><span><strong>{{ $b->name }}</strong><small>{{ $b->address }}</small></span>@if($box?->id===$b->id)<x-icon name="check"/>@endif
                        </a>
                        @endforeach</div>
                        @endforeach
                    </section>
                @empty<p class="switcher-empty">Nenhuma caixa autorizada. Peça acesso ao administrador.</p>@endforelse
                <p id="workspace-no-results" class="switcher-empty" hidden>Nenhuma caixa encontrada.</p>
                </div>
                <div class="switcher-footer"><x-icon name="shield"/><span>Apenas caixas às quais você tem acesso. O remetente acompanha a caixa selecionada.</span></div>
            </div>
        </details>
        <div class="active-address" title="{{ $box?->address }}">{{ $box?->address }}</div>
        <nav aria-label="Pastas" class="folders">@foreach($folders as $key=>$label)<a class="nav-item {{ $folder===$key?'active':'' }}" href="/mail?box={{ $box?->id }}&folder={{ $key }}" @if($folder===$key) aria-current="page" @endif><x-icon :name="$folderIcons[$key]"/><span>{{ $label }}</span><span class="count">{{ $counts[$key] ?? 0 }}</span></a>@endforeach</nav>
        <div class="sidebar-bottom">
            <a href="/security"><x-icon name="shield"/> Segurança</a>@if(auth()->user()->master)<a href="/admin"><x-icon name="settings"/> Administração</a>@endif
            <div class="sidebar-mode"><span class="mode-dot"></span><span>{{ config('brnmail.external_enabled') ? 'Envio externo habilitado' : 'Envio externo desativado' }}</span></div>
        </div>
    </aside>
    <main id="main" class="message-list">
        <div class="list-head"><div><h1>{{ $folders[$folder] }}</h1><p>{{ $messages->total() }} {{ $messages->total()===1?'mensagem':'mensagens' }}{{ $search ? ' encontradas' : '' }}</p></div><a href="{{ request()->fullUrlWithQuery(['box'=>$box?->id,'folder'=>$folder]) }}" class="icon-button refresh" aria-label="Atualizar mensagens" title="Atualizar"><x-icon name="refresh"/></a></div>
        @if($search)<form class="search-result" action="/mail/search" method="post">@csrf<input type="hidden" name="box" value="{{ $box?->id }}"><input type="hidden" name="folder" value="{{ $folder }}"><input type="hidden" name="q" value=""><span>Resultados para “{{ $search }}”</span><button class="icon-button" aria-label="Limpar pesquisa" title="Limpar pesquisa"><x-icon name="close"/></button></form>@endif
        <div class="rows">@forelse($messages as $m)
            @php
                $senderName=preg_replace('/\s*<[^<>]+>$/u','',$m->sender) ?: $m->sender;
                $unread=$m->direction==='inbound' && !$m->read_at;
            @endphp
            <a class="message-row {{ $selected?->id===$m->id?'current':'' }} {{ $unread?'unread':'' }}" href="/mail?box={{ $m->mailbox_id }}&folder={{ $folder }}&message={{ $m->id }}" @if($selected?->id===$m->id) aria-current="true" @endif>
                <span class="read-dot">@if($unread)<span class="sr-only">Não lida.</span>@endif</span>
                <strong class="row-sender">{{ $senderName }}</strong>
                <div class="row-preview"><h2>{{ $m->subject ?: '(sem assunto)' }}</h2><p>{{ Str::limit(preg_replace('/\s+/u',' ',$m->body_text),140) }}</p></div>
                <div class="row-metadata">@if($m->attachments_count)<span title="{{ $m->attachments_count }} anexos"><x-icon name="paperclip"/><span class="sr-only">{{ $m->attachments_count }} anexos</span></span>@endif
                @if($m->status!=='received')<span class="status {{ $m->status }}" title="{{ $labels[$m->status] ?? $m->status }}">{{ $rowLabels[$m->status] ?? $m->status }}</span>@endif
                <time datetime="{{ $m->created_at->toIso8601String() }}" title="{{ $m->created_at->format('d/m/Y H:i') }} UTC">{{ $m->created_at->isToday() ? $m->created_at->format('H:i') : $m->created_at->format('d/m') }}</time></div>
            </a>
        @empty<div class="empty"><x-icon name="inbox"/><h2>{{ !$box ? 'Nenhuma caixa atribuída' : ($search ? 'Nenhum resultado' : 'Tudo em dia') }}</h2><p>{{ !$box ? 'Peça ao administrador acesso às caixas necessárias.' : ($search ? 'Tente outro termo nesta caixa.' : 'Não há mensagens nesta pasta.') }}</p></div>@endforelse</div>
        <div class="pagination"><span>{{ $messages->firstItem() ?? 0 }}–{{ $messages->lastItem() ?? 0 }} de {{ $messages->total() }}</span><div>@if($messages->previousPageUrl())<a class="icon-button" aria-label="Página anterior" href="{{ $messages->previousPageUrl() }}"><x-icon name="back"/></a>@endif<span>{{ $messages->currentPage() }} / {{ $messages->lastPage() }}</span>@if($messages->nextPageUrl())<a class="icon-button next-page" aria-label="Próxima página" href="{{ $messages->nextPageUrl() }}"><x-icon name="back"/></a>@endif</div></div>
    </main>
    @if($selected)
    <section class="reading" aria-label="Mensagem selecionada">
        <div class="reading-toolbar">
            <a class="icon-button back-list" href="/mail?box={{ $box->id }}&folder={{ $folder }}" aria-label="Voltar para mensagens" title="Voltar para mensagens"><x-icon name="back"/></a>
            <span class="status {{ $selected->status }}">{{ $labels[$selected->status] ?? $selected->status }}</span>
            @if($selected->direction==='inbound' && $canSend)
            <form action="/messages/{{ $selected->id }}/move" method="post">@csrf<input type="hidden" name="folder" value="{{ $folder==='archive'?'inbox':'archive' }}"><button class="icon-button" aria-label="{{ $folder==='archive'?'Restaurar':'Arquivar' }}" title="{{ $folder==='archive'?'Restaurar':'Arquivar' }}"><x-icon name="archive"/></button></form>
            <form action="/messages/{{ $selected->id }}/move" method="post">@csrf<input type="hidden" name="folder" value="{{ $folder==='trash'?'inbox':'trash' }}"><button class="icon-button" aria-label="{{ $folder==='trash'?'Restaurar da lixeira':'Mover para lixeira' }}" title="{{ $folder==='trash'?'Restaurar da lixeira':'Mover para lixeira' }}"><x-icon name="trash"/></button></form>
            @endif
        </div>
        <div class="reading-content">
            @if($selected->status==='draft')
                <div class="eyebrow">NOVA MENSAGEM</div><h1>Novo e-mail</h1>
                <p class="from-line">De <strong>{{ $box->address }}</strong><span>{{ $box->domain->product->organization->name }} · {{ $box->name }}</span></p>
                @if($selected->in_reply_to)<div class="notice">Resposta pela caixa original. Confira o destinatário abaixo antes de enviar.</div>@endif
                <form action="/drafts/{{ $selected->id }}" method="post" class="composer" id="draft-form">@csrf<input type="hidden" name="version" value="{{ $selected->version }}"><label>Para<input name="to" value="{{ implode(', ',$selected->recipients['to'] ?? []) }}" required maxlength="2000"></label><details><summary>CC e BCC</summary><label>CC<input name="cc" value="{{ implode(', ',$selected->recipients['cc'] ?? []) }}" maxlength="2000"></label><label>BCC (cópia oculta)<input name="bcc" value="{{ implode(', ',$selected->recipients['bcc'] ?? []) }}" maxlength="2000"></label></details><label>Assunto<input name="subject" value="{{ $selected->subject }}" required maxlength="500"></label><label>Mensagem<textarea name="body_text" rows="12" required maxlength="100000">{{ $selected->body_text }}</textarea></label><button class="secondary">Salvar rascunho</button><span id="draft-state" class="muted" role="status">Versão salva: {{ $selected->version }}</span></form>
            @else
                <h1>{{ $selected->subject }}</h1>
                <div class="sender-block"><span class="avatar">{{ mb_strtoupper(mb_substr($selected->sender,0,1)) }}</span><div><strong>{{ $selected->sender }}</strong><p>Para {{ implode(', ',$selected->recipients['to'] ?? []) }}</p><time>{{ $selected->created_at->format('d/m/Y · H:i') }} UTC</time></div></div>
                <div class="mail-body">{{ $selected->body_text }}</div>
            @endif
            @if($selected->attachments->count())<section class="attachment-list"><h2>Anexos</h2>@foreach($selected->attachments as $a)<div class="attachment"><span><strong>{{ $a->filename }}</strong><small>{{ number_format($a->size/1024,1,',','.') }} KB · {{ ['clean'=>'Verificado','quarantine'=>'Em quarentena','blocked'=>'Bloqueado','unavailable'=>'Indisponível'][$a->status] ?? $a->status }}</small></span>@if($a->status==='clean')<a href="/attachments/{{ $a->id }}">Baixar</a>@endif @if($selected->status==='draft')<form method="post" action="/attachments/{{ $a->id }}/remove">@csrf<button aria-label="Remover {{ $a->filename }}">Remover</button></form>@endif</div>@endforeach</section>@endif
            @if($selected->status==='draft')
                <form action="/drafts/{{ $selected->id }}/attachments" method="post" enctype="multipart/form-data" class="upload-form">@csrf<label>Anexar imagem ou documento<input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.docx,.xlsx,.pptx,.txt,.csv" required></label><button><x-icon name="paperclip"/> Anexar e verificar</button><p class="muted">Até 5 arquivos · 10 MiB cada · 20 MiB por mensagem. Vídeos não permitidos. Salve o texto antes de anexar.</p></form>
                <form action="/drafts/{{ $selected->id }}/send" method="post" id="send-form">@csrf<input type="hidden" name="version" value="{{ $selected->version }}"><button class="primary"><x-icon name="send"/>{{ config('brnmail.transport')==='local'?'Processar envio local':'Enviar para destinatários autorizados' }}</button><p class="muted">O envio usa a última versão salva. {{ config('brnmail.transport')==='local'?'Nenhuma mensagem sairá deste ambiente.':'' }}</p></form>
            @elseif($selected->direction==='inbound')
                @if($canSend)<form action="/drafts" method="post" class="reply-form">@csrf<input type="hidden" name="mailbox_id" value="{{ $box->id }}"><input type="hidden" name="reply_to" value="{{ $selected->id }}"><button class="secondary"><x-icon name="reply"/> Responder por {{ $box->name }}</button></form>@endif
                <details class="message-controls"><summary>Leitura e responsável <x-icon name="chevron"/></summary>
                    <form action="/messages/{{ $selected->id }}/read" method="post">@csrf<input type="hidden" name="unread" value="{{ $selected->read_at?1:0 }}"><button>{{ $selected->read_at?'Marcar como não lida':'Marcar como lida' }}</button><small>Estado compartilhado pela equipe desta caixa</small></form>
                    @if($canSend)<form action="/messages/{{ $selected->id }}/assign" method="post">@csrf<input type="hidden" name="version" value="{{ $selected->version }}"><label>Responsável por esta conversa<select name="user_id"><option value="">Sem responsável</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected($selected->assigned_to===$operator->id)>{{ $operator->name }}</option>@endforeach</select></label><button>Atualizar responsável</button></form>@endif
                </details>
            @endif
        </div>
    </section>
    @endif
</div>
@endsection
