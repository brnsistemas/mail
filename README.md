# BRN Mail

Webmail em Laravel para organizar mensagens por empresa, produto, domínio e caixa. O transporte de envio e recebimento externo usa a API e os webhooks do Resend.

Esta distribuição contém código, exemplos fictícios e instruções de instalação. Não contém contas prontas, credenciais, mensagens, dados de clientes ou configuração de um servidor em funcionamento. Cada instalação precisa de infraestrutura e configuração próprias.

## O que faz

- Uma conta humana pode alternar entre as caixas às quais recebeu acesso explícito.
- Empresas e caixas têm permissões independentes; ser master não concede leitura automática.
- Caixa de entrada, rascunhos, envio, resposta, arquivamento, lixeira, busca e responsável pela conversa.
- Autenticação com senha, segundo fator TOTP e códigos de recuperação.
- Recebimento por webhook assinado, processamento em filas, idempotência e reconciliação.
- Anexos privados e criptografados, com validação de formato e varredura antes da liberação.

Não é um servidor SMTP/IMAP. A senha pertence à pessoa que acessa o webmail, não a cada endereço de e-mail. Criar uma caixa no painel não configura o DNS nem comprova recebimento externo.

## Requisitos

PHP 8.4 com Composer 2, MySQL 8.4, Redis, ClamAV e HTTPS para uso externo. Para o ambiente local, o Compose fornece MySQL e Redis e tem um perfil opcional de scanner. O runtime local usa `pcntl`. Node.js 22 é utilizado pelos testes de interface; os assets do webmail já estão em `public/`, sem etapa obrigatória de Vite.

## Primeiro uso local

Execute em uma cópia nova e isolada. Confira se as portas 8876, 33461, 16381 e 13310 estão disponíveis; não substitua serviços já existentes.

```sh
git clone https://github.com/brnsistemas/mail.git
cd mail
php scripts/prepare-local.php
composer install --no-interaction --prefer-dist
docker compose up -d mysql redis
php artisan migrate
php artisan db:seed --class=LocalDemoSeeder
php artisan db:seed --class=LocalWorkspaceDemoSeeder
php scripts/run-local.php
```

Abra <http://127.0.0.1:8876>. O login local exibe a identidade e a senha fictícias de demonstração quando `BRNMAIL_LOCAL_DEMO=true`. Configure o autenticador de teste para essa instalação. Nunca use a identidade de demonstração em produção.

`prepare-local.php` cria diretórios privados, gera segredos locais e preserva um `.env` existente. O envio externo começa **desativado** e o transporte local marca os envios como simulados. Esse modo não comprova entrega de e-mail.

Para testar anexos com o scanner real:

```sh
docker compose --profile scanner up -d scanner
```

Verifique saúde e assinaturas do ClamAV. O scanner consome memória adicional; anexos ficam bloqueados se ele estiver indisponível. Não habilite um scanner de teste em produção.

## Instalação em VPS

[Abra o guia completo de instalação em VPS](docs/INSTALACAO_VPS.md): Linux, Docker, HTTPS, banco, filas, scanner, Resend, DNS e backup, com modelos de configuração incluídos.

## Configuração externa

Siga [o manual](index.md) e [a configuração do Resend](docs/CONFIGURACAO.md). Cadastre seu próprio domínio e credenciais, instale os workers e o scheduler, configure HTTPS e teste backup/restauração. O webhook é `/webhooks/resend` no host da sua instalação.

Esta versão mantém uma lista de destinatários autorizados para o envio real. Habilitar o provedor não remove essa barreira: o operador cadastra os destinatários de homologação no painel. Não é um produto de disparo em massa.

Convites de acesso são compartilhados por link manual. A integração de aplicações externas e a entrega automática de convites não são fornecidas como uma API pública pronta.

## Testes

O workflow [BRN Mail QA](https://github.com/brnsistemas/mail/actions) usa MySQL descartável, dados sintéticos e bloqueia chamadas externas inesperadas. Inclui testes de backend, concorrência e interface. Execute os testes de banco somente no banco isolado `brnmail_test`, nunca contra dados reais.

```sh
composer validate --strict
vendor/bin/pint --test
php artisan test --compact
php tests/Concurrency/run.php
npm ci
npm run qa:auth-ui
npm run qa:invite
npm run lint
```

Os testes de navegador requerem o servidor local, os dois seeders e Chromium do Playwright. Testes do scanner real exigem o serviço ativo; um teste ignorado não equivale a aprovação. A CI não testa entrega pública de mensagens.

## Limites e licença

Anexos: até 5 arquivos, 10 MiB por arquivo e 20 MiB no total. Vídeos são bloqueados. O antivírus reduz riscos, mas não garante detectar todo conteúdo malicioso.

O campo de licença do projeto permanece `proprietary`. As dependências mantêm suas próprias licenças e atribuições. A disponibilidade do código neste repositório não transforma as dependências em propriedade do projeto.
