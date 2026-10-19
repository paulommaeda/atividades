<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class Admin {
    public static function boot(): void {
        add_action('admin_menu', function () {
            add_menu_page('K8 Financeiro', 'K8 Financeiro', 'manage_options', 'k8f', [self::class, 'page'], 'dashicons-chart-area', 32);
            add_submenu_page('k8f', 'Configurações', 'Configurações', 'manage_options', 'k8f-settings', [self::class, 'settings']);
        });
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'toplevel_page_k8f') { return; }
            wp_enqueue_style('k8f', plugins_url('assets/dashboard.css', K8F_FILE), [], '1.2.0');
            wp_enqueue_script('k8f', plugins_url('assets/dashboard.js', K8F_FILE), [], '1.2.0', true);
            wp_localize_script('k8f', 'K8F', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('k8f'), 'settings' => admin_url('admin.php?page=k8f-settings')]);
        });
        add_action('wp_ajax_k8f', [self::class, 'ajax']);
        add_action('admin_post_k8f_save', [self::class, 'save']);
    }
    private static function guard(): void { if (!current_user_can('manage_options')) { wp_die('Acesso restrito.', '', ['response' => 403]); } }
    public static function ajax(): void {
        self::guard(); check_ajax_referer('k8f', 'nonce'); nocache_headers();
        try {
            $action = sanitize_key($_POST['op'] ?? '');
            $f = json_decode(wp_unslash($_POST['filters'] ?? '{}'), true);
            if (!is_array($f)) { $f = []; }
            switch ($action) {
                case 'export': self::export($f); exit;
                case 'schema': $data = self::schema(absint($_POST['id'] ?? 0)); break;
                case 'query': $data = Store::query($f); break;
                case 'sync': $data = Sync::step(!empty($_POST['restart'])); break;
                case 'refresh': $data = Sync::refresh(absint($_POST['id'] ?? 0)); break;
                case 'test': $data = ['message' => 'Conexão válida. Consulta de cobranças autorizada.', 'sample_count' => count((new Api())->page(0))]; break;
                default: throw new \RuntimeException('Operação inválida.');
            }
            wp_send_json_success($data);
        } catch (\Throwable $e) { wp_send_json_error(['message' => $e->getMessage()], 400); }
    }
    private static function schema(int $id): array {
        global $wpdb; $t = Store::table();
        $remote = $wpdb->get_var($wpdb->prepare("SELECT remote_id FROM $t WHERE scope=%s AND id=%d", Core::scope(), $id));
        if (!$remote) { throw new \RuntimeException('Cobrança não encontrada.'); }
        $data = (new Api())->charge($remote); $paths = [];
        $walk = function ($value, $path, $depth) use (&$walk, &$paths) {
            if ($depth > 8 || count($paths) >= 300) { return; }
            if (is_array($value)) { foreach ($value as $k => $v) { $walk($v, $path === '' ? (string) $k : $path . '.' . $k, $depth + 1); } }
            else { $paths[] = ['path' => $path, 'type' => gettype($value)]; }
        };
        $walk($data, '', 0); return $paths;
    }
    private static function export(array $f): void {
        global $wpdb; $t = Store::table(); $c = Store::customers();
        [$w, $p] = Store::where($f, Core::scope());
        $base = "FROM $t c LEFT JOIN $c u ON u.scope=c.scope AND u.remote_id=c.customer_id WHERE $w";
        $max = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(c.id) $base", $p));
        if ($wpdb->last_error) { throw new \RuntimeException('Falha preparando exportação.'); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="k8-cobrancas.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID K8','Número','Cliente','CPF/CNPJ','Descrição','Situação','Tipo','Métodos oferecidos','Método utilizado','Valor nominal (R$)','Pago informado (R$)','Tarifa informada (R$)','Líquido informado (R$)','Criação','Vencimento','Pagamento informado','Sincronizado em','Origem do pago','Origem da tarifa','IDs Pay','Status Pay','Métodos Pay','Transações vinculadas','Parcelas retornadas (vínculo único)','Taxas Pay (R$; vínculo único)','Liquidado Pay (0/1)','Liquidação Pay','Disponibilização Pay','Parcelas Pay (JSON; valores em centavos)'], ';', '"', '');
        $last = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT c.*,u.document $base AND c.id>%d AND c.id<=%d ORDER BY c.id ASC LIMIT 500", array_merge($p, [$last, $max])), ARRAY_A);
            if ($wpdb->last_error) { fputcsv($out, ['ERRO: exportação interrompida; gere novamente.'], ';', '"', ''); break; }
            foreach (Store::enrich($rows) as $r) {
                $values = [];
                foreach (['remote_id','seq','customer','document','description','state','kind','methods','method_paid','amount','paid','fee','net','created_at','due_at','paid_at','synced_at','paid_source','fee_source','pay_ids','pay_state','pay_kind','pay_count','pay_installments','pay_fees','pay_settled','pay_settled_at','pay_available_at','pay_parts'] as $key) {
                    $v = $r[$key];
                    if (in_array($key, ['amount','paid','fee','net','pay_fees'], true) && $v !== null) { $v = number_format((int) $v / 100, 2, ',', ''); }
                    $values[] = Core::csv($v);
                }
                fputcsv($out, $values, ';', '"', ''); $last = (int) $r['id'];
            }
        } while (count($rows) === 500);
        fclose($out);
    }
    public static function save(): void {
        self::guard(); check_admin_referer('k8f_save');
        try {
            if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('Ative a extensão OpenSSL no PHP.'); }
            $s = Core::settings();
            if (!empty($_POST['reuse'])) {
                $c = ['id' => get_option('k8p_client_id', ''), 'secret' => get_option('k8p_client_secret', ''), 'aes' => get_option('k8p_aes_key', '')];
                $s['environment'] = get_option('k8p_producao', '0') === '1' ? 'production' : 'sandbox';
            } else {
                $c = empty($s['credentials']) ? ['id' => '', 'secret' => '', 'aes' => ''] : Core::credentials();
                foreach (['id','secret','aes'] as $field) {
                    $value = trim((string) wp_unslash($_POST[$field] ?? ''));
                    if ($value !== '') { $c[$field] = $value; }
                }
                $s['environment'] = ($_POST['environment'] ?? '') === 'sandbox' ? 'sandbox' : 'production';
            }
            if (empty($c['id']) || empty($c['secret']) || empty($c['aes'])) { throw new \RuntimeException('As três credenciais são obrigatórias.'); }
            $s['credentials'] = Core::seal(wp_json_encode($c));
            $s['automatic'] = !empty($_POST['automatic']);
            foreach (self::maps() as $name => $config) {
                $v = trim((string) wp_unslash($_POST[$name] ?? $config[1]));
                if (!preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', $v)) { throw new \RuntimeException('Caminho de campo inválido: ' . $config[0]); }
                $s[$name] = $v;
            }
            update_option('k8f_settings', $s, false);
            wp_safe_redirect(admin_url('admin.php?page=k8f-settings&saved=1')); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), 'Configuração K8', ['back_link' => true]); }
    }
    private static function maps(): array {
        return ['map_paid' => ['Valor pago','DadosPagamento.ValorPago'], 'map_fee' => ['Tarifa','DadosPagamento.ValorTaxa'], 'map_net' => ['Valor líquido','DadosPagamento.ValorLiquido'], 'map_date' => ['Data do pagamento','DadosPagamento.DataPagamento'], 'map_method' => ['Método utilizado','DadosPagamento.FormaPagamento']];
    }
    public static function settings(): void {
        self::guard(); $s = Core::settings(); ?>
        <div class="wrap"><h1>K8 Financeiro · Configurações</h1>
        <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p>Configuração salva. Abra o dashboard, teste a conexão e inicie a sincronização.</p></div><?php endif; ?>
        <p>Conexão com a API <strong>K8 Cobranças</strong>. Configure Cobranças aqui e K8 Pay no formulário abaixo. Os extratos da conta digital continuam sendo uma API distinta.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="k8f_save"><?php wp_nonce_field('k8f_save'); ?>
        <table class="form-table"><tbody>
        <tr><th>Credenciais existentes</th><td><label><input type="checkbox" name="reuse" value="1"> Copiar as credenciais e o ambiente do plugin de material didático</label><p class="description">Cria uma configuração própria. Não altera o plugin antigo.</p></td></tr>
        <?php foreach (['id' => 'Client ID','secret' => 'Client Secret','aes' => 'Chave AES'] as $key => $label): ?>
        <tr><th><label for="k8f-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" id="k8f-<?php echo esc_attr($key); ?>" type="password" name="<?php echo esc_attr($key); ?>" autocomplete="new-password" value="" placeholder="<?php echo empty($s['credentials']) ? 'Preencha ou use a opção de copiar' : 'Em branco mantém o valor salvo'; ?>"></td></tr>
        <?php endforeach; ?>
        <tr><th>Ambiente</th><td><select name="environment"><option value="production" <?php selected($s['environment'] ?? 'production','production'); ?>>Produção</option><option value="sandbox" <?php selected($s['environment'] ?? '', 'sandbox'); ?>>Sandbox (endereço herdado; confirmar com K8)</option></select><p class="description">Dados isolados por Client ID e ambiente. Trocar a conta não mistura os históricos.</p></td></tr>
        <tr><th>Atualização automática</th><td><label><input type="checkbox" name="automatic" value="1" <?php checked(!empty($s['automatic'])); ?>> Habilitar sincronização periódica via WP-Cron</label><p class="description">Uma página por execução; nova varredura 15 minutos após terminar o ciclo. Em sites sem visitas, configure cron do servidor.</p></td></tr>
        </tbody></table>
        <details><summary><strong>Mapeamento dos dados de pagamento</strong></summary>
        <p>A documentação descreve DadosPagamento, mas não detalha todos os nomes internos. Os caminhos abaixo são candidatos e precisam ser conferidos no retorno da sua conta. Campos ausentes aparecem como não informados. Após ajustar, reinicie a sincronização.</p>
        <table class="form-table"><tbody><?php foreach (self::maps() as $name => $config): ?>
        <tr><th><?php echo esc_html($config[0]); ?></th><td><input class="regular-text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($s[$name] ?? $config[1]); ?>"></td></tr>
        <?php endforeach; ?></tbody></table></details>
        <?php submit_button('Salvar configuração'); ?></form>
        <?php PayAdmin::settings(); ?>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=k8f')); ?>">Abrir dashboard</a></p></div>
        <?php
    }
    public static function page(): void {
        self::guard(); ?>
        <div id="k8f" class="wrap">
            <header class="kf-header"><div><span class="kf-eyebrow">K8 · GESTÃO FINANCEIRA</span><h1>Suas cobranças, em perspectiva.</h1><p>Cobranças e gateway K8 Pay · valores e parcelamento</p></div><a class="kf-button" href="<?php echo esc_url(admin_url('admin.php?page=k8f-settings')); ?>">Configurações</a></header>
            <div class="kf-actions"><button id="kf-test">Testar conexão</button><button id="kf-sync" class="primary">Sincronizar / retomar</button><button id="kf-restart">Reiniciar varredura</button><button id="kf-stop" hidden>Pausar nesta tela</button><span id="kf-sync-status" role="status">Carregando…</span></div>
            <div id="kf-message" role="alert" hidden></div>
            <p class="kf-note">Indicadores calculados sobre os registros sincronizados e os filtros aplicados. Pagamento não significa liquidação bancária. O valor em aberto é nominal e não deduz pagamentos parciais.</p>
            <form id="kf-filters" class="kf-panel kf-filters">
                <label class="kf-search">Buscar<input name="q" placeholder="Cliente, CPF/CNPJ, descrição, ID ou número"></label>
                <label>Data de referência<select name="date_field"><option value="created_at">Criação</option><option value="due_at">Vencimento</option><option value="paid_at">Pagamento informado</option></select></label>
                <label>De<input type="date" name="from"></label><label>Até<input type="date" name="to"></label>
                <label>Situação<select name="state"><option value="">Todas</option></select></label>
                <label>Tipo<select name="kind"><option value="">Todos</option></select></label>
                <label>Método utilizado<select name="method_paid"><option value="">Todos</option></select></label>
                <label>Método oferecido<select name="methods"><option value="">Todos</option><option>Pix</option><option>Boleto</option><option>Cartao</option></select></label>
                <label>Valor mínimo<input type="number" name="min" min="0" step="0.01" placeholder="R$"></label><label>Valor máximo<input type="number" name="max" min="0" step="0.01" placeholder="R$"></label>
                <label>Vínculo Pay<select name="pay_linked"><option value="">Todos</option><option value="yes">Com vínculo</option><option value="no">Sem vínculo</option></select></label>
                <label>Método Pay<select name="pay_kind"><option value="">Todos</option><option value="Cartao">Cartão</option><option>Pix</option><option>Boleto</option><option>BoletoHibrido</option></select></label>
                <label>Status Pay<input name="pay_state" placeholder="Ex.: Aprovada"></label>
                <label>Liquidação Pay<select name="pay_settled"><option value="">Todas</option><option value="1">Liquidado informado</option><option value="0">Não liquidado informado</option></select></label>
                <label>Mín. parcelas retornadas<input type="number" name="pay_min_installments" min="0" step="1"></label>
                <label>Máx. parcelas retornadas<input type="number" name="pay_max_installments" min="0" step="1"></label>
                <label class="kf-check"><input type="checkbox" name="overdue" value="1"> Somente vencidas em aberto</label>
                <label class="kf-check"><input type="checkbox" name="include_missing" value="1"> Incluir ausentes na última varredura</label>
                <div class="kf-actions"><button class="primary" type="submit">Aplicar filtros</button><button type="reset">Limpar</button></div>
            </form>
            <section id="kf-metrics" class="kf-metrics" aria-label="Indicadores financeiros"></section>
            <section class="kf-charts"><div class="kf-panel"><h2>Situação das cobranças</h2><div id="kf-states"></div></div><div class="kf-panel"><h2>Valor nominal por mês</h2><small>Últimos 12 meses com registros, conforme data de referência.</small><div id="kf-months"></div></div><div class="kf-panel"><h2>Método utilizado</h2><small>Somente o método informado nos dados de pagamento.</small><div id="kf-methods"></div></div></section>
            <section class="kf-panel"><div class="kf-actions"><h2>Cobranças</h2><span id="kf-count"></span><button id="kf-export">Exportar filtros (CSV)</button><button id="kf-export-selected">Exportar selecionadas</button><button id="kf-refresh">Atualizar selecionadas</button><span id="kf-selected">0 selecionadas</span></div>
            <div class="kf-actions"><label>Ordenar por <select id="kf-sort"><option value="created_at">Criação</option><option value="due_at">Vencimento</option><option value="paid_at">Pagamento</option><option value="amount">Valor</option><option value="customer">Cliente</option><option value="state">Situação</option></select></label><select id="kf-direction" aria-label="Direção"><option value="DESC">Decrescente</option><option value="ASC">Crescente</option></select><label>Por página <select id="kf-size"><option>25</option><option>50</option><option>100</option></select></label><details><summary>Colunas</summary><div id="kf-columns"></div></details></div>
            <div class="kf-table-wrap"><table><thead id="kf-head"></thead><tbody id="kf-body"></tbody></table></div>
            <div class="kf-actions kf-pagination"><button id="kf-prev">Anterior</button><span id="kf-page"></span><button id="kf-next">Próxima</button></div></section>
            <?php PayAdmin::panel(); ?>
            <dialog id="kf-dialog"><div class="kf-actions"><h2>Detalhes da cobrança</h2><button id="kf-close">Fechar</button></div><div id="kf-detail"></div><p class="kf-note">Pago e tarifa usam Cob; se ausentes, usam Pay somente com um vínculo único. Líquido mantém o valor informado no Cob. Liquidação vem do gateway e não substitui conciliação bancária.</p></dialog>
        </div>
        <?php
    }
}
