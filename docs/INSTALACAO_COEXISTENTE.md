# Instalação nova em servidor que já tem outros serviços

Este complemento orienta a adaptação do [guia de VPS](INSTALACAO_VPS.md) quando o servidor já possui Nginx, PHP 8.4 e outros bancos. O guia principal usa Docker. A instalação nativa descrita aqui precisa de configurações próprias para o servidor escolhido; não execute os dois procedimentos sobre os mesmos diretórios.

**Uma aplicação nova deve começar com dados e credenciais novos.** Não copiar `.env`, banco, contas, sessões, vendor ou chaves Resend de outra instalação. Usar a mesma máquina não significa compartilhar o banco da aplicação.

## 1. Registrar o destino e os recursos

Antes de escrever, identifique as versões reais e os serviços existentes. Confirme capacidade e portas. Não substitua pacotes, reinicie bancos globais nem interrompa sites para liberar portas.

Preencha uma ficha privada com:

| Recurso | Valor escolhido para esta instalação |
|---|---|
| Hostname HTTPS | Um subdomínio controlado pelo operador |
| Prefixo exclusivo | Exemplo: `mail-lab` |
| Usuários Unix | Aplicação, MySQL e Redis separados |
| Código | `releases/<commit>` e link `current` em diretório exclusivo |
| Escrita da aplicação | `shared/storage` e `shared/bootstrap-cache` exclusivos |
| Configuração privada | Diretório fora da raiz pública, sem leitura pelo Nginx |
| MySQL | MySQL 8.4, nova instância, novo datadir e porta livre de loopback |
| Banco inicial | `brnmail`, dentro da nova instância |
| Redis | Instância, diretório, senha e porta de loopback próprios |
| PHP-FPM | Mestre/pool próprios e socket Unix exclusivo |
| Filas/scheduler | Unidades systemd próprias, com o prefixo escolhido |
| Antivírus | Scanner próprio ou scanner local existente, explicitamente autorizado |

Portas alternativas, como 33462 e 16382, são apenas exemplos: verifique se estão livres. Não abra MySQL, Redis, FPM ou scanner para a Internet. Não instale outro SMTP/IMAP; o transporte externo continua sendo o Resend.

## 2. MySQL realmente separado

Quando já existe um binário compatível de MySQL 8.4, é possível usá-lo com outro arquivo de configuração, usuário Unix, datadir, socket, PID, log e porta. Compartilhar o executável não compartilha dados. Confirmar versão, procedência e política de atualização antes de reutilizar esse binário; MariaDB não atende ao contrato do cadastro inicial.

A nova instância deve ter:

- `bind_address=127.0.0.1`, porta própria e `mysqlx=OFF`;
- diretório vazio pertencente ao novo usuário MySQL, sem links para o datadir anterior;
- conta root com senha nova, armazenada em arquivo privado, modo 0600;
- usuário da aplicação com senha diferente, acesso somente a `brnmail.*` e origem de loopback;
- `local_infile=OFF`, `secure_file_priv=NULL` e logs que não registrem consultas com credenciais;
- serviço systemd próprio, limites de memória medidos e diretório `/run` exclusivo.

Se o provisionamento usar `--initialize-insecure`, **a primeira inicialização precisa manter a rede desativada**. Configure as senhas por socket privado antes de habilitar TCP. Nunca use esse modo para uma instância acessível sem autenticação.

No ambiente Laravel, confirme o destino explicitamente:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=33462
DB_DATABASE=brnmail
BRNMAIL_BOOTSTRAP_DB_PORT=33462
```

O exemplo pressupõe que 33462 foi escolhida e provisionada. `DB_PORT` e `BRNMAIL_BOOTSTRAP_DB_PORT` devem coincidir; o cadastro inicial também verifica MySQL 8.4, esquema, URLs HTTPS e ausência de usuários. Não desative essas verificações para adaptar um servidor. O banco dos testes automáticos permanece separado em `brnmail_test`; não execute PHPUnit no banco recém-publicado.

## 3. Redis, diretórios e dependências

Configure Redis em loopback com senha própria, AOF, `appendfsync everysec`, limite de memória e `noeviction`. Use prefixos de cache/Redis e nome de cookie exclusivos. Não reutilize a senha ou a fila de outra aplicação.

Clone o repositório público diretamente e fixe um commit aprovado na CI. Na release, prepare links privados:

```text
release/.env             → configuração privada desta instalação
release/storage          → shared/storage
release/bootstrap/cache  → shared/bootstrap-cache
```

O clone pode não conter diretórios vazios como `storage/`. Crie a estrutura necessária; nunca remova diretórios que já contenham dados. `shared/storage` precisa de `app/private`, `framework/cache/data`, `framework/sessions`, `framework/views` e `logs`.

Execute Composer com o PHP 8.4 explícito, como o usuário de implantação desta aplicação, com cache privado:

```sh
php8.4 /CAMINHO/DO/composer install \
  --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins
php8.4 /CAMINHO/DO/composer validate --strict
php8.4 /CAMINHO/DO/composer check-platform-reqs --no-dev
php8.4 artisan package:discover --no-interaction
php8.4 artisan migrate --force --no-interaction
php8.4 artisan config:cache
php8.4 artisan view:cache
```

Substitua o caminho do Composer pelo binário conferido. Use diretório e ambiente corretos antes de cada comando. Preserve `composer.lock`; não copie vendor de outra instalação, não rode `composer update`, seeders de demonstração ou `storage:link`.

Após a preparação, deixe o código somente leitura para os processos. Dê escrita apenas aos caminhos de runtime. O Nginx precisa ler os assets públicos e atravessar os diretórios até eles; não precisa ler `.env`, código privado, banco ou anexos.

## 4. PHP-FPM, filas e scheduler

Crie um mestre FPM exclusivo, sem incluir automaticamente pools de outros projetos. O pool usa o novo usuário Unix, `clear_env=yes`, socket próprio e permissões que permitam a conexão do Nginx. O ambiente Laravel fica no link `.env`; não duplique segredos em `EnvironmentFile` ou argumentos do processo.

Prepare quatro unidades próprias para:

```sh
php8.4 artisan queue:work redis --queue=inbound --tries=1 --timeout=75 --memory=192 --sleep=3
php8.4 artisan queue:work redis --queue=outbound --tries=1 --timeout=75 --memory=192 --sleep=3
php8.4 artisan queue:work redis --queue=attachments --tries=1 --timeout=75 --memory=192 --sleep=3
php8.4 artisan schedule:work
```

Os timeouts dos workers precisam ser menores que `retry_after` do Redis; esta versão usa 90 segundos por padrão. Configure diretório de trabalho `current`, reinício, saída graciosa e limites de recursos. Use `ProtectSystem`, `ProtectHome`, `NoNewPrivileges`, caminhos de escrita restritos e usuário sem login, revisando compatibilidade com o runtime.

Se `opcache.validate_timestamps=0`, reinicie **somente o FPM desta instalação** a cada nova release. A configuração de OPcache deve ser aplicada no mestre FPM, não como opção de pool. Valide FPM e todas as unidades antes de iniciá-las.

Um scanner compartilhado exige capacidade conjunta e autorização. Consulte a versão e a data das assinaturas e identifique a rotina real de atualização. Não inicie outro FreshClam sobre a mesma base nem altere o scanner dos demais projetos. Teste texto limpo e EICAR somente no canal local controlado; nenhum conteúdo de teste deve ser enviado a caixas externas.

## 5. HTTPS sem substituir o site existente

Adicione um virtual host com `server_name` **exato** para o subdomínio novo, raiz em `current/public` e FastCGI apontando para o novo socket. Um wildcard do servidor pode atender o subdomínio antes dessa configuração; uma página existente ou HTTP 500 não prova que o BRN Mail está instalado.

Permita a execução apenas do `public/index.php`. Bloqueie arquivos ocultos e `/storage/`; configure limites compatíveis de upload e logs sem cookies, tokens, corpos ou query strings. Valide a configuração completa com `nginx -t` antes do reload gracioso.

Use certificado válido para o hostname. Se houver certificado wildcard compatível, ele pode atender outro virtual host sem copiar sua chave para a aplicação. A chave deve ser legível apenas pelos processos administrativos necessários. Não desative validação TLS para fazer o teste passar.

Com Cloudflare Origin CA, mantenha o proxy e TLS Full (strict); esse certificado não substitui um certificado público para acesso direto pelo navegador. Confirme a origem com a CA oficial e o acesso público separadamente. Não altere o modo TLS de toda a zona para acomodar um único site. [Documentação oficial da Origin CA](https://developers.cloudflare.com/ssl/origin-configuration/origin-ca/).

## 6. Primeiro master e aceite

Mantenha o transporte `resend`, envio externo desativado e demonstração desativada. Configure `APP_URL` e `BRNMAIL_BOOTSTRAP_URL` com o mesmo HTTPS real. Execute como o usuário da aplicação, em terminal interativo:

```sh
php8.4 artisan brnmail:bootstrap-master
```

O operador informa nome, login e senha oculta e confirma o cadastro. Não coloque senha em comando, variável, arquivo compartilhado ou chat. Confira a mensagem final e a existência da identidade no banco correto; clicar em “concluído” em um assistente não substitui a verificação. Entre no site e configure o autenticador e os códigos de recuperação privadamente.

Antes de declarar a instalação operacional, registre:

- commit implantado, dependências do lock, migrations e banco/Redis exclusivos;
- HTTPS público/origem, login e MFA reais, acesso protegido e ausência de exposição de arquivos;
- processamento nas três filas, scheduler e scanner, com estado real das assinaturas;
- backup criptografado, cópia externa e restauração em destino separado; teste com conteúdo quando houver mensagens e anexos;
- capacidade observada e saúde dos serviços que já existiam;
- pendências de Resend, DNS, caixas, permissões e envio/recebimento reais conforme o guia principal.

## 7. Reversão

Registre os nomes de todos os recursos novos. Para retirar a publicação, desabilite apenas o virtual host e os serviços desta instalação, valide Nginx e faça reload. Preserve banco, Redis, storage, chaves e backups para recuperação. Uma troca de release não autoriza rollback de migrations nem restauração sobre dados novos.

Essa adaptação não comprova a execução do procedimento Docker em uma VPS vazia. Registre qual perfil foi realmente testado, os limites de capacidade e as etapas não executadas. Nunca publique no GitHub a ficha privada de uma instalação real.
