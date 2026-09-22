# Inventário administrativo do master


## Uso

Entrar com a conta master existente, concluir o 2FA e abrir **Administração** (`/admin`). As primeiras seções são **Todas as caixas cadastradas** e **Contas de acesso**. As tabelas têm rolagem horizontal em telas estreitas.

- Caixas: empresa, produto, domínio, endereço, estado, indicação de correspondência sensível e permissões efetivas de leitura/envio por pessoa.
- Pessoas: nome, e-mail de login, estado da conta, perfil, configuração do autenticador e última confirmação de 2FA registrada.
- Datas em UTC. `mfa.verified` é emitido depois de verificação válida, inclusive com código de recuperação; senha aceita sem concluir o segundo fator não conta. Uma nova verificação de 2FA também atualiza a data.
- Uma caixa compartilhada mostra a data mais recente entre seus usuários atualmente autorizados a ler ou enviar. Consultar a tabela de contas para a data individual. Uma caixa inativa não tem operadores efetivos.
- `Sem registro` indica ausência de confirmação no histórico disponível. Retenção/exclusão futura de auditorias pode remover datas antigas. A data não indica leitura ou presença online.
- `Ativa` é o estado administrativo da caixa, não uma comprovação de DNS, entrega externa ou disponibilidade do provedor.

## Proteções e implementação

As rotas existentes mantêm autenticação, versão da credencial, 2FA e verificação de master. A consulta seleciona somente metadados de usuários; não carrega hashes de senha, segredo TOTP ou códigos de recuperação. A renderização escapa nomes e endereços. Nenhuma mensagem ou anexo é carregado pelo inventário.

O último login é calculado por `MAX(created_at)` dos eventos `mfa.verified` de cada pessoa, incluindo o histórico anterior aos 20 eventos exibidos no painel. Não há nova coluna, migration, backfill ou mudança do fluxo de autenticação.

`AdminMailboxInventory` apresenta permissões efetivas considerando estado da caixa, estado da pessoa, vínculo ativo à empresa e concessão de acesso sensível. Não autoriza ações. `Access` continua sendo o controle de leitura/envio/download. Nenhum vínculo, convite, usuário ou concessão é criado pela consulta.

## Cobertura automática

`tests/Feature/AdminMailboxInventoryTest.php`, com dados sintéticos e transações revertidas no banco exclusivo de QA:

| Verificação | Critério |
|---|---|
| Inventário global | Master sem concessões vê caixas de empresas diferentes, inclusive inativas |
| Proteção de conteúdo | Assunto/corpo/anexos ausentes; URLs de caixa/anexo e criação de rascunho recusadas sem concessão |
| Ausência de efeito colateral | Quantidade de vínculos e permissões permanece igual |
| Controle de acesso | Visitante vai para login; master sem 2FA vai para verificação; usuário comum recebe 403 |
| Histórico | Último MFA válido é recuperado mesmo fora dos 20 eventos recentes; senha/falha/leitura não substituem a data |
| Estado efetivo | Resultado coincide com `Access` para usuário inativo, vínculo revogado, caixa inativa e conteúdo sensível |
| Dados privados | Segredos não selecionados; nomes com HTML escapados |

## Publicação e reversão

Publicar a release completa na própria instalação após QA, preservando o `.env`, armazenamento e cache de configuração por release. Não executar migrations para esta mudança. Reiniciar apenas o PHP-FPM do BRN Mail para atualizar OPcache; filas e outros projetos não precisam reiniciar.

Reversão: repontar atomicamente `current` para a release anterior registrada no relatório da implantação e reiniciar apenas `brnmail-php-fpm`. Não restaurar banco, excluir dados ou alterar permissões. A interface anterior volta; auditorias existentes são preservadas.
