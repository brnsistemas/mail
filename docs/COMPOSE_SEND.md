# Composição e envio

O botão **Enviar** valida os campos, salva a edição atual, envia o arquivo selecionado, aguarda anexos aprovados e só então registra a intenção durável de envio. O texto não precisa ser salvo manualmente antes. Salvar rascunho continua disponível. Nenhum anexo em quarentena/bloqueado é liberado por esse fluxo.

Campos inválidos, conflito entre abas, sessão expirada, destinatários restritos, anexo bloqueado e falha de conexão são mostrados junto ao botão. Durante a operação, campos e botões ficam protegidos contra edições/cliques simultâneos. Requests têm timeout; resultado incerto impede repetição cega. Texto permanece no formulário, sem gravar conteúdo no localStorage. Em dúvida de persistência, copiar o texto e reabrir o rascunho para conferir. O arquivo já confirmado é removido do seletor para não duplicar no retry.

A verificação de anexo é aguardada por aproximadamente 30 segundos; se não concluir, o rascunho permanece salvo e a pessoa pode tentar enviar depois sem anexar novamente. A página acompanha o envio em fila por até um minuto e atualiza o resultado. Aceito pelo provedor não é sinônimo de entrega ou leitura.

O estado JSON exige sessão, 2FA, permissão de envio e autoria. Não fornece conteúdo, caminhos de armazenamento ou dados do scanner. A fila mantém controle de versão, idempotência, limites diários, supressões e rechecagem de permissões e anexos no worker.

## Homologação e envio normal

`BRNMAIL_RESTRICT_TEST_RECIPIENTS=true` é o padrão conservador; somente os destinatários cadastrados de teste são aceitos. O impedimento agora é explicado antes de criar a outbox. Não remove nem substitui a lista.

Após autorização explícita do responsável, `BRNMAIL_RESTRICT_TEST_RECIPIENTS=false` libera destinatários normais. `BRNMAIL_EXTERNAL_ENABLED`, domínio verificado, chave própria, permissões, supressões, anexos e limites continuam obrigatórios. Atualizar o arquivo privado de ambiente, recachear configuração e reiniciar FPM e outbound. Não alterar chaves, DNS ou outras aplicações para isso.


Sem migrations ou novas dependências. Na reversão, manter rascunhos, anexos e outbox; não reenviar itens aceitos/incertos nem restaurar banco. Voltar à release anterior retorna ao fluxo de salvamento separado e à restrição de homologação daquela versão.
