# Journey — Atividades Opcionais

Plugin WordPress 1.1.1. WordPress 6.4+, PHP 8.0+.

## Instalação e atualização

Instale o ZIP com a pasta `journey-atividades` pelo painel do WordPress e substitua a versão anterior, sem desinstalar. A versão 1.1.0 não tem atualizador: essa primeira troca é manual.

Após instalar a 1.1.1, o WordPress consulta este repositório público (branch `main`) e oferece versões superiores em **Plugins** e **Painel → Atualizações**. Na linha do plugin, **Verificar atualizações** força uma consulta. Permissão `update_plugins` e nonce são exigidos. A consulta tem cache de uma hora; falhas têm espera de cinco minutos. Autoatualização só ocorre se habilitada pelo administrador no WordPress.

Para publicar uma próxima versão, aumente `Version` e `JAO_VERSION` em `journey-atividades.php`, valide as alterações e envie para `main`. Commits sem aumento de versão não geram atualização. O arquivo principal deve continuar na raiz do repositório. O download fica fixado no commit que forneceu a versão; a pasta instalada é preservada. Não é necessário criar Release nem configurar token enquanto o repositório for público. Se ficar privado, este atualizador deixará de funcionar.

O servidor precisa acessar `api.github.com`, `raw.githubusercontent.com` e os destinos HTTPS de download do GitHub (incluindo `codeload.github.com`). Erros de rede ou limite de API não oferecem um pacote inválido. O link manual informa falha ao consultar.

Consulte [LEIA-ME.md](LEIA-ME.md) para instalação, integrações, shortcodes e homologação.

## Mapa do código

| Arquivo/módulo | Responsabilidade |
|---|---|
| `journey-atividades.php` | Inicialização, conflitos com plugins antigos, instalação e cron |
| `includes/core.php` | Tabelas, configurações, travas e despacho AJAX |
| `includes/totvs.php` | Consulta DADOSREMAT.2 e sincronização transacional do espelho local |
| `includes/auth.php` | CPF, WhatsApp NX, OTP, limites e vínculo com alunos |
| `includes/checkout.php` | Catálogo por turma, reservas, cobrança K8 e consulta de pagamentos |
| `includes/dashboard.php` | Sessão própria, filtros, indicadores e exportação CSV |
| `includes/admin.php` | Cadastro de atividades, integrações e conferência de cobranças |
| `includes/front.php`, `assets/` | Shortcodes e interface pública |
| `includes/updater.php` | Atualização nativa do WordPress a partir do GitHub |
| `financeiro/` | Cliente K8, tabelas financeiras, sincronização Cobranças/Pay e painéis |

Fluxo: TOTVS → tabelas locais de alunos/responsáveis → CPF + OTP WhatsApp → aluno → atividades autorizadas por turma → reserva local e pedido → cobrança K8 → checkout hospedado → consulta de situação e dashboard.

Os valores são armazenados em centavos. O pedido preserva os dados e valores da compra. Reservas impedem a compra duplicada de uma atividade pelo mesmo aluno. Uma resposta inconclusiva do K8 mantém o pedido em conferência, sem repetição automática de cobrança. Remoções no espelho não apagam o histórico financeiro.

## Escopo de 1.1.1

Inclui apenas mecanismo de atualização, metadados de versão, cache dos assets vinculado à versão e documentação. Não altera schema, valores, contratos de APIs ou regras de inscrição. O plugin recebido possui senha inicial fixa do dashboard; altere-a em **Atividades Journey → Integrações** antes de disponibilizar o painel.

Os relatos de testes da versão original estão em `VALIDACAO.md`. Eles não constituem execução nova nesta versão. Esta publicação ainda requer homologação em WordPress real, incluindo atualização individual/em lote, conservação da pasta e manutenção da ativação, configurações e pedidos. Não foram feitas cobranças ou mensagens reais nesta alteração.
