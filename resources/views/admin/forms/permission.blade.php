<section class="panel"><h2>Permissões por caixa</h2>
<p>Use a conta existente da pessoa para acessar uma ou mais caixas. Não é necessário criar outro login ou aceitar um convite.</p>
<form method="post" action="/admin/grants">@csrf
<label>Pessoa<select name="user_id">@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }} · {{ $u->email }}</option>@endforeach</select></label>
<label>Filtrar caixas nesta empresa<input type="search" data-permission-filter placeholder="Nome ou endereço"></label><fieldset class="permission-boxes"><legend>Caixas que receberão estas permissões</legend>
@foreach($boxes as $b)<label class="check" data-permission-option><input type="checkbox" name="mailbox_ids[]" value="{{ $b->id }}" @checked(in_array((string) $b->id, array_map("strval", (array) old("mailbox_ids", [])), true))> {{ $b->address }}{{ $b->sensitive?' · Sensível':'' }}</label>@endforeach
</fieldset><p class="muted" data-permission-count aria-live="polite">Nenhuma caixa selecionada.</p>
<label class="check"><input type="checkbox" name="add_membership" value="1"> Vincular esta conta às empresas das caixas selecionadas</label>
<p class="muted">Marque se a pessoa ainda não pertence a essas empresas. Sua senha e seu autenticador serão mantidos. A opção não reativa acessos revogados.</p>
<label class="check"><input type="checkbox" name="can_read" value="1"> Ler mensagens</label>
<label class="check"><input type="checkbox" name="can_send" value="1"> Redigir, responder e organizar</label>
<label class="check"><input type="checkbox" name="view_sensitive" value="1"> Acesso explícito às caixas sensíveis selecionadas</label>
<label>Confirme sua senha<input name="password" type="password" required autocomplete="current-password"></label>
<button class="primary">Aplicar às caixas selecionadas</button>
<p class="muted">As permissões acima substituem as atuais somente nas caixas selecionadas. Caixas não selecionadas permanecem como estão. Uma única confirmação de senha aplica o vínculo solicitado e as permissões.</p>
</form></section>
