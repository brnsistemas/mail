# Convite individual e primeiro acesso

1. O master prepara empresa, produto e domínio próprio, confirmado no provedor. Demonstração local usa somente domínios `.test`.
2. Em **Convite individual**, escolhe a empresa, o e-mail da pessoa e a caixa. O padrão usa o mesmo endereço do login; identidade externa exige caixa explícita do domínio da empresa.
3. Confirma a própria senha para autorizar leitura/envio. Caixa nova é criada com o convite em uma transação, antes do compartilhamento do link. Caixa existente exige opção de acesso ao histórico; alias, caixa desativada ou sensível são recusados nesse fluxo.
4. Compartilha o link completo por canal privado que a pessoa já acessa. O sistema não o envia automaticamente por e-mail. O convite tem uso único e validade de 48 horas.
5. A pessoa escolhe e repete a senha. O aceite cria sua identidade, vínculo e concessão de leitura/envio somente na caixa registrada; segue direto ao 2FA.
6. A pessoa configura seu autenticador, guarda os códigos de recuperação privadamente e abre o webmail. A caixa preparada deve aparecer no seletor. Sem 2FA, não entra na caixa.

Criar caixa não verifica DNS nem comprova entrega. O ciclo de entrada, leitura e resposta precisa ser homologado separadamente. Não há senha por endereço nem acesso IMAP/SMTP.

## Contas já existentes e situações antigas

Use **Permissões por caixa** para uma conta existente. Não duplique identidade, gere outro convite, redefina senha ou 2FA para adicionar acesso. Masters não ganham acesso implícito às mensagens.

Convites antigos sem caixa preservam o escopo anterior. Se já foram aceitos, revise a concessão pontual no painel. Para identidade ativa sem sua caixa pessoal, existe uma operação auditada de reparo descrita em [MAILBOX_GRANTS.md](MAILBOX_GRANTS.md), exclusiva de operador autorizado; não é etapa de instalação nem correção em lote.

Um convite expirado não apaga caixas ou mensagens. Links usados não podem ser reutilizados. Um convite novo não pode reativar silenciosamente uma conta ou vínculo revogado. Reativação de credenciais é uma operação administrativa distinta, com autorização específica, que invalida a senha e o 2FA anteriores; não a use para conceder uma caixa.

## Proteção do link

O código privado vem no fragmento do link. `invite.js` o troca por comprovação na sessão mediante POST e CSRF; só depois remove o fragmento do navegador. O servidor guarda o hash verificado, associado ao convite. Recarregar ou corrigir campos não perde a comprovação na mesma sessão; outro navegador precisa do link completo.

Antes de comprovar o link, a página não exibe identidade, empresa ou formulário de senha. A aceitação revalida validade, uso único, caixa ativa, endereço e empresa sob bloqueio de banco. Falha desfaz o cadastro da transação. O código não vai para query string, localStorage ou relatórios.

## Cobertura

`InvitationMailboxTest`, `InviteFlowTest` e `AccountReactivationTest` cobrem concessão específica, duplicidade, domínio de outra empresa, reutilização, conta existente, revogação, expiração e fluxo de 2FA. `npm run qa:invite` verifica o transporte do fragmento no navegador sem serviços externos. Use sempre banco isolado e identidades sintéticas.
