# Journey — Atividades Opcionais 1.1.3

Plugin único para WordPress: alunos e responsáveis na TOTVS RM, validação WhatsApp opcional, atividades por turma, inscrição em várias atividades, checkout K8 e acompanhamento.

## 1. Instalação, na ordem

1. Faça backup dos arquivos e banco do WordPress. Instale primeiro em uma cópia de homologação.
2. Desative os plugins antigos **Sistema Alunos**, **K8 Cobranças** e **K8 Financeiro**. Não exclua os dados. O plugin unificado bloqueia sua ativação se encontrar os três diretórios originais ativos.
3. Em **Plugins → Adicionar plugin → Enviar plugin**, envie `journey-atividades.zip` e ative **Journey — Atividades Opcionais**.
4. Abra **Atividades Journey → Integrações**. Preencha a conexão TOTVS e escolha se deseja exigir validação pelo WhatsApp. Quando ligada, preencha o ID da API NX e o token.
5. Salve e clique em **Sincronizar agora**. Ajuste o mapeamento de colunas até a consulta passar.
6. Abra **K8 Financeiro → Configurações**, confira ambiente e credenciais de Cobranças. Configure Pay caso queira conciliação, taxas e parcelas de cartão.
7. Cadastre atividades em **Atividades Journey**: nome, descrição, valor em reais e uma ou várias turmas. Marque **Ativa**.
8. Crie as duas páginas descritas abaixo. Exclua ambas do cache do WordPress, Nginx/CDN e PWA; não armazene respostas de `admin-ajax.php` no service worker.
9. Execute o roteiro de homologação antes de divulgar a página aos responsáveis.

Requisitos: WordPress 6.4+, PHP 8.0+ com OpenSSL, HTTPS no site e na API TOTVS, MySQL 8 (ou compatível com InnoDB, GET_LOCK e JSON_CONTAINS), saída HTTPS do servidor para TOTVS e K8 (e NX quando a validação WhatsApp estiver ligada). Não é necessário Composer. Em proxy reverso, configure o WordPress para reconhecer corretamente HTTPS.

## 2. Páginas e shortcodes

Página de inscrições, por exemplo `/atividades/`:

```
[journey_atividades]
```

Página da equipe, por exemplo `/acompanhamento-atividades/`:

```
[journey_atividades_dashboard]
```

A segunda página deve ser uma página pública normal do WordPress. O próprio plugin mostra a tela de senha antes de consultar inscrições; não usa login nem senha de usuários WordPress.

**Senha inicial: Journey15530**

A sessão do painel dura 8 horas. A senha fica armazenada como hash. É possível alterá-la em **Atividades Journey → Integrações**, o que invalida as sessões anteriores. Não aplique a proteção nativa de página do WordPress por cima da proteção do plugin.

Remova os shortcodes antigos das páginas e substitua por estes. Não há redirecionamento para o antigo endereço de compra nem dependência do endpoint antigo de situação de matrícula.

## 3. Conexão TOTVS / DADOSREMAT.2

Campos no administrador:

- URL base: por exemplo `https://rm.suaescola.com.br:8051` (sem a rota `/api/...`).
- Usuário e senha: usuário de integração RM autorizado a executar a consulta.
- Consulta fixa: **DADOSREMAT.2**.
- Coligada: a coligada em que a sentença SQL está cadastrada.
- Sistema: normalmente **S**, para Educacional. Confirme onde sua consulta foi criada.
- Parâmetros: somente os que a sua sentença exige, por exemplo `CODCOLIGADA=1;IDPERLET=123`. O plugin faz a codificação da URL.

Rota utilizada, via GET e autenticação Basic sobre HTTPS:

```
/api/framework/v1/consultaSQLServer/RealizaConsulta/DADOSREMAT.2/{coligada}/{sistema}
```

A DADOSREMAT.2 deve retornar a lista completa do período em uso. O plugin não conhece nem modifica o SQL cadastrado no RM. Não utilize uma consulta que exija receber um CPF específico para devolver a lista: o cadastro de atividades precisa conhecer as turmas disponíveis. Um retorno explicitamente paginado é rejeitado para não autorizar com uma lista incompleta.

### Colunas

O ZIP original não contém o SQL nem uma amostra real da DADOSREMAT.2. Por isso, o mapeamento é configurável e deve ser conferido antes do uso.

| Campo do plugin | Conteúdo esperado |
|---|---|
| ra | Matrícula/RA do aluno, mantendo zeros iniciais |
| nome_aluno | Nome completo do aluno |
| turma | Código ou nome da turma, consistente em todos os registros |
| coligada | CODCOLIGADA; usa a coligada configurada se ausente |
| periodo | IDPERLET; recomendado para separar períodos letivos |
| cpf_resp1 | CPF do responsável 1, nunca o CPF do aluno |
| nome_resp1 | Nome do mesmo responsável 1 |
| telefone_resp1 | Telefone do responsável 1 (necessário para WhatsApp) |
| cpf_resp2 | CPF do responsável 2, opcional |
| nome_resp2 | Nome do mesmo responsável 2 |
| telefone_resp2 | Telefone do responsável 2 (necessário para WhatsApp) |

Você pode usar nomes alternativos separados por `|`. O mapeamento ignora maiúsculas/minúsculas. Campos de responsável 2 podem ficar vazios. A consulta também pode retornar uma linha por vínculo aluno/responsável, sempre preenchendo os campos do responsável 1.

O botão de sincronização exibe os nomes das colunas recebidas, inclusive quando o mapeamento não passa. Nunca exibe os valores dos registros. A consulta à TOTVS acontece somente na sincronização manual ou agendada. CPF, lista de alunos, turmas e autorização da inscrição usam exclusivamente as tabelas locais do WordPress. Não há consulta à TOTVS provocada pela busca de CPF, por falta de cadastro ou por expiração de cache.

### Espelho local e sincronização

- `wp_jao_students`: alunos, RA, nome, turma, coligada e período letivo.
- `wp_jao_guardians`: vínculos entre aluno e responsável, CPF, nome e telefone; índice de busca por CPF. O prefixo real acompanha o WordPress.
- A TOTVS é a fonte dos cadastros. Não há edição local desses campos nem envio de alterações para o RM.
- A cada sincronização completa válida, entram alunos e responsáveis novos, são atualizados os existentes e saem do espelho os vínculos ausentes na consulta. O histórico de inscrições e pagamentos permanece intacto.
- Toda a lista é validada antes da publicação. A substituição ocorre em uma única transação InnoDB, sem TRUNCATE: a leitura enxerga a versão anterior ou a nova, nunca uma atualização parcial.
- Falha de rede, resposta inválida, paginação incompleta ou erro de gravação mantém a última versão local concluída, que continua disponível para validação.
- Uma lista vazia é recusada automaticamente para não apagar o espelho por engano. Se a TOTVS realmente não tiver registros, use a confirmação de lista vazia na sincronização manual.
- Frequência padrão: **a cada hora**. No painel, escolha 15 minutos, uma hora, seis horas, uma vez ao dia ou somente manual.
- O painel mostra última sincronização concluída, quantidades de alunos/vínculos, próxima execução e último erro.
- O WP-Cron depende das visitas ou do cron configurado no servidor. A operação de sincronização tem trava para evitar duas importações simultâneas.
- O espelho não expira automaticamente: em indisponibilidade prolongada, permanecem os dados da última sincronização. Acompanhe a data e os erros no painel. Para aplicar uma remoção ou alteração urgente no RM, clique em **Sincronizar agora**.

### Atualização da versão 1.0.0

Envie o novo ZIP pelo WordPress e escolha substituir a versão instalada. Não desinstale nem apague dados. As tabelas novas são criadas automaticamente; atividades, pedidos, senha do painel e configurações existentes são preservados. Depois, em **Atividades Journey → Integrações**, clique em **Sincronizar agora** para fazer a carga inicial. Antes dessa primeira carga, o espelho estará vazio e a validação não buscará dados na TOTVS sob demanda. O cache temporário da versão anterior não é importado como fonte definitiva.

Com WhatsApp desligado, telefone ausente ou divergente não bloqueia o acesso. Com WhatsApp ligado, é necessário um único telefone válido associado ao CPF. Cadastros sem CPF válido ou sem alunos vinculados não liberam acesso. Filtre o período letivo na consulta; o mesmo RA não pode ter turmas divergentes no mesmo período/coligada.

## 4. Ligar ou desligar a validação pelo WhatsApp

Em **Atividades Journey → Integrações → Acesso dos responsáveis**, marque ou desmarque **Exigir validação pelo WhatsApp** e clique em **Salvar configurações**.

- **Ligado (padrão):** CPF → confirmação dos últimos quatro dígitos do telefone → Continuar → código WhatsApp → alunos → atividades. O envio só ocorre ao clicar em Continuar. Preencha o ID da API NX Digital e o token; valores salvos continuam disponíveis. O código vale cinco minutos e permite até cinco tentativas. A sessão após confirmar o código dura quinze minutos.
- **Desligado:** CPF → alunos → atividades. Não envia mensagens, não exige código, telefone ou credenciais NX. A sessão dura quinze minutos. Conhecer um CPF cadastrado permite consultar os alunos vinculados e iniciar inscrições.

A opção vem ligada quando ainda não há preferência salva, inclusive ao atualizar da versão 1.1.2. Escolha o modo desejado e salve. Quando ligada, o salvamento exige ID e token NX válidos no formato esperado, preservando-os se os campos forem deixados em branco. Essa conferência de preenchimento não substitui um teste real de entrega.

Ao mudar a opção, as sessões de inscrição anteriores deixam de valer, mesmo se voltar ao modo anterior depois. O responsável precisa consultar o CPF novamente. A decisão de exigir código é conferida no servidor em alunos, catálogo e pagamento; não depende de uma opção enviada pelo navegador.

Em ambos os modos, a consulta usa o espelho local da TOTVS, confere CPF/vínculo, aplica limites por IP e CPF e exige HTTPS. Remover o vínculo de um aluno impede selecioná-lo em sessões existentes. Com WhatsApp ligado, uma mudança de telefone também exige nova validação. O telefone usado é o associado ao mesmo CPF; o telefone de outro responsável nunca é utilizado. A senha do painel de acompanhamento continua obrigatória.

## 5. Atividades, valores e cobranças

Cada atividade tem valor único por inscrição, descrição, turmas autorizadas e situação ativa/inativa. A seleção das turmas usa a lista retornada pela TOTVS. Inativar uma atividade impede novas compras, preservando o histórico.

O responsável escolhe **um aluno por vez**, marca quantas atividades quiser e vê o total. Pode repetir para outro aluno vinculado. Não há mensalidade recorrente: o valor é o total da inscrição. Se a atividade for oferecida em um novo período, cadastre uma nova atividade ou use o IDPERLET correspondente.

São oferecidos PIX, boleto e cartão. O checkout hospedado K8 processa os dados de pagamento; o WordPress não recebe dados do cartão. Parcelamento de cartão depende do checkout/contrato K8. Esta versão não gera carnê para atividades.

O servidor confere novamente vínculo, turma, atividade ativa e preço. Não aceita nome, CPF ou valor arbitrários do navegador. Os valores são somados em centavos. Alterações posteriores de preço não mudam os pedidos existentes.

Descrição enviada ao K8, exatamente nesse formato:

```
Nome do Aluno: Maria Exemplo
Atividades escolhidas: Ballet, Robótica, Futsal
```

Também envia `NumeroOperacao=JAO-{ID do pedido}` para auxiliar a conferência. Isso não é tratado como garantia de idempotência do K8.

O plugin reaproveita as configurações e tabelas do K8 Financeiro. Se não houver credenciais de Cobranças no financeiro e houver opções do plugin de pagamento antigo, importa essas credenciais na primeira ativação. Confira o ambiente antes de criar cobranças. Para sandbox, confirme com o K8 o endpoint e a URL de checkout adequados; o original não confirmava o endereço do sandbox. A URL base do checkout é ajustável no painel.

### Duplicidade e conferência

Há trava por aluno e reserva única por atividade. Cliques repetidos reutilizam uma cobrança existente do mesmo pedido, sem gerar nova cobrança. Outro responsável do mesmo aluno também não consegue comprar a mesma atividade duas vezes.

Em timeout ou resposta inconclusiva, o pedido permanece **Conferir** e suas atividades ficam reservadas. Não há repetição automática de criação. No administrador, abra **Atividades Journey → Conferência de pedidos**:

- Se a cobrança existe: informe seu ID. O plugin consulta o K8 e confere responsável, descrição e valor antes de vincular.
- Se nenhuma cobrança foi criada: confirme isso no K8 e marque a opção correspondente para liberar nova inscrição.

O painel externo não pode fazer essa liberação. Não há cancelamento financeiro automático. Cancelamentos devem ser feitos no K8; quando a consulta reconhecer `Cancelada`, o plugin libera as atividades.

## 6. Acompanhamento

O shortcode do painel mostra apenas os pedidos de atividades criados por este plugin, com filtros por turma, atividade, situação, aluno/RA/responsável e período. Inclui valores pagos nominais, valores a receber, pedidos para conferir, consulta individual ao K8 e exportação CSV de todos os resultados filtrados.

Ao filtrar uma atividade, o indicador soma o valor **do pedido completo** que contém aquela atividade. Não representa faturamento isolado da atividade. O CSV possui uma linha por pedido, com todas as atividades. Por privacidade, o painel externo e esse CSV não exibem CPF nem telefone.

O financeiro completo **K8 Financeiro**, dentro do WordPress, mantém a consulta de Cobranças, conciliação Pay, taxas e detalhes das parcelas. Cobranças antigas continuam nas tabelas existentes; pedidos antigos de material não são convertidos em inscrições de atividades.

A cada cinco minutos, o WP-Cron consulta um lote de até dez pedidos, priorizando os há mais tempo sem consulta. Pedidos pagos também podem ser reconsultados. O atraso depende da quantidade de pedidos e da execução do WP-Cron; o botão **Consultar K8** atualiza individualmente. Não marque como pago apenas porque o checkout foi aberto ou porque houve redirecionamento.

Os estados reconhecidos são `Pendente`, `Paga`, `Inadimplente` e `Cancelada`. Estados não reconhecidos são sinalizados para conferência, sem converter automaticamente em pagamento confirmado. Não há webhook público que aceite alterações não autenticadas.

Para execução regular, configure o cron do servidor para chamar o WP-Cron a cada minuto, com o comando/caminho adequados à sua hospedagem. O WP-Cron padrão depende das visitas ao site.

## 7. Homologação antes de publicar

1. Teste a TOTVS e confira a turma de dois alunos e os respectivos responsáveis.
2. Cadastre duas atividades para uma turma e outra atividade para uma turma diferente.
3. Acesse a página pelo celular: teste os dois modos. Ligado: CPF → confirmação de telefone → Continuar → código → alunos. Desligado: CPF → alunos, inclusive para responsável sem telefone ou com telefone divergente.
4. Confira que não aparece aluno de outro responsável nem atividade de outra turma.
5. Selecione duas atividades e confira a soma. Teste também um CPF com mais de um aluno.
6. Gere uma cobrança no ambiente de homologação K8; confira nome do aluno e atividades na descrição, total, responsável e forma de pagamento.
7. Abra o painel em uma janela anônima: dados só podem aparecer depois de entrar com a senha própria.
8. Consulte o pedido após um pagamento de teste e confira o estado retornado pelo K8 e a exportação CSV.
9. Confirme as credenciais e a URL de checkout do ambiente de produção antes de liberar inscrições reais.

Código e telas podem ser validados localmente, mas consulta real à TOTVS, entrega WhatsApp e pagamento só podem ser homologados no seu ambiente. Não foram fornecidos URL/credenciais TOTVS, credenciais NX/K8 nem amostra real da DADOSREMAT.2 neste pedido.

## 8. Dados e manutenção

- Dados novos: tabelas `jao_students`, `jao_guardians`, `jao_activities`, `jao_orders`, `jao_reservations`, `jao_bird_sessions`, `jao_bird_limits`, com o prefixo do WordPress.
- Financeiro preservado: tabelas e opções `k8f_*` existentes.
- Credenciais novas criptografadas com chave derivada dos salts WordPress; trocar os salts exige salvar novamente as credenciais.
- Tokens de sessão guardados somente como HMAC; sessões expiradas e limitadores são removidos periodicamente. As tabelas mantêm os nomes antigos `jao_bird_*` para compatibilidade. Códigos OTP são guardados como HMAC e utilizados somente com WhatsApp ligado.
- Desativação remove os agendamentos, mas não apaga inscrições, configurações nem financeiro.
- Não há rotina de exclusão definitiva de dados nesta versão. O histórico anterior fica preservado para backup e consulta.
- O pacote usa tabelas do site atual; ative por site em instalações multisite.

## Referência da integração TOTVS

A rota e o formato de parâmetros seguem a documentação oficial:
https://centraldeatendimento.totvs.com/hc/pt-br/articles/4402123363607-Cross-Segmentos-Backoffice-RM-Integra%C3%A7%C3%A3o-API-Consumo-API-ConsultaSQL

A integração K8 foi adaptada do código fornecido no ZIP. O módulo financeiro foi incorporado mantendo as opções, tabelas e comportamento de Cobranças/Pay, com adição do método autenticado de criação de clientes e cobranças usado pelo novo fluxo.
