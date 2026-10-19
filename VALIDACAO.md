# Validação — versão 1.1.2

Data: 19/10/2026.

- Sintaxe do JavaScript verificada com `node --check`.
- Fluxo de interface executado em Node com DOM e respostas HTTP simulados: CPF formatado → consulta direta → dois alunos → seleção do aluno → catálogo; token enviado nas operações seguintes; Sair limpa o token do navegador; CPF não localizado mantém a tela de identificação; dashboard exige sua senha. Nenhuma requisição de envio/validação WhatsApp foi feita pelo fluxo.
- Revisão estática: identificação depende de CPF válido com vínculo local; sessão de quinze minutos com token em hash; vínculo relido em alunos, catálogo e checkout; estados de sessões antigas rejeitados; rotas OTP removidas; módulo de acesso não contém chamadas HTTP. Essas verificações são inspeção de código, não execução PHP.
- Comparação dos arquivos confirma que checkout, sincronização TOTVS, dashboard e módulos financeiros não tiveram alterações nesta versão.
- Integridade do ZIP, pasta raiz e número de versão conferidos.

Limites: não há PHP ou navegador instalado neste ambiente. Não foram executados lint PHP, teste em WordPress/MySQL real ou chamadas reais TOTVS/K8. Homologar em WordPress: CPF válido e inválido, CPF sem vínculo, responsáveis sem telefone ou com telefone divergente, seleção de outro aluno, expiração da sessão e remoção de vínculo após sincronização. Mensagens WhatsApp não fazem mais parte do fluxo. O painel de acompanhamento continua protegido por senha.

Os registros abaixo se referem à versão recebida e são mantidos como histórico. Os testes antigos de OTP/telefone não descrevem mais a regra de acesso da versão 1.1.2.

---

# Validação — versão 1.1.0

Data: 23/09/2026.

## Verificado localmente

- Análise sintática de todos os 16 arquivos PHP com parser configurado para PHP 8.0.
- Lint dos mesmos 16 arquivos em runtime PHP 8.5.10 via WebAssembly.
- Sintaxe dos três arquivos JavaScript.
- 65 verificações de comportamento com funções WordPress, banco e HTTP simulados: CPF, telefone, mapeamento TOTVS, isolamento por coligada/período, conflitos de turma/telefone, autorização por CPF, obrigatoriedade de OTP, bloqueio de aluno forjado, alteração do telefone, sessão e senha do dashboard, CSRF, criptografia de credenciais, decodificação K8, CSV, descrição da cobrança, soma em centavos, preço adulterado, turma não autorizada, reutilização de pedido, reserva de atividades, timeout de cobrança sem repetição e geração do schema.
- Fluxo JavaScript executado em Chromium com respostas de API simuladas: CPF → confirmação dos quatro dígitos → envio somente após Continuar → OTP → alunos → duas atividades → total de R$ 50,00 → abertura do checkout.
- Tela mobile com viewport de 390 × 844: sem transbordamento horizontal. Revisão visual da seleção de atividades.
- Login próprio do dashboard, independente de usuário WordPress, com renderização da lista de inscrições. Viewport desktop de 1440 × 1000.
- Nenhuma exceção JavaScript durante os fluxos simulados.

## Verificação adicional do espelho local — 1.1.0

Mais 28 verificações, totalizando 93, cobrem primeira carga, leitura local por CPF sem chamada HTTP, turmas locais, uso do espelho com a TOTVS indisponível, preservação da data de sucesso em falhas, inclusão de alunos, atualização de turma/telefone, remoção de aluno/vínculo, rollback em falha de gravação, rejeição de retorno vazio automático, confirmação manual de espelho vazio, rejeição de paginação/retorno inválido, deduplicação, conflito de telefone e alterações do agendamento.

Os testes adicionais executam as consultas locais e as transações em um banco SQLite em memória por meio de um adaptador de testes. Uma falha de inserção após o DELETE foi induzida e o rollback preservou as duas tabelas anteriores. O contador de requisições HTTP confirma ausência de chamadas à TOTVS durante a leitura do CPF, inclusive com espelho vazio e durante indisponibilidade da API. SQLite não substitui a homologação de InnoDB/MySQL e WP-Cron na hospedagem.

## Limites dessa validação

Não foi instalado em um WordPress real nem executado contra MySQL real. Os testes originais de banco usam um adaptador simulado; os testes novos do espelho usam SQLite real em memória. A geração do schema foi verificada, mas dbDelta, InnoDB, travas e WP-Cron precisam ser confirmados na hospedagem.

Nenhuma mensagem WhatsApp ou cobrança real foi criada. Não foram fornecidos endereço e credenciais TOTVS/NX/K8, nem amostra real da consulta DADOSREMAT.2. A homologação dessas conexões, nomes de colunas, estados K8, sandbox, URLs de checkout e entrega WhatsApp deve seguir o roteiro do LEIA-ME.md.

As tabelas e configurações do financeiro original foram mantidas. Não houve exclusão ou conversão de dados antigos de material didático.
