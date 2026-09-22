# Leitor de HTML e pasta Spam

## Leitura formatada

O recebimento preserva o texto original e salva uma versão sanitizada do HTML, cifrada em `messages.body_html`. A migração já existente contém a coluna; esta mudança não altera o esquema.

Quando houver HTML, o webmail apresenta títulos, tabelas, cores e botões, com opção **Ver versão em texto**. Imagens e fontes externas não são carregadas. Formulários, scripts, elementos ativos, SVG/MathML e estilos globais são removidos. Apenas elementos e atributos explícitos e estilos inline de apresentação são aceitos. CSS com URLs, funções de código, escapes e posicionamento é recusado. O resultado é sanitizado novamente a cada leitura.

O conteúdo é servido por `/messages/{id}/html` depois de autenticação, versão de credencial, 2FA e permissão efetiva de leitura. O master não tem exceção. O iframe não recebe `allow-scripts`, `allow-same-origin`, formulários ou navegação do topo. CSP própria bloqueia conexões, mídia, imagens, scripts e frames internos. Só esse endpoint permite enquadramento pela própria origem; o restante da aplicação mantém `X-Frame-Options: DENY` e sua CSP original.

Links HTTP/HTTPS/mailto abrem somente após ação do usuário, em outra aba, sem `opener` ou referrer. Links relativos e protocolos ativos são removidos. A formatação não garante que um remetente ou destino seja confiável. Não se deve clicar em links de convite/senha para testar a aparência.

HTML ausente, sem conteúdo permitido ou maior que 512 KiB usa a versão em texto. Nem todo CSS de e-mail é suportado: folhas de estilo no cabeçalho, imagens, fontes remotas e layouts ativos não são reproduzidos. Não prometer fidelidade visual idêntica a todos os clientes.

Referências consultadas: [Symfony HTML Sanitizer](https://symfony.com/doc/current/html_sanitizer.html), [sandbox de iframe](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe), [HTML/text do Resend Receiving](https://resend.com/docs/api-reference/emails/retrieve-received-email).

## Mensagens anteriores à correção

Não se recupera formatação a partir do texto. Quando o original ainda estiver disponível no Resend, o operador do servidor pode usar `brnmail:restore-html ID_DA_MENSAGEM --operator=ID_DA_PESSOA_AUTORIZADA`, para IDs previamente selecionados e autorizados (máximo 20 por execução).

O comando exige concessão efetiva de leitura, consulta o original pelo ID já armazenado e confere o texto antes de persistir o HTML sanitizado. Não clica em links, reenvia mensagens, cria convites ou modifica assunto, estado, pasta, leitura, versão ou permissões. Não substitui HTML já existente; gera auditoria sem conteúdo. Uma falha interrompe a lista e preserva alterações anteriores já concluídas; repetir IDs concluídos é seguro.

## Spam

Spam é uma pasta de cada caixa, com contador e pesquisa seguindo o isolamento existente. **Marcar como spam** move somente a mensagem recebida selecionada; **Não é spam** devolve à Entrada. Requer a mesma permissão de organizar/enviar usada para Arquivar e Lixeira. Usuários somente de leitura não podem mover mensagens. Mensagens enviadas, rascunhos e envios em fila não são classificados como spam.

A classificação é **manual**, sem filtro automático, bloqueio de remetentes, aprendizado ou exclusão automática. Não alterar MX, Resend, chaves ou assinatura do webhook para criar essa pasta.

## Testes e publicação

- Unitários: template com botão, preservação de URL, HTML malformado, protocolos ativos, scripts, iframes, formulários, tracking e CSS hostil; limites/fallback.
- Funcionais: entrada HTML cifrada/idempotente, endpoint protegido, isolamento entre caixas/master/revogação/2FA, restauração pontual, ida e volta de Spam, leitura sem movimentação, recusa de mensagens de saída.
- Navegador CI: formulário de login + 2FA sintéticos, iframe em 390/1440 px, estilo computado do botão, clique para destino local fictício, ausência de requisições de tracking, `opener` nulo, ida e volta de Spam.

Na publicação, reiniciar PHP-FPM e o worker inbound do BRN Mail para que novos recebimentos usem o código novo; preservar serviços de outros projetos. Nenhuma migration. Na reversão, preservar `body_html` e mensagens em `folder=spam`: voltar ao código anterior não deve apagar dados; a interface antiga não mostra Spam nem HTML. Planejar a restauração da versão corrigida para voltar a exibi-los, sem mover mensagens de terceiros silenciosamente.
