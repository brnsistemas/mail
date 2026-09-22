# Acesso a várias caixas na mesma conta

Uma pessoa usa o mesmo login para alternar entre as caixas autorizadas. A senha e o autenticador pertencem à identidade; caixas não têm senhas próprias. O papel master não concede leitura implícita.

Em Administração → Permissões por caixa, selecione a pessoa, marque as caixas e escolha as permissões. A confirmação da senha aplica toda a seleção em uma transação, com auditoria por caixa e uma atualização da versão de segurança da pessoa. As sessões anteriores exigirão novo 2FA uma vez após a operação.

Somente as caixas marcadas são atualizadas. As permissões desmarcadas são removidas dessas caixas; caixas não selecionadas e outros usuários permanecem como estão. A autorização de conteúdo sensível precisa ser marcada explicitamente e só é gravada nas caixas atualmente sensíveis. Nenhuma concessão se estende automaticamente a novas caixas.

A pessoa precisa ter participação ativa na empresa de cada caixa selecionada. Para uma conta já cadastrada, o master pode marcar **Vincular esta conta às empresas das caixas selecionadas**. Essa opção explícita (`add_membership=1`) cria apenas os vínculos ausentes, junto com a concessão, sem convite ou novo login. A conta precisa estar ativa; acessos anteriormente revogados não são reativados. Senha, autenticador, nome e papel global da pessoa são preservados. Convites ainda pendentes dessa mesma pessoa nas empresas recém-vinculadas expiram; convites de outras pessoas/empresas permanecem intactos. Há auditoria dos vínculos e das concessões.

Sem a opção marcada, a falta de participação ativa continua recusando a seleção inteira, sem atualização parcial. O limite é de 50 caixas por operação. O endpoint existente `/admin/grants` aceita `mailbox_ids[]` e continua aceitando o formato anterior `mailbox_id`, mas recusa os dois juntos. A alteração não concede leitura implícita a masters nem altera caixas não selecionadas.

A reversão do código preserva os registros de concessão. Para reverter uma concessão, selecione somente as caixas afetadas e reaplique as permissões anteriores pelo formulário, confirmando a senha; as mensagens são preservadas.

## Convite individual com caixa

Novos convites preparam uma caixa antes de o link ser compartilhado. O administrador escolhe a empresa, informa o e-mail da pessoa e confirma a própria senha para autorizar leitura/envio nessa caixa. Por padrão, o endereço da caixa é o mesmo do login. Para uma identidade externa, informe explicitamente uma caixa em domínio cadastrado na empresa selecionada. Domínio ausente, pendente ou de outra empresa impede o convite; o cadastro não altera DNS ou provedor.

Com DNS, provedor, webhook e filas operacionais, a caixa cadastrada já pode receber antes do aceite, sem leitor implícito. O cadastro sozinho não comprova entrega pública. No aceite, a pessoa escolhe a senha e a transação cria identidade, vínculo e concessão somente para a caixa registrada no convite. O acesso ao webmail continua bloqueado até concluir o 2FA. Não são concedidos papel master, gestão de caixas, acesso sensível nem outras caixas.

Reutilizar uma caixa exige a opção explícita de acesso ao histórico. Alias, caixa desativada ou sensível exige revisão separada. A concessão revalida caixa ativa, endereço e empresa no momento do aceite; falha desfaz o cadastro inteiro. Convite duplicado pendente e conta já existente são recusados, sem novos recursos. O link é privado, manual e de uso único; não existe envio automático para uma caixa que a pessoa ainda não consegue acessar. Convites expirados não apagam a caixa ou mensagens já recebidas.

Convites históricos sem caixa e convites de reativação preservam o escopo antigo; não recebem acesso retroativo em lote. Para corrigir especificamente uma identidade ativa que já aceitou um convite antigo e ficou sem a própria caixa, existe a operação auditada de servidor `brnmail:repair-invited-mailbox EMAIL --operator=ID_MASTER --organization=ID_EMPRESA --provision-personal-mailbox`. Exige autorização expressa do responsável, master ativo, colaborador ativo, vínculo ativo e convite comum já aceito. Cria somente a caixa do próprio endereço; recusa caixa/alias existente em conflito e não reativa conta/vínculo revogado. Repetir a mesma correção concluída não duplica concessões nem solicita outro 2FA. Senha e autenticador são preservados; a primeira alteração exige confirmar o 2FA já existente.

A migration `2026_09_22_000001_prepare_invitation_mailbox` é aditiva. Em reversão de código, manter suas colunas e os dados; não executar rollback destrutivo de caixas, convites ou mensagens. Mensagens recebidas pelo provedor antes do cadastro da caixa devem ser recuperadas pela reconciliação autenticada, com escopo validado, sem reenvio/aceite de convites de outras aplicações.
