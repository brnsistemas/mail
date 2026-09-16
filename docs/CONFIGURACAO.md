# Configuração do transporte externo

O Resend transporta as mensagens; a aplicação mantém o webmail, dados e permissões. Não há serviço IMAP/SMTP de caixas nesta distribuição.

## Preparar a instalação

Configure HTTPS com certificado válido, MySQL, Redis, scanner e armazenamento privado. O document root é `public/`. Não exponha `.env`, backups ou `storage/`; não crie link público para anexos. Instale as dependências pelo lock e configure os processos sob usuários apropriados da própria instalação.

Proteja `APP_KEY`: ela participa da criptografia de dados. Defina `APP_DEBUG=false` e mantenha credenciais em configuração privada ou no cofre criptografado do painel. Configure o primeiro administrador conforme `index.md`.

## Domínio e Resend

1. Entre na organização correta do Resend e cadastre ou reutilize apenas seu domínio.
2. Registre DNS atual e destinatários existentes. Se houver recebimento ativo, obtenha aprovação para migração antes de substituir MX.
3. Consulte os registros exatos exibidos pelo provedor. Preserve registros DKIM/SPF existentes, principalmente os que outros sistemas usam. Não copie destinos MX de outro projeto.
4. Para receber, habilite Receiving e publique o MX indicado pelo Resend somente após o plano de migração.
5. Crie uma credencial dedicada com o acesso necessário a envio e consulta de recebidos. Restrições e alcance devem ser avaliados na conta; não reutilize chaves de outra aplicação.
6. Configure o webhook HTTPS `https://SEU-HOST/webhooks/resend`, com o segredo de assinatura fornecido pelo provedor. Não use o segredo sintético gerado para demonstração local.
7. Os eventos processados incluem `email.received` e estados de entrega. Confira os tipos suportados no controller e na documentação atual antes de ativar o endpoint.
8. Cadastre a empresa, o produto, o domínio, o ID do domínio no provedor e as caixas. A consulta de status exige a autenticação do operador.
9. Defina os destinatários de teste autorizados no painel. Configure `BRNMAIL_TRANSPORT=resend`, desative a demonstração local e habilite envio externo somente quando a preparação estiver concluída.

Documentação oficial: [Receiving](https://resend.com/docs/dashboard/receiving/introduction), [Webhooks](https://resend.com/docs/webhooks/introduction) e [Domínios](https://resend.com/docs/dashboard/domains/introduction). Consulte as opções atuais na conta; não contrate um plano automaticamente.

## Processamento e validação

Workers devem consumir as filas `inbound`, `outbound` e `attachments`; o scheduler executa os comandos de bombeamento e recuperação registrados em `routes/console.php`. O launcher `scripts/run-local.php` é exclusivo do localhost e recusa produção. Use um supervisor próprio na implantação externa.

O webhook valida assinatura sobre o corpo original, tolerância de timestamp e duplicidade. O evento é persistido antes do trabalho assíncrono. O recebimento resolve destinatários para caixas cadastradas; não é um catch-all público. A reconciliação é disponibilizada pelo comando `brnmail:reconcile`; consulte `php artisan help brnmail:reconcile` antes de executá-lo.

Para cada caixa, mande uma mensagem de um endereço externo autorizado, confira armazenamento e leitura, responda pelo webmail e confirme chegada na caixa externa. Confira From, To, Reply-To, encadeamento e SPF/DKIM/DMARC nos cabeçalhos. Registre horários e identificadores sem corpo de mensagens nem segredos. Eventos de entrega não provam leitura humana.

Teste anexos limpos e falhas de scanner de forma controlada. Sem varredura aprovada, o arquivo deve continuar bloqueado. Teste backup/restauração em destino separado antes de usar dados reais.

## Limites atuais

A lista de destinatários autorizados é aplicada ao envio real. As caixas não fornecem credenciais IMAP/SMTP para conectar outros aplicativos. Não há contrato público de API para integração de sistemas nem automação pronta de convites; esses fluxos precisam de implementação própria e homologação.
