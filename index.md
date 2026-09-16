# Manual de instalação e operação

Leia este arquivo antes de adicionar empresas, domínios, caixas ou pessoas.

## Modelo

**Empresa → produto → domínio → caixa.** O usuário é uma identidade humana separada. Uma pessoa pode operar várias caixas pelo seletor, desde que tenha vínculo ativo com a empresa e concessão explícita por caixa.

Antes de criar algo, confira se já existe. Não crie outra conta nem envie convite para vincular uma pessoa já cadastrada. Use **Permissões por caixa** para adicionar o vínculo/concessão pelo mecanismo existente, confirmando a identidade. Não troque senha ou segundo fator. Ser master não autoriza ler mensagens sem concessão.

## Novo domínio

1. Identifique empresa, produto, domínio e responsável. Inventarie endereços, aliases e grupos atuais.
2. Confirme qual provedor recebe os e-mails hoje. Leia MX, SPF, DKIM e DMARC e guarde os valores anteriores privadamente.
3. Faça um plano para todos os destinatários do domínio antes de trocar MX. Alterar MX afeta o domínio inteiro, não apenas uma caixa.
4. Preserve registros de envio existentes, inclusive subdomínios. Não duplique SPF e não enfraqueça DMARC.
5. Configure Resend, webhook, DNS e filas conforme [CONFIGURACAO.md](docs/CONFIGURACAO.md).
6. Crie ou reutilize as caixas e conceda acesso individual. Não habilite catch-all por conveniência.
7. Faça testes reais apenas com destinatários autorizados. Confirme recebimento na caixa correta, resposta e cabeçalhos externos.

## Primeiro administrador

Em uma instalação nova, vazia e separada da demonstração, configure `APP_ENV=production`, `APP_DEBUG=false`, `BRNMAIL_LOCAL_DEMO=false`, `BRNMAIL_TRANSPORT=resend` e mantenha `BRNMAIL_EXTERNAL_ENABLED=false` durante o cadastro.

Defina `APP_URL` e `BRNMAIL_BOOTSTRAP_URL` com o mesmo endereço HTTPS da sua instalação, sem usuário, senha, caminho, query ou fragmento. O segundo campo confirma explicitamente o destino permitido para o primeiro cadastro. Não há host pré-autorizado nesta distribuição.

O comando usa o MySQL 8.4 isolado em `127.0.0.1:33461`, banco `brnmail`, sem DB_URL ou réplicas. Tenha `APP_KEY` válida, execute migrations e rode interativamente:

```sh
php artisan brnmail:bootstrap-master
```

Informe nome, e-mail pessoal de login e senha privadamente. O comando recusa banco que já possua usuários e não redefine identidades. Entre no HTTPS e configure TOTP e recuperação. Nenhuma caixa é criada ou concedida automaticamente.

## Operação

- Separe workers `inbound`, `outbound` e `attachments` e mantenha o scheduler Laravel ativo.
- Configure limites, timeout, reinício e logs privados para os processos da própria instalação.
- Use o cofre do painel ou configuração privada para chaves; nunca Git, links ou screenshots.
- Monitore falhas, supressões, eventos pendentes e scanner. Não reenvie cegamente mensagens de estado incerto.
- Guarde backup criptografado fora do servidor e teste restauração isolada. Preserve a chave de criptografia por canal seguro separado.
- Registre release, mudanças, resultados e reversão em documentação privada da sua instalação.

## Critérios de aceite

Distinguir **simulado**, **aceito pelo provedor**, **entregue ao servidor de destino** e **recebido e acessível ao usuário**. Domínio verificado ou HTTP 200 não comprova ida e volta.

Teste permissões entre empresas e caixas, revogação durante filas, assinatura inválida, repetição de webhook, recuperação de worker, anexos bloqueados e restauração. Testes negativos usam ambientes controlados. Nunca envie EICAR a caixas externas.

Reverter uma migração exige restaurar os MX anteriores e manter a consulta aos dois provedores durante a propagação. Preservar banco, mensagens e anexos; mudar DNS não recupera automaticamente mensagens que chegaram ao outro provedor.
