# Atualização de administração e segurança — outubro de 2026

## O que mudou

A Segurança foi reorganizada para renderizar sem o erro de template. A Administração agora tem menu por área, busca, filtros e listas de 15 registros por página. Empresas, produtos, domínios, caixas, aliases, pessoas, permissões, convites, integração e atividade têm telas próprias.

Cada caixa oferece uma confirmação específica antes de bloquear, revogar acessos ou excluir. A confirmação exige sessão com 2FA, perfil master, senha atual e aceite do impacto. Visualizar o inventário não permite ler mensagens.

| Ação | Resultado |
| --- | --- |
| Bloquear | Pausa uso e envio. Recebimentos podem ficar pendentes; o DNS não muda. |
| Desbloquear | Reativa a caixa com as concessões que ainda existirem. Confira eventos retidos. |
| Revogar acessos | Retira leitura/envio de todos os operadores dessa caixa e expira convites pendentes. Mantém o recebimento ativo e o histórico. |
| Excluir | Guarda o cadastro em **Estado → Excluídas**, bloqueia uso e revoga acessos. Preserva endereço, mensagens e anexos. |
| Restaurar | Recupera o cadastro bloqueado. Não devolve permissões; conceda acesso e desbloqueie separadamente. |

Não há exclusão física de mensagens ou liberação do endereço para outra caixa. Ações não cancelam mensagens já aceitas pelo provedor. Revogar uma caixa não remove a conta humana nem o acesso a outras caixas.

## Atualizar uma instalação existente

1. Registre a versão atual, guarde backup criptografado e confira sua restauração isolada. Preserve `.env`, chave da aplicação, dados e armazenamento privado.
2. Obtenha esta versão do repositório público. Não copie configurações de outra instalação. Use uma release nova e confira diferenças locais antes de trocar código.
3. Instale as dependências do `composer.lock`, com os comandos do seu perfil de VPS. Execute `php artisan migrate --force` na instalação correta. A migration adiciona apenas `mailboxes.deleted_at`, inicialmente vazio; não revoga contas existentes.
4. Atualize o cache de configuração e views e ative a release. Reinicie somente os processos da sua instalação para que carreguem o novo código, respeitando os envios em andamento. Consulte o guia de VPS para os nomes do Compose ou dos serviços.
5. Abra Segurança e cada seção administrativa com seu administrador. Confirme busca, paginação e confirmação/cancelamento das ações. Teste alterações efetivas em caixas fictícias, sem bloquear caixas reais por engano.
6. Confira filas, scanner e logs sanitizados. Não considere HTTP 200 uma prova de entrega de e-mail.

O modelo de imagem PHP para VPS agora inclui suporte GD/WebP, também necessário para executar os testes de imagens do projeto.

## Reversão que preserva dados

Mantenha o backup e a release anterior. Não use `migrate:fresh`, não apague volumes e não restaure sobre banco em uso. A coluna adicionada pode permanecer em uma reversão de código. Caixas excluídas ficam com `active=false` e concessões retiradas; uma versão antiga não terá a interface de restauração. Prefira corrigir para a frente ou restabelecer a versão atual antes de gerenciar essas caixas.

## Verificações e limites

A versão pública passou em 268 testes de backend, com 1.636 asserções, em MySQL novo e rede isolada. O teste que exige ClamAV real não foi executado nesta rodada. A interface compartilhada foi validada em telas de 390/1440 pixels, temas claro/escuro, com ações em dados fictícios. Envio e recebimento externos não foram repetidos: esta atualização não altera DNS ou transporte.

Esta edição pública continua sendo o webmail com Resend descrito no README. Não inclui o gateway privado de IMAP/SMTP nem a integração MCP de uma instalação particular. Nenhum usuário, domínio real, credencial, assinatura, backup, log ou histórico Git de instalação privada acompanha a atualização.
