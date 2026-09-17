# Instalação do BRN Mail em VPS

**Guia da distribuição pública:** <https://github.com/brnsistemas/mail>  
**Revisão do documento:** 16/09/2026. Base funcional: `fd8f79d` ou versão posterior compatível.

**Instalação guiada por agente:** copie o [prompt completo para Codex ou Claude Code](PROMPT_INSTALACAO_ASSISTIDA.md). Ele usa este guia e conduz a escolha da VPS, acesso, instalação, usuários e homologação, inclusive quando você ainda não tem experiência com servidores.

Este guia instala o BRN Mail em uma **VPS Linux x86_64 de qualquer provedor**, com Docker Engine e Compose. O exemplo usa Ubuntu Server 24.04 LTS. Em Debian, use o instalador oficial do Docker específico para Debian; os contêineres e a configuração da aplicação são os mesmos. ARM, Windows, hospedagem compartilhada e VPS sem suporte a Docker não estão validados neste procedimento.

Você terá webmail HTTPS, MySQL, Redis, três filas, scheduler e ClamAV. **O Resend faz o transporte externo dos e-mails.** Não é necessário instalar Postfix, Dovecot ou outro servidor SMTP/IMAP; as portas 25, 465, 587 e 993 não fazem parte desta instalação.

> A instalação não fornece domínio, assinatura de provedor ou caixas prontas. A versão mantém uma lista de destinatários autorizados para envio. Instalar não remove essa restrição nem comprova entrega real. Este documento não é um atestado de que a sua VPS já foi homologada.

## Sumário

1. [Requisitos e informações](#1-requisitos-e-informações)
2. [VPS, firewall e Docker](#2-vps-firewall-e-docker)
3. [Código e arquivos](#3-código-e-arquivos)
4. [Configuração privada](#4-configuração-privada)
5. [Dependências e banco](#5-dependências-e-banco)
6. [Primeiro administrador](#6-primeiro-administrador)
7. [HTTPS e processos](#7-https-e-processos)
8. [Resend, domínio e DNS](#8-resend-domínio-e-dns)
9. [Caixas e acesso](#9-caixas-e-acesso)
10. [Homologação real](#10-homologação-real)
11. [Backup e restauração](#11-backup-e-restauração)
12. [Atualização e reversão](#12-atualização-e-reversão)
13. [Problemas comuns](#13-problemas-comuns)
14. [Cobertura e referências](#14-cobertura-e-referências)

## 1. Requisitos e informações

| Item | Referência inicial |
|---|---|
| Sistema | Ubuntu 24.04 LTS, Linux x86_64, acesso SSH com sudo |
| CPU e memória | Comece com 4 vCPU e 8 GiB de RAM para uma homologação pequena; 12 GiB dão mais margem. Medir o uso real antes de dimensionar produção |
| Disco | SSD, pelo menos 40 GiB como ponto de partida; ampliar conforme mensagens, anexos, bases do scanner e retenção |
| Software | Docker Engine com plugin `docker compose`, Git, Python 3 e cliente DNS |
| Hostname do painel | Um nome próprio, por exemplo `mail.seudominio.com` |
| Domínio de e-mail | Pode ser `seudominio.com`; não precisa ser igual ao hostname do painel |
| Provedor | Conta Resend, acesso ao DNS e destinatário externo controlado para testes |
| Operação | Destino de backup fora da VPS, cofre de segredos e monitoramento |

Esses números são uma estimativa inicial, não um benchmark. Só o scanner e suas atualizações podem consumir vários GiB. Os limites por contêiner do modelo não reservam memória antecipadamente nem garantem capacidade conjunta. Não use uma VPS de 1 GiB para esta combinação de serviços. [Requisitos do ClamAV](https://docs.clamav.net/Introduction.html).

Escolha os valores antes de executar:

```text
PAINEL:              mail.seudominio.com
DOMÍNIO DAS CAIXAS:   seudominio.com
IP PÚBLICO DA VPS:   fornecido pelo seu provedor
LOGIN DO OPERADOR:   um e-mail que você já controla
DESTINATÁRIO DE QA:  uma conta externa controlada por você
```

Substitua exemplos pelos seus dados. Não use domínio ou credencial de outra instalação. Acesso root/Docker permite ler dados da VPS: conceda-o somente a administradores autorizados.

## 2. VPS, firewall e Docker

### 2.1 Verifique o destino

Os comandos seguintes são executados **na sua VPS**, por SSH. Para os blocos administrativos, entre com `sudo -i`. Não cole os comandos no terminal do computador pessoal sem estar conectado à VPS.

```sh
cat /etc/os-release
uname -m
free -h
df -h
ss -lntup
```

Este exemplo reserva TCP **80, 443, 19000, 33461, 16381 e 13310**. Os quatro últimos são exclusivos de loopback. Se alguma porta estiver ocupada, não mate processos nem substitua serviços: ajuste o projeto de implantação primeiro. O cadastro inicial usa por padrão MySQL em `127.0.0.1:33461`, banco `brnmail`. Em uma adaptação revisada para coexistência, uma instância nova pode usar outra porta: defina `DB_PORT` e `BRNMAIL_BOOTSTRAP_DB_PORT` com a mesma porta livre entre 1024 e 65535. O nome do banco continua `brnmail` e o host precisa continuar `127.0.0.1`; não basta usar `mysql:3306`. Ajuste também a porta do serviço MySQL ou do mapeamento Docker, sem alterar a instância existente.

Não é obrigatório usar uma VPS vazia. Entretanto, o bloco de HTTPS abaixo assume 80/443 livres. Em uma VPS com Nginx/Caddy/Traefik existentes, não inicie o serviço `web` deste exemplo; integre o novo virtual host ao proxy existente, com FastCGI em `127.0.0.1:19000` e caminho PHP `/srv/brnmail/public/index.php`. Preserve os sites e certificados já existentes. Essa adaptação exige revisão própria.

Se o servidor já oferece PHP 8.4, Nginx e MySQL e a escolha for uma instalação nativa, consulte o [complemento de coexistência](INSTALACAO_COEXISTENTE.md). Ele descreve banco realmente novo, processos exclusivos, portas alternativas e critérios de aceite. Esse perfil deve ser registrado separadamente do teste do roteiro Docker.

### 2.2 Acesso e rede

No firewall do provedor, permita SSH somente das origens administrativas necessárias e TCP 80/443 para o painel e validação do certificado. Mantenha MySQL, Redis, PHP-FPM e ClamAV inacessíveis pela Internet. A saída deve permitir DNS, sincronização de hora e HTTPS para os provedores/atualizações.

O Docker pode publicar portas por fora das regras usuais do UFW. Por isso o modelo usa bind explícito em `127.0.0.1`, além do firewall do provedor. Não altere esses binds para `0.0.0.0`. [Docker e firewall](https://docs.docker.com/engine/install/ubuntu/#firewall-limitations).

### 2.3 Instale Docker

Em Ubuntu 24.04 sem Docker instalado, siga a seção **Install using the apt repository** da [documentação oficial](https://docs.docker.com/engine/install/ubuntu/). Em [Debian, use esta página](https://docs.docker.com/engine/install/debian/). Não misture os repositórios das distribuições nem remova instalações existentes sem avaliar o impacto.

Instale também os utilitários e confirme o Docker:

```sh
apt-get update
apt-get install -y git python3 dnsutils ca-certificates curl nano
systemctl enable --now docker
docker version
docker compose version
```

O PHP e o Composer serão executados na imagem da aplicação. Não é necessário instalar Node.js ou compilar assets para rodar o webmail.

## 3. Código e arquivos

Use um diretório **novo**. Se `/opt/brnmail` já existir, confira se é outra instalação; não sobrescreva.

```sh
install -d -m 0755 /opt/brnmail
git clone https://github.com/brnsistemas/mail.git /opt/brnmail/app
cd /opt/brnmail/app
git status --short
git rev-parse HEAD
install -d -m 0755 /opt/brnmail/deploy
cp docs/vps/Dockerfile docs/vps/php.ini docs/vps/php-fpm.conf \
   docs/vps/Caddyfile docs/vps/compose.yaml docs/vps/.dockerignore \
   docs/vps/inicializar.py \
   /opt/brnmail/deploy/
chmod -R a+rX /opt/brnmail/app
```

Registre o commit escolhido. Não rode seeders de demonstração nem `scripts/prepare-local.php` na VPS de produção.

```text
/opt/brnmail/
├── app/                 código público; leitura para os processos
├── deploy/              Compose, PHP e Caddy
├── private/             ambiente, senhas e chave do backup; fora do Git
└── data/
    ├── vendor/          dependências do lock
    ├── bootstrap-cache/ cache privado do Laravel
    └── storage/         mensagens/anexos privados, sessões e logs
```

MySQL, Redis, certificados e assinaturas do ClamAV usam volumes Docker nomeados. Não execute `docker compose down -v`, `docker volume prune` ou remoção global de volumes.

**Use sempre `/opt/brnmail/deploy` nos comandos Compose deste guia.** O `compose.yaml` da raiz do repositório serve para demonstração local e não é o arquivo de produção.

## 4. Configuração privada

### 4.1 Gere o ambiente uma única vez

Defina **o hostname real do painel**, sem `https://` nem barras. Repita este `export` se abrir uma nova sessão SSH:

```sh
export BRNMAIL_HOST=mail.seudominio.com
```

Execute o inicializador abaixo. Ele gera senhas aleatórias, sem exibi-las, e recusa configuração privada existente. Não o execute novamente para “corrigir” uma instalação: isso trocaria as chaves necessárias para ler dados antigos.

O [inicializador incluído](vps/inicializar.py) gera APP_KEY, senhas distintas de banco/Redis e chave de backup, prepara diretórios e mantém o transporte externo bloqueado. Ele deve ser executado como root e exige o hostname na variável acima.

```sh
python3 /opt/brnmail/deploy/inicializar.py
cd /opt/brnmail/deploy
docker compose config --quiet
```

Os arquivos de MySQL/Redis precisam ser lidos pelos usuários internos das imagens. Seu diretório pai no host é root:root `0700`; apenas o Docker e root conseguem alcançá-los no host. O ambiente Laravel e a chave de backup são `0600`, UID/GID 33. O ambiente entra por mount somente leitura nos processos PHP; a chave de backup é montada somente na CLI. Não abra o diretório privado para outros usuários e não publique seu conteúdo.

Guarde **APP_KEY e backup.key**, separadamente dos backups, em um cofre privado. Não imprima o `.env`, não inclua segredos em argumentos, tickets, chat ou logs. Perder a APP_KEY pode tornar mensagens e credenciais armazenadas ilegíveis.

### 4.2 Entenda o modelo de rede

Os processos PHP usam `network_mode: host` em Linux para atender ao contrato de MySQL loopback do cadastro inicial. Isso compartilha a rede da VPS: é adequado apenas a um host administrado por pessoas confiáveis. O FPM escuta exclusivamente em `127.0.0.1:19000`; MySQL, Redis e ClamAV têm portas publicadas somente em loopback. Caddy expõe 80/443. [Rede host no Docker](https://docs.docker.com/engine/network/drivers/host/).

## 5. Dependências e banco

```sh
cd /opt/brnmail/deploy
docker compose build cli
docker compose pull mysql redis scanner web
docker compose run --rm cli composer install \
  --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins
docker compose run --rm cli composer validate --strict
docker compose run --rm cli composer check-platform-reqs --no-dev
docker compose run --rm cli php artisan package:discover --ansi
docker compose up -d --wait --wait-timeout 180 mysql redis
```

As versões de dependências PHP vêm de `composer.lock`; não rode `composer update` no servidor. Os modelos usam famílias de tags oficiais para imagens. Para uma release reproduzível, após testar, registre os digests e fixe as imagens aprovadas; uma tag pode receber atualizações futuras.

Confirme que este é **o banco novo** antes da migration:

```sh
docker compose run --rm cli php artisan migrate:status
docker compose run --rm cli php artisan migrate --force
```

Na primeira execução, `migrate:status` pode informar que ainda não há tabela de migrations. `migrate --force` cria o esquema sem apagar dados. Nunca use `migrate:fresh`, `db:wipe` ou restauração sobre banco existente como atalho.

## 6. Primeiro administrador

Mantenha `BRNMAIL_EXTERNAL_ENABLED=false`. Confirme `APP_ENV=production`, `APP_DEBUG=false`, demonstração desativada e as duas URLs HTTPS iguais.

```sh
cd /opt/brnmail/deploy
docker compose run --rm cli php artisan brnmail:bootstrap-master
```

Execute em terminal interativo. Digite nome, e-mail de login já controlado por você e uma senha forte de 14 a 72 bytes. A senha é solicitada de forma oculta; não a passe na linha de comando.

Esse comando exige MySQL **8.4**, recusa MariaDB, usa loopback e banco `brnmail` (porta 33461 por padrão, ou outra explicitamente confirmada em `BRNMAIL_BOOTSTRAP_DB_PORT`) e não funciona se já houver usuários. Se uma conta já existe, faça login nela. Não recrie master, não redefina senha e não envie convite para resolver um vínculo de caixa.

O master inicial **não recebe caixas nem acesso a mensagens automaticamente**. Depois do HTTPS, ele configura o autenticador e guarda os códigos de recuperação privadamente.

## 7. HTTPS e processos

### 7.1 DNS do painel

No DNS, crie o registro **A do hostname do painel** apontando para o IPv4 da VPS. Só crie AAAA se IPv6 realmente estiver funcionando na VPS e no firewall. Um AAAA incorreto pode quebrar acesso e emissão do certificado.

No primeiro teste, use DNS direto, sem proxy CDN, para que a validação HTTPS chegue à VPS. Depois, qualquer inclusão de Cloudflare proxy exige TLS Full (strict), revisão de cabeçalhos/proxy confiável e novo teste. Não use Flexible.

**Esse registro A não configura o recebimento de e-mails.** O MX do domínio será tratado separadamente na seção 8.

### 7.2 Valide e suba o webmail

```sh
cd /opt/brnmail/deploy
docker compose run --rm cli php-fpm -t
docker compose run --rm --no-deps web caddy validate --config /etc/caddy/Caddyfile
docker compose run --rm cli php artisan config:cache
docker compose run --rm cli php artisan view:cache
docker compose up -d scanner
docker compose up -d fpm inbound outbound attachments scheduler web
docker compose ps
```

`route:cache` não é necessário neste procedimento. O `storage/` é privado; não rode `storage:link`.

Caddy solicita e renova o certificado automaticamente, desde que o hostname aponte para a VPS e 80/443 estejam acessíveis. Certificados ficam em volume persistente. [HTTPS automático](https://caddyserver.com/docs/automatic-https).

O serviço `scanner` usa o entrypoint oficial, com **clamd e FreshClam**. O arquivo local `infra/clamd-local.conf` não é usado em produção: ele desativa uma verificação periódica e foi preparado para QA. Preserve a atualização das assinaturas. O primeiro início pode demorar; confira a saúde antes de testar anexos. [Imagem oficial do ClamAV](https://docs.clamav.net/manual/Installing/Docker.html).

```sh
curl -fsS "https://$BRNMAIL_HOST/up" >/dev/null
curl -I "https://$BRNMAIL_HOST/login"
docker compose exec scanner clamdscan --version
docker compose ps
ss -lntp
```

`/up` apenas mostra que a aplicação responde; não comprova banco, workers ou entrega. Abra `https://SEU-HOST/login`, entre e configure o TOTP. URLs úteis: `/mail` para caixas; `/admin` para administração; `/security` para segurança da conta.

Não execute os testes do repositório no banco desta VPS. PHPUnit e os testes de concorrência pertencem a ambientes descartáveis.

## 8. Resend, domínio e DNS

### 8.1 Inventário e credenciais

Confirme a conta/organização do Resend e quem administra o domínio. Liste caixas, aliases e grupos que já recebem mensagens. Registre os valores atuais, prioridade e TTL:

```sh
dig +short MX seudominio.com
dig +short TXT seudominio.com
dig +short TXT _dmarc.seudominio.com
dig +short NS seudominio.com
```

Confira também os seletores DKIM e o subdomínio de envio indicados pelo provedor. Se existir recebimento anterior, migrar o MX afeta **todos os endereços do domínio**. Não prossiga sem mapear os destinatários e aprovar a mudança; MX de provedores diferentes não dividem destinatários por caixa.

No Resend, cadastre ou reutilize o domínio. Crie uma chave dedicada que permita enviar e consultar recebidos. Confirme o alcance efetivo: uma chave Full access pode abranger mais de um domínio da organização. Não copie a chave de outra aplicação e não contrate plano automaticamente.

Em `/admin`, salve a chave no cofre do BRN Mail. Informe somente seus destinatários externos de homologação na lista autorizada. O administrador confirma a própria senha atual; não é senha do domínio nem da caixa.

### 8.2 Webhook

Crie o endpoint HTTPS no Resend:

```text
https://SEU-HOST/webhooks/resend
```

Selecione os eventos suportados por esta versão:

```text
email.received
email.sent
email.delivered
email.bounced
email.complained
email.failed
email.delivery_delayed
```

Salve o segredo de assinatura do webhook no cofre do painel. Não use um segredo gerado para demonstração. O aplicativo valida assinatura no corpo original e timestamp, persiste o evento e responde **202**; o scheduler/worker faz o processamento depois. Uma resposta 202 isolada não prova entrega ou armazenamento de mensagem. [Webhooks do Resend](https://resend.com/docs/webhooks/introduction).

### 8.3 Ative a integração de forma controlada

No arquivo privado, altere apenas `BRNMAIL_EXTERNAL_ENABLED` para `true`, mantendo o transporte `resend` e a demonstração desativada:

```sh
nano /opt/brnmail/private/brnmail.env
cd /opt/brnmail/deploy
docker compose run --rm cli php artisan config:cache
docker compose restart fpm inbound outbound attachments scheduler
```

Faça isso depois de cadastrar credenciais, webhook e lista de teste. A consulta de status do domínio também depende desse gate; com ele falso, a consulta ao Resend é recusada. Campos vazios no cofre preservam chaves já salvas: editar apenas o `.env` não substitui uma chave preenchida no cofre.

### 8.4 Registros do domínio de e-mail

**Antes de trocar MX, conclua o cadastro de todas as caixas e concessões da seção 9, confirme o webhook e os processos ativos.** Se mensagens chegarem antes de o destinatário existir no BRN Mail, elas não serão roteadas para uma caixa conhecida. A ordem segura é preparar o destino inteiro, mudar o recebimento e então testar; não interrompa o provedor anterior durante essa preparação.

Use **os nomes, valores e prioridades exibidos na sua conta Resend**. Eles dependem do domínio/região; não há um MX universal para copiar deste guia.

| Finalidade | O que configurar |
|---|---|
| Assinatura de envio | DKIM indicado pelo Resend |
| Retorno técnico do envio | SPF/MX do subdomínio de envio indicado pelo provedor |
| Recebimento das caixas | Habilitar Receiving e publicar o MX raiz indicado para esse domínio |
| DMARC | Manter política existente; se ausente, definir uma política aprovada e validar alinhamento |
| Painel | A/AAAA para a VPS, sem relação com o MX de entrada |

Preserve DKIM/SPF dos sistemas existentes e registros do subdomínio de envio. Não crie dois SPF no mesmo nome e não enfraqueça DMARC. A política `p=none` é de observação, não bloqueio contra falsificação. [Domínio próprio de recebimento](https://resend.com/docs/dashboard/receiving/custom-domains).

Confira o novo MX nos autoritativos e em resolvers públicos:

```sh
dig @SEU-NS-AUTORITATIVO MX seudominio.com
dig @1.1.1.1 MX seudominio.com
dig @8.8.8.8 MX seudominio.com
```

Espere o estado de Receiving no Resend e realize o teste real. Propagação DNS e domínio verificado não são prova de que a mensagem entrou na caixa certa.

## 9. Caixas e acesso

No painel, crie ou reutilize nesta ordem: **empresa → produto → domínio → caixa**. Confirme o domínio usando seu ID exato no Resend. A consulta distingue envio verificado e recebimento habilitado, mas ainda não comprova o ciclo completo.

Crie apenas os endereços necessários. Para quem já tem conta, vá a **Permissões por caixa**, selecione a pessoa, marque as caixas e, se faltar vínculo, use a opção de vincular às empresas selecionadas. Conceda leitura e/ou envio; caixas sensíveis precisam de concessão explícita adicional. O master não tem leitura implícita.

A mesma pessoa usa seu login e autenticador e alterna as caixas no seletor. Não há senha individual IMAP/SMTP para cada endereço. Para uma pessoa nova, use o convite privado de uso único; nesta versão a entrega do link é manual. Nunca dependa de uma caixa ainda sem recebimento para entregar o primeiro acesso.

## 10. Homologação real

Escolha uma conta externa que o operador controla e inclua-a na lista autorizada. Para cada caixa, use assunto `[QA BRN MAIL — identificador único]`:

| Verificação | Critério para aprovar |
|---|---|
| Entrada | Mensagem externa chega pelos MX públicos, é persistida e pode ser aberta na caixa correta |
| Saída | Mensagem enviada pelo webmail chega e pode ser aberta na conta externa |
| Resposta | Resposta mantém o destinatário/From corretos e o encadeamento; conferir Reply-To efetivo |
| Autenticação | Cabeçalhos recebidos mostram SPF, DKIM e DMARC alinhados conforme a política |
| Isolamento | Não há cópia em outra empresa/caixa; conta sem concessão não lê nem baixa anexos |
| Anexo limpo | Arquivo permitido só é baixado depois de o scanner aprovar |
| Scanner indisponível | Em QA, falha/timeout mantém o anexo bloqueado; recuperação permite retomada |
| Webhook | Repetição é idempotente; assinatura/timestamp inválidos são recusados em endpoint próprio |
| Filas | Worker processa; evento persistido retoma após parada controlada em homologação |
| Backup | Cópia externa verificada e restauração isolada comprovada |

Aceito pelo Resend, entregue ao servidor externo e recebido/aberto são estados diferentes. Não teste com clientes, convites reais ou recuperação da senha do administrador. EICAR só em scanner controlado, nunca por e-mail externo.

Os limites de anexos são 5 arquivos, 10 MiB cada e 20 MiB no total; vídeos são bloqueados. Configure o proxy e o PHP sem aumentar os limites de negócio. Nenhum antivírus garante detectar todo conteúdo malicioso.

## 11. Backup e restauração

### 11.1 Backup da aplicação

A aplicação possui `brnmail:backup`, com chave externa de 32 bytes. O inicializador já criou `/opt/brnmail/private/backup.key`, montada em `/run/secrets/backup.key`.

O backup incorporado tem limites: 100 mil linhas por tabela, 48 MiB de dados agregados antes da codificação e limite de 96 MiB na inspeção do arquivo. É apropriado apenas enquanto sua base cabe nesses limites. Para bases maiores, prepare backup consistente de MySQL + anexos + chaves com procedimento próprio; não aumente limites de memória indiscriminadamente.

Para uma cópia consistente com breve suspensão de escrita, use uma janela de manutenção. O painel pode retornar 502 durante o intervalo; o provedor deverá tentar novamente webhooks recusados. Confira as tentativas após retomar.

```sh
cd /opt/brnmail/deploy
(
  set -eu
  trap 'docker compose up -d fpm inbound outbound attachments scheduler' EXIT
  docker compose stop fpm inbound outbound attachments scheduler
  docker compose run --rm cli php -d memory_limit=512M artisan brnmail:backup \
    --key-file=/run/secrets/backup.key
)
```

O comando informa somente caminho e hash do arquivo; o resultado fica em `/opt/brnmail/data/storage/app/private/backups/`. Não exponha essa pasta por HTTP. Defina uma rotina diária em horário adequado e um alerta de falha; agende esse procedimento apenas depois de medir o tempo e testar o impacto.

Copie os `.enc` para um destino privado **fora da VPS**, com retenção definida, usando SSH/SFTP ou armazenamento próprio aprovado. Verifique o SHA256 no destino. A cópia externa não é feita automaticamente pelo comando. Guarde separadamente a APP_KEY, a chave de backup, o commit e os modelos da release. Snapshot do provedor pode ser complementar; não substitui teste de restauração.

### 11.2 Restaure em outro ambiente

Nunca restaure sobre a instalação ativa. Use uma segunda VPS isolada, sem apontar DNS de produção, com o mesmo commit e dependências. Faça estes ajustes **no ambiente de restauração**:

1. Prepare o runtime e credenciais novas de MySQL/Redis pelo guia; não crie master nem seeders.
2. Antes de iniciar o MySQL novo, altere `MYSQL_DATABASE` no Compose para `brnmail_restore`.
3. No ambiente Laravel, use `APP_ENV=local`, `DB_DATABASE=brnmail_restore`, `BRNMAIL_LOCAL_DEMO=false` e `BRNMAIL_EXTERNAL_ENABLED=false`. Preserve as credenciais novas do banco de destino. Recupere **somente a APP_KEY original** e a chave do backup pelo cofre privado.
4. Mantenha FPM, Caddy, workers e scheduler parados. Não ligue essa cópia ao webhook real.
5. Instale dependências, inicie somente MySQL/Redis e rode migrations com o mesmo código. Não carregue um cache de configuração da produção.
6. Copie o arquivo `.enc` para `/opt/brnmail/restore-in/backup.enc`, diretório privado, arquivo legível apenas pelo UID 33. Monte-o somente na CLI de restauração.

```sh
# SOMENTE na VPS isolada de restauração
cd /opt/brnmail/deploy
docker compose run --rm cli php artisan config:clear
docker compose run --rm cli php artisan migrate --force
docker compose run --rm \
  -v /opt/brnmail/restore-in:/run/restore:ro \
  cli php docs/vps/restore-isolado.php \
  /run/restore/backup.enc /run/secrets/backup.key
```

O exemplo [restore-isolado.php](vps/restore-isolado.php) chama o serviço real de restauração, recusa ambiente de produção, exige banco `brnmail_restore`, destino vazio, esquema compatível e envio externo desativado. Sua saída contém apenas contagens. Registre também a leitura de mensagens, integridade de anexos e permissões usando acesso privado ao ambiente de teste; uma contagem de linhas sozinha não conclui a homologação. Não envie mensagens reais a partir dessa cópia.

## 12. Atualização e reversão

Não use atualização automática sem revisão. Antes de alterar: confira CI, registre commit/digests atuais, tenha backup externo válido e teste a nova revisão fora de produção.

1. Anote `git rev-parse HEAD` e mantenha o commit anterior disponível.
2. Faça backup e programe a breve parada dos cinco processos de aplicação.
3. Atualize o código para **um commit escolhido**, preservando alterações locais de implantação; não use `git reset --hard` como receita.
4. Instale novamente pelo lock na CLI. Se o Dockerfile mudou, reconstrua a imagem antes.
5. Leia migrations antes de executá-las. Execute `migrate --force`, `config:cache` e `view:cache`.
6. Recrie/reinicie FPM, os três workers e scheduler e confira HTTPS, filas e um ciclo real autorizado.

Se a atualização falhar, retorne ao commit e dependências anteriores **somente se forem compatíveis com o esquema do banco atual**. Não use `migrate:rollback` automaticamente e não restaure backup por cima de mensagens novas. Uma migration incompatível exige um plano de recuperação próprio.

Para reverter migração de recebimento, restaure exatamente os MX, prioridades e TTL anteriores, removendo apenas o MX novo. Preserve todas as mensagens recebidas durante a mudança e consulte os dois provedores durante a propagação. Reverter DNS não move mensagens já recebidas.

## 13. Problemas comuns

| Sintoma | Conferir |
|---|---|
| Certificado não emite | A/AAAA, 80/443, firewall, CDN, CAA e serviço anterior ocupando a porta |
| 502 no painel | Estado do FPM, bind `127.0.0.1:19000`, memória e caminho `/srv/brnmail/public` |
| Banco recusado | MySQL 8.4 saudável, senha de arquivo correspondente ao `.env`, porta confirmada em DB_PORT/BRNMAIL_BOOTSTRAP_DB_PORT e nome do banco |
| Troquei a senha no arquivo e não funcionou | Imagem MySQL usa variáveis de inicialização apenas no volume novo; alterar o arquivo não altera usuário existente |
| Não cadastra master | Banco já tem usuários, URLs diferentes, MySQL/porta incorretos, ambiente ou gate externo fora do contrato |
| Caixa não aparece | Falta vínculo ou concessão explícita; não crie outro login nem outro convite |
| Envio bloqueado | Lista de destinatários, domínio não verificado, credencial, gate externo, supressão ou anexo pendente |
| Recebe no Resend, mas não no webmail | Webhook/segredo, domínio e destinatário cadastrados, scheduler, inbound e eventos persistidos |
| Anexo não libera | Saúde, assinaturas, limite ou memória do scanner; nunca libere manualmente sem varredura |
| Mudança no `.env` não aparece | Refaça `config:cache` e reinicie processos longos; confira valor prioritário no cofre |
| Backup falha | Chave de 32 bytes/mode 600, limites, espaço livre e arquivos ausentes |

```sh
cd /opt/brnmail/deploy
docker compose ps
docker compose stats --no-stream
docker compose run --rm cli php artisan schedule:list
docker compose run --rm cli php artisan migrate:status
```

Consulte logs privadamente, por serviço. Não publique `docker inspect` completo, arquivos de ambiente, corpos de mensagens ou logs sem revisão. Monitore disco, renovação TLS, FreshClam, filas e backups. Para reconciliação de recebidos, leia `php artisan help brnmail:reconcile`; o comando é paginado, depende de liberação externa e não substitui a correção do webhook.

## 14. Cobertura e referências

Este guia foi conferido contra o código público e inclui modelos em [docs/vps](vps/). Na preparação foram verificados:

| Verificação | Resultado |
|---|---|
| Compose com hostname fictício | Sintaxe válida |
| Imagem PHP do guia | Build concluído; PHP 8.4.25 e requisitos de plataforma do lock aprovados |
| PHP-FPM sem rede e como UID 33 | Configuração válida; listener confirmado em 127.0.0.1:19000 |
| Caddy sem rede | Configuração válida; nenhum certificado solicitado |
| Inicializador em diretório temporário | Geração consistente, modos de arquivos e recusa de sobrescrita aprovados; chamadas de ownership conferidas sem alterar o host |
| Comandos/documento | 16 blocos shell com sintaxe válida, links relativos conferidos; exemplo PHP com sintaxe/formatação aprovadas |
| VPS pública completa | Não executado por esta documentação |

Revisão de sintaxe e construção de imagem não equivalem a implantação homologada: DNS, certificado público, entrega pelo Resend, capacidade, atualização real do scanner e restauração precisam ser comprovados na VPS escolhida. Nenhuma conta, domínio, caixa ou serviço de produção é alterado pela publicação deste documento.

Fontes oficiais consultadas:

- [Docker Engine: Ubuntu](https://docs.docker.com/engine/install/ubuntu/) e [Debian](https://docs.docker.com/engine/install/debian/).
- [Docker: rede host](https://docs.docker.com/engine/network/drivers/host/) e [imagem PHP](https://hub.docker.com/_/php).
- [Caddy: HTTPS automático](https://caddyserver.com/docs/automatic-https) e [PHP FastCGI](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi).
- [ClamAV: requisitos](https://docs.clamav.net/Introduction.html) e [containers](https://docs.clamav.net/manual/Installing/Docker.html).
- [Resend: Receiving](https://resend.com/docs/dashboard/receiving/custom-domains) e [webhooks](https://resend.com/docs/webhooks/introduction).
