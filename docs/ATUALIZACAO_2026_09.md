# Edição pública 2026.09

## O que mudou

- Convite de novo colaborador prepara a caixa antes de compartilhar o link. O aceite concede leitura/envio nessa caixa e conduz à escolha de senha e 2FA.
- Contas existentes continuam usando Permissões por caixa, preservando credenciais.
- Administração apresenta inventário de caixas, pessoas, permissões efetivas e último 2FA registrado, sem leitura implícita de mensagens.
- Leitor de HTML sanitizado com botões/tabelas e alternativa em texto; recursos ativos e imagens remotas bloqueados.
- Pasta Spam com movimentação manual, sem filtro automático.
- Enviar salva os campos atuais, inclui o arquivo selecionado, espera a varredura e registra a outbox. Falhas ficam visíveis, preservando a edição; retries cegos são bloqueados quando o resultado é incerto.
- Homologação restrita por padrão e opção explícita de liberar destinatários normais após testes.
- Guia de VPS, prompt assistido e PDF de entrega alinhados ao fluxo atual.

## Instalação nova

Siga [INSTALACAO_VPS.md](INSTALACAO_VPS.md) e use suas próprias contas, domínio, DNS e credenciais. Não há usuários, mensagens, bancos, backups ou serviços prontos nesta distribuição. Exemplos locais são fictícios e não comprovam envio externo. Preserve as confirmações configuráveis de hostname e porta no bootstrap público.

## Atualizar instalação existente

Antes de atualizar, revise as diferenças, registre commit/imagens anteriores e tenha backup criptografado fora da VPS com restauração isolada verificada. Teste em uma cópia autorizada; não rode seeders ou PHPUnit contra o banco real.

As migrations de reativação de credenciais e preparação de caixa em convites adicionam campos ao esquema. Execute as migrations antes de iniciar o código novo. Preserve o banco, armazenamento, APP_KEY, cofre, usuários, caixas, concessões e configuração privada. Não execute novamente bootstrap de master. A migration não cria caixas ou permissões retroativas para todos os convites antigos.

Instale dependências pelo lock, recompile os caches e reinicie apenas FPM, inbound, outbound, attachments e scheduler desta instalação. Confira login/2FA, permissões, anexos e ciclo real de recebimento e resposta autorizado. Consulte a CI do commit escolhido; entrega real, DNS, scanner do servidor e backup não são comprovados pela CI.

Para voltar ao código anterior, confira sua compatibilidade com o esquema atualizado e mantenha as colunas aditivas. Preserve dados e credenciais; não faça migrate:rollback, migrate:fresh ou restauração sobre mensagens novas. A interface anterior pode não mostrar HTML/Spam. Revisões antigas do login podem não aplicar o controle novo de versão de credencial: a reversão exige revisão, não deve ser automática após uma revogação.

## Distribuição

A publicação contém código, testes sintéticos e documentação genérica. O histórico desta distribuição é independente de instalações particulares. Não publique seu .env, logs, dumps, anexos, backups, segredos, links de convite ou capturas de dados reais em forks/issues. Para vídeos, use dados fictícios e esconda senha, QR do autenticador e códigos de recuperação.

O projeto mantém sua licença declarada; a publicação desta edição não altera os termos das dependências.
