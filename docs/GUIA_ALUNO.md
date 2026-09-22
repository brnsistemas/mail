# BRN Mail - Guia do aluno
Edição 2026.09. Fonte textual do PDF disponibilizado na release.

## Seu webmail. Sua operação.
Guia de instalação e primeiros acessos

BRN Mail organiza e-mails de várias empresas e domínios em um painel. Cada pessoa usa sua conta e enxerga somente as caixas autorizadas.

> EDIÇÃO 2026.09
Código público + tutorial de VPS + prompt para Codex ou Claude Code.

### Do código ao primeiro e-mail

Prepare a infraestrutura, configure seu domínio, crie o administrador e as caixas, convide a equipe e comprove recebimento e resposta de verdade.

[Abrir o repositório dos alunos](https://github.com/brnsistemas/mail)

[Abrir o tutorial completo com os comandos](https://github.com/brnsistemas/mail/blob/main/docs/INSTALACAO_VPS.md)

Este material usa somente exemplos fictícios. Você utiliza sua própria VPS, domínio, conta Resend e credenciais. Não inclui contas de acesso, banco de dados ou mensagens de uma operação existente.

> O guia detalhado no GitHub é a referência dos comandos. Este PDF apresenta a sequência, as decisões e o checklist para conduzir a instalação e o primeiro uso.

## Uma conta, várias caixas.
Entenda as peças antes de instalar.

| Peça | Função |
|---|---|
| BRN Mail | Painel, usuários, caixas, permissões, mensagens e anexos. |
| VPS | Executa Laravel/PHP, MySQL, Redis, filas e ClamAV. |
| Resend | Transporta envios e recebimentos; avisa o BRN Mail por webhook. |
| DNS | Autentica o envio e define para onde chegam as mensagens. |

### Empresa > produto > domínio > caixa

Exemplo de demonstração: Empresa Aurora > Atendimento > aurora.example.test > suporte@aurora.example.test. Domínios .test são reservados para demonstração e não recebem e-mails públicos.

### A senha é da pessoa

Um colaborador pode usar seu login para operar suporte e comercial. Cada acesso é concedido explicitamente. As caixas não têm senhas IMAP/SMTP próprias; o acesso acontece pelo webmail.

### Master administra, permissões liberam leitura

O master vê o inventário de caixas e contas, incluindo permissões e última confirmação de 2FA registrada. Para ler ou enviar por uma caixa, também precisa de concessão. Criar um domínio ou ser master não abre mensagens automaticamente.

> Esta distribuição não instala Postfix ou Dovecot. Não configure clientes IMAP/SMTP usando a senha do webmail.

## O que você precisa.
Defina o destino e os responsáveis.

| Item | Antes de avançar |
|---|---|
| VPS Linux x86_64 | Ubuntu 24.04 LTS no roteiro principal. SSH com sudo e suporte a Docker. |
| Capacidade inicial | Referência de planejamento: 4 vCPU, 8 GiB RAM e 40 GiB SSD; 12 GiB dão mais margem. Medir antes de produção. |
| Domínio próprio | Controle do DNS. Pode usar Cloudflare ou outro provedor. |
| Resend | Conta própria com envio e Receiving disponíveis. Confirmar limites e custos vigentes. |
| Identidade | E-mail de login controlado pelo administrador e aplicativo autenticador. |
| Teste e backup | Caixa externa controlada, cofre e destino de backup fora da VPS. |

A estimativa não é uma garantia de capacidade. O scanner e suas atualizações consomem memória; número de mensagens e tamanho dos anexos afetam disco e processamento. Não trate uma VPS de 1 GiB como suficiente para esse conjunto.

### Se ainda não contratou a VPS

Peça ao agente para comparar opções compatíveis e apresentar valor recorrente, região, disco, backup e limites. Você escolhe e aprova a contratação. Não existe um plano pago incluído neste código.

### Se o servidor já tem outros sistemas

Inventarie portas, serviços e memória antes de instalar. Use dados, credenciais e processos exclusivos. O roteiro com Caddy pressupõe 80/443 livres; um proxy existente exige adaptação.

[Guia de coexistência com serviços existentes](https://github.com/brnsistemas/mail/blob/main/docs/INSTALACAO_COEXISTENTE.md)

## Comece pelo prompt completo.
O agente conduz; você fornece decisões e acessos privados.

### 1. Abra o projeto público

Baixe ou clone o repositório em uma pasta nova. Abra essa pasta no Codex ou Claude Code. Use o arquivo docs/PROMPT_INSTALACAO_ASSISTIDA.txt: ele orienta o agente a ler o manual, inspecionar o ambiente e avançar por etapas.

[Copiar o prompt de instalação assistida](https://github.com/brnsistemas/mail/raw/refs/heads/main/docs/PROMPT_INSTALACAO_ASSISTIDA.txt)

### 2. Informe os dados sem segredos

Diga se já possui VPS, qual sistema operacional, o hostname escolhido para o painel, domínio das caixas e quais endereços precisa. Exemplo de preenchimento: painel mail.SEUDOMINIO, caixas suporte e comercial, administrador já identificado e um destinatário externo autorizado para QA.

### 3. Guarde credenciais fora da conversa

Senha do administrador, chave SSH, API key, segredo do webhook, APP_KEY e códigos do autenticador devem ser inseridos por fluxo privado. O agente deve pedir sua ação quando o formulário exigir senha ou 2FA; não fotografe esses campos.

### 4. Saiba em qual terminal executar

Comandos da instalação são executados na VPS, depois de conectar por SSH. O terminal do computador serve para abrir essa conexão. Não cole comandos de instalação do servidor no seu Mac ou PC sem verificar o destino.

> O agente deve registrar progresso privado e retomar de onde parou. Uma tela de login aberta não encerra a instalação: ainda faltam domínio, transporte, caixas, testes e backup.

## Prepare a VPS sem sobrescrever dados.
Roteiro principal: Linux x86_64, Docker e Compose.

### Verificação inicial no servidor

```sh
cat /etc/os-release
uname -m
free -h
df -h
ss -lntup
```

Confira arquitetura, memória, espaço e portas. Se existir algo em /opt/brnmail, pare e identifique a instalação. Não apague a pasta nem interrompa serviços para forçar o roteiro.

### Instale Docker pela documentação oficial

Use o repositório oficial da distribuição. Confirme Docker Engine e o plugin docker compose. No Ubuntu, instale também Git, Python 3, cliente DNS e certificados conforme a seção 2 do tutorial. Node.js não é obrigatório no servidor de aplicação.

[Docker Engine no Ubuntu](https://docs.docker.com/engine/install/ubuntu/)

### Rede e portas

| Acesso | Portas do modelo |
|---|---|
| Internet | 80 e 443 para o painel; SSH limitado às origens administrativas. |
| Somente loopback | MySQL 33461, Redis 16381, ClamAV 13310 e FPM 19000. |
| Saída | DNS, sincronização de horário e HTTPS para provedores/atualizações. |

Não exponha banco, Redis, scanner ou FPM em 0.0.0.0. O modelo publica essas portas em 127.0.0.1. Docker pode contornar regras comuns do UFW; confira também firewall do provedor.

> Se 80/443 já estiverem em uso, integre o virtual host ao proxy existente com revisão própria. Não inicie um segundo proxy disputando as mesmas portas.

## Instale uma cópia nova.
Siga as seções 3 a 5 do tutorial completo.

### Código público, dados privados

```sh
git clone https://github.com/brnsistemas/mail.git /opt/brnmail/app
cd /opt/brnmail/app
git status --short
git rev-parse HEAD
```

O tutorial prepara deploy com os arquivos de docs/vps. Código fica em app; segredos em private; armazenamento e cache em data. Use os comandos completos do tutorial.

### Inicialização única

Defina BRNMAIL_HOST com seu hostname real, sem protocolo ou barras, e execute inicializar.py conforme o guia. Ele gera segredos distintos e recusa sobrescrever configuração privada existente. Não rode novamente para tentar corrigir login.

| Configuração | Estado inicial |
|---|---|
| APP_ENV / APP_DEBUG | production / false |
| APP_URL / BRNMAIL_BOOTSTRAP_URL | Mesmo HTTPS da sua instalação. |
| BRNMAIL_TRANSPORT | resend |
| BRNMAIL_EXTERNAL_ENABLED | false até preparar a integração. |
| BRNMAIL_LOCAL_DEMO | false |
| BRNMAIL_RESTRICT_TEST_RECIPIENTS | true durante homologação. |

### Dependências e esquema

Construa a imagem, instale pelo composer.lock, confira requisitos, inicie MySQL/Redis e aplique migrations no banco novo. Não use composer update, migrate:fresh ou seeders de demonstração na VPS com uso real.

> Guarde APP_KEY e a chave do backup em cofre privado. Perder ou substituir a APP_KEY pode tornar mensagens e credenciais existentes ilegíveis.

## Crie o master e ative o HTTPS.
A senha é escolhida privadamente por você.

### Cadastro inicial no terminal da VPS

```sh
cd /opt/brnmail/deploy
docker compose run --rm cli php artisan brnmail:bootstrap-master
```

Execute interativamente após migrations. Informe nome, e-mail que você controla e senha forte de 14 a 72 bytes. O comando exige MySQL 8.4, banco novo brnmail em loopback e URLs confirmadas. A porta padrão é 33461; coexistência exige confirmação adicional descrita no guia.

Se já houver usuários, o cadastro é recusado. Isso protege identidades existentes. Use a conta já criada; não rode outro bootstrap para conceder uma caixa ou solucionar uma senha incorreta.

### Painel público com certificado válido

Aponte o A do hostname do painel para a VPS. Só use AAAA com IPv6 funcionando. Valide FPM/Caddy e inicie scanner, FPM, inbound, outbound, attachments, scheduler e web conforme a seção 7. O Caddy administra o certificado quando DNS e rede permitem.

| Caminho no seu hostname | Uso |
|---|---|
| /login | Entrar na conta individual. |
| /mail | Selecionar caixa, ler e escrever. |
| /admin | Empresa, domínio, caixas, convites e permissões. |
| /security | Segurança da conta. |

### Finalize o autenticador

Entre pelo HTTPS, configure seu aplicativo TOTP e guarde os códigos de recuperação em local privado. Mantenha a gravação desligada nessa etapa. O master nasce sem caixas e não recebe leitura automática.

> O A do painel apenas abre o site. Ele não configura o recebimento de e-mails; essa função é do MX do domínio.

## Conecte o seu domínio.
Prepare o destino antes de migrar o recebimento.

### 1. Inventarie o que já existe

Confirme organização e domínio corretos no Resend. No DNS, registre MX, SPF, DKIM, DMARC, prioridades, TTL e subdomínio de envio. Liste caixas, aliases, grupos e encaminhamentos no provedor anterior; a consulta MX não revela todos os destinatários.

### 2. Configure o envio

Cadastre ou reutilize o domínio no Resend e publique somente os registros exatos fornecidos para ele. Preserve SPF/DKIM de outros sistemas e não crie dois SPF no mesmo nome. Não copie registros de outra instalação nem enfraqueça DMARC.

### 3. Prepare o recebimento

Receiving fornece o MX necessário na tela do domínio. Antes de trocar, deixe o BRN Mail com credencial, webhook, filas e caixas cadastradas. O plano deve contemplar todos os destinatários anteriores. A migração precisa de aprovação do responsável.

### 4. Aplique e confira

Troque somente os registros aprovados. Confira servidores autoritativos e resolvers públicos. Use o valor e prioridade da sua conta; este PDF não fornece um MX universal. Não misture MX de provedores para tentar dividir destinatários do mesmo domínio.

> Mudar o MX afeta todos os endereços daquele domínio. Se algum destinatário antigo estiver sem destino definido, interrompa a migração antes de alterar o DNS.

[Domínios de recebimento no Resend](https://resend.com/docs/dashboard/receiving/custom-domains)

Reversão: restaure os valores, prioridades e TTL registrados antes da troca e consulte ambos os provedores durante a propagação. Preservar o banco é obrigatório; DNS não transporta de volta mensagens já recebidas.

## Webhook, filas e armazenamento.
O evento precisa chegar e virar mensagem na caixa certa.

### Credencial dedicada

Use uma chave própria para esta instalação, com permissões de envio e consulta de recebidos. Avalie o alcance apresentado pelo Resend; se ultrapassar o domínio previsto, aprove isso antes de criar. Guarde a chave no cofre do BRN Mail. Não reutilize chaves de outros projetos.

### Endpoint do seu painel

```sh
https://SEU-HOST/webhooks/resend
```

Crie esse endpoint no Resend e salve o segredo de assinatura no cofre. Selecione os eventos suportados: email.received, email.sent, email.delivered, email.bounced, email.complained, email.failed e email.delivery_delayed.

### Ativação controlada

Cadastre a conta externa de teste autorizada. Após preparar credencial e webhook, ative BRNMAIL_EXTERNAL_ENABLED, atualize config:cache e reinicie os processos próprios conforme o guia. Mantenha BRNMAIL_RESTRICT_TEST_RECIPIENTS=true. A consulta do domínio depende do gate externo.

| Processo | Responsabilidade |
|---|---|
| inbound | Consultar recebidos e persistir na empresa/caixa reconhecida. |
| outbound | Enviar intenções duráveis, rever permissões e estados. |
| attachments | Processar e verificar anexos antes da liberação. |
| scheduler | Bombeamento e recuperação das etapas registradas. |

O webhook valida assinatura e timestamp no corpo original, persiste o evento e processa depois. Duplicidade não deve criar outra mensagem. Destinatário desconhecido não deve cair em outra empresa.

> HTTP 202 só comprova aceitação do evento. Domínio verificado ou worker ativo também não comprova mensagem recebida e acessível.

## Crie a operação.
Endereços independentes e permissões explícitas.

### Uma estrutura de exemplo

| Empresa / domínio fictício | Caixas sugeridas |
|---|---|
| Aurora / aurora.example.test | admin, comercial, suporte |
| Horizonte / horizonte.example.test | atendimento, financeiro, comunicacao |
| Estúdio / estudio.example.test | projetos, contato, suporte |

Use esses nomes apenas para aprender ou gravar uma demonstração. Para envio e recebimento público, substitua por domínios que você controla e conclua Resend/DNS. Nunca marque um domínio fictício como verificado no provedor.

### Ordem no painel

Crie ou reutilize empresa, produto e domínio. Informe o ID exato do domínio no Resend e consulte o estado. Crie apenas as caixas necessárias; não ative catch-all. Um alias precisa de destino e decisão explícitos, pois compartilha armazenamento com a caixa de destino.

### Você vai administrar todas as caixas?

Use sua conta master já existente. Em Permissões por caixa, selecione a própria identidade, marque as caixas desejadas e leitura/envio. Se faltar participação na empresa, use a opção explícita de vínculo. Confirme sua senha atual e o 2FA quando solicitado. Não envie um convite para si mesmo.

### Acesso da equipe

Para pessoas já cadastradas, use o mesmo formulário. Para um novo colaborador, siga o convite com caixa preparada da próxima página. Caixas de privacidade, segurança ou outros conteúdos sensíveis exigem responsáveis e autorização específica.

> A caixa ativa no inventário é um estado administrativo. A prova de recebimento só vem do teste externo e da leitura na caixa correta.

## O colaborador entra com a caixa preparada.
Sem senha criada por outra pessoa.

### 1. Administrador prepara o convite

Selecione a empresa e informe o e-mail da pessoa. Por padrão, esse mesmo endereço será a caixa; o domínio precisa estar cadastrado e confirmado nessa empresa. Para login externo, informe explicitamente uma caixa interna. Confirme sua senha para autorizar leitura e envio.

### 2. Caixa pronta antes do link

O sistema cria a caixa junto com o convite. Se já existir, exige confirmação explícita de acesso ao histórico. Caixa sensível, desativada ou endereço que é alias exige revisão separada. Conta existente usa Permissões por caixa, sem duplicar convite.

### 3. Entrega privada

Compartilhe o link completo por canal que a pessoa já acessa. A entrega é manual; o BRN Mail não envia automaticamente o convite para uma caixa ainda sem acesso. O link vale por 48 horas e pode ser usado uma vez. Não corte o trecho final nem publique o link em gravações.

### 4. Senha e autenticador pelo titular

A pessoa abre o convite, escolhe e repete a senha. O aceite cria a identidade, o vínculo e a permissão de leitura/envio na caixa prevista. Ela segue diretamente para configurar 2FA, guarda os códigos de recuperação e entra no webmail.

### 5. Confirmação de acesso

Confira que a caixa aparece no seletor e permite ler a mensagem de QA. O convite não concede master, outras caixas ou gestão administrativa. Um convite antigo aceito sem caixa exige revisão pontual de permissões; esta atualização não libera históricos em lote.

> Criar a caixa antes do aceite evita deixar o colaborador sem destino, mas não recupera sozinho e-mails anteriores nem substitui os testes de entrega pública.

## Escreva, responda e acompanhe.
O que o webmail oferece nesta edição.

### Enviar em um clique

Preencha destinatários, assunto e mensagem. Selecione o anexo, se precisar, e clique em Enviar. O fluxo salva os campos atuais, inclui o arquivo selecionado, aguarda varredura aprovada e registra a intenção de envio. Salvar rascunho continua disponível.

Se a verificação demorar, o rascunho permanece salvo e informa o bloqueio. Se houver falha de conexão com resultado incerto, confira o rascunho/estado antes de repetir. Não reanexe cegamente nem trate uma mensagem em fila como entregue.

### E-mails formatados

Quando existe HTML válido, o leitor preserva elementos permitidos, incluindo títulos, tabelas, cores e botões. Conteúdo ativo e imagens/fontes remotas são bloqueados. A versão em texto permanece disponível. Nem todo CSS de remetentes será reproduzido.

### Spam manual

Marcar como spam move a mensagem recebida para a pasta Spam. Não é spam devolve à Entrada. Não há filtro automático, aprendizado ou limpeza automática nesta edição. A ação respeita as permissões da caixa.

### Anexos protegidos

| Regra atual | Limite/comportamento |
|---|---|
| Quantidade | Até 5 arquivos por mensagem. |
| Tamanho | 10 MiB por arquivo; 20 MiB no total. |
| Tipos | Imagens/documentos previstos na política; vídeos bloqueados. |
| Scanner | Sem aprovação, sem liberação. Antivírus não garante detecção absoluta. |

> Mensagens e anexos exigem permissão mesmo por URL direta. Não crie storage:link nem exponha os arquivos privados por um servidor estático.

## Prove o ciclo de ida e volta.
Execute para cada caixa com uma conta externa autorizada.

| Etapa | Evidência esperada |
|---|---|
| 1. Entrada pública | Envio externo pelos MX públicos; evento identificável no Resend. |
| 2. Persistência | Mensagem armazenada na empresa e caixa corretas. |
| 3. Acesso | Responsável abre a mensagem no webmail após login/2FA. |
| 4. Resposta | Resposta pelo BRN Mail chega e pode ser aberta na conta externa. |
| 5. Autenticação | Conferir From, To, Reply-To, encadeamento e SPF/DKIM/DMARC. |
| 6. Isolamento | Sem cópia em outra empresa/caixa; usuário sem concessão não lê. |

Use um assunto como [QA BRN MAIL - ID UNICO] e conteúdo sem dados sensíveis. Registre horário com fuso, caixa, identificador e resultado em relatório privado. Não teste com clientes ou toda a agenda.

### Teste também em QA isolado

Duplicidade e assinatura inválida de webhook, retomada de worker, revogação de acesso antes do job, download sem concessão, anexo bloqueado e scanner indisponível. Não envie payloads forjados ao Resend nem EICAR a contas externas.

### Liberar envio normal

Depois do ciclo aprovado e da autorização do responsável, altere BRNMAIL_RESTRICT_TEST_RECIPIENTS para false no ambiente privado. Recompile o cache e reinicie os processos próprios. Permissões, limites, supressões, domínio verificado e scanner continuam obrigatórios.

> Simulado, aceito pelo provedor, entregue ao servidor e recebido/acessível são estados distintos. CI aprovada e HTTP 200 não substituem esta homologação.

## Mantenha os dados protegidos e recuperáveis.
A instalação continua exigindo operação.

### Backup com restauração comprovada

Siga a janela de consistência e os limites do comando de backup descritos no tutorial. Guarde a cópia criptografada fora da VPS e as chaves em canal privado separado. Teste a restauração em banco isolado e vazio, com envio externo desativado; verifique mensagens, anexos e permissões.

### Atualização desta edição

Registre o commit e imagens atuais, leia as migrations, faça backup validado e teste antes da publicação. Preserve APP_KEY, dados e configuração privada. Não execute bootstrap novamente. Aplique migrations aditivas, dependências pelo lock, caches e reinicie FPM, filas e scheduler.

Convites antigos não recebem acesso retroativo em lote. Voltar à versão anterior exige compatibilidade de esquema e revisão de segurança das credenciais. Não remova colunas ou restaure backup sobre mensagens novas como atalho de reversão.

### Quando algo falhar

| Sintoma | Primeira conferência |
|---|---|
| Não abre o painel | DNS A/AAAA, TLS, proxy, firewall e estado do FPM. |
| Login recusado | Hostname e identidade corretos; senha digitada ou preenchimento automático. Sessão antiga não comprova senha nova. |
| Nenhuma caixa atribuída | Vínculo/concessão efetivos ou convite legado sem caixa. |
| Resend recebeu, caixa vazia | Destinatário cadastrado, webhook, inbound e scheduler. |
| Envio ou anexo bloqueado | Restrição de QA, supressão, limites e saúde do scanner. |

> Não recrie conta, não troque APP_KEY e não desligue 2FA para contornar um erro. Registre a falha sem senha, tokens ou conteúdo de mensagens.

## Seu próximo passo está no repositório.
Salve estes links para instalar e retomar.

[Código público e instruções](https://github.com/brnsistemas/mail)

[Tutorial completo de VPS](https://github.com/brnsistemas/mail/blob/main/docs/INSTALACAO_VPS.md)

[Prompt completo para copiar](https://github.com/brnsistemas/mail/raw/refs/heads/main/docs/PROMPT_INSTALACAO_ASSISTIDA.txt)

[Fluxo de convite com caixa preparada](https://github.com/brnsistemas/mail/blob/main/docs/INVITATION_FLOW.md)

[Mudanças e cuidados de atualização](https://github.com/brnsistemas/mail/blob/main/docs/ATUALIZACAO_2026_09.md)

### Pedido curto para começar

> Quero instalar o BRN Mail do repositório github.com/brnsistemas/mail. Leia index.md, docs/INSTALACAO_VPS.md e o prompt de instalação assistida. Inspecione meu ambiente antes de alterar. Conduza a preparação da VPS, HTTPS, Resend, DNS, master, caixas e convites. Use só meus recursos autorizados, preserve dados existentes e peça que eu insira segredos privadamente. Conclua com testes reais de recebimento/resposta e backup restaurado em destino isolado. Registre o que ficou pendente; não declare entrega por HTTP 200.

### Antes de publicar a sua demonstração

Use somente nomes, mensagens e domínios fictícios. Não grave senha, QR do autenticador, códigos de recuperação, API keys, links de convite ou dados de clientes. Para gravar o painel, prepare os acessos privados antes de iniciar a captura.

### Referências oficiais

[Resend: domínios e autenticação](https://resend.com/docs/dashboard/domains/introduction)

[Resend: webhooks](https://resend.com/docs/webhooks/introduction)

[Caddy: HTTPS automático](https://caddyserver.com/docs/automatic-https)

[ClamAV: documentação](https://docs.clamav.net/Introduction.html)

Material de instalação e operação, não comprovação de uma VPS já homologada. A distribuição mantém sua licença declarada; consulte os termos do repositório e das dependências.
