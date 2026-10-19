<?php
/**
 * Plugin Name: Journey — Atividades Opcionais
 * Description: TOTVS, validação WhatsApp opcional, inscrições por turma, K8 Cobranças e acompanhamento financeiro em um plugin.
 * Version: 1.1.3
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Plugin URI: https://github.com/paulommaeda/atividades
 * Update URI: https://github.com/paulommaeda/atividades
 * Author: Journey
 */
if (!defined('ABSPATH')) exit;
define('JAO_FILE', __FILE__);
define('JAO_VERSION', '1.1.3');
require_once __DIR__.'/includes/updater.php';
function jao_conflicts() {
    $active = array_merge((array)get_option('active_plugins', []), array_keys((array)get_site_option('active_sitewide_plugins', [])));
    return array_filter($active, static function($p) { return preg_match('~^(sistema-alunos-bird|k8-cobrancas|k8-financeiro)/~', $p); });
}
register_activation_hook(__FILE__, function() {
    if (jao_conflicts()) wp_die('Desative os três plugins anteriores antes de ativar Journey — Atividades Opcionais. Os dados antigos serão preservados.');
    if (!extension_loaded('openssl')) wp_die('Ative a extensão OpenSSL do PHP.');
    jao_install();
    \K8F\Store::install();
});
if (jao_conflicts()) {
    add_action('admin_notices', function() { echo '<div class="notice notice-error"><p>Journey Atividades está pausado: desative Sistema Alunos, K8 Cobranças e K8 Financeiro antigos.</p></div>'; });
    return;
}
require_once __DIR__.'/financeiro/bootstrap.php';
foreach (['core','totvs','auth','checkout','dashboard','admin','front'] as $module) require_once __DIR__.'/includes/'.$module.'.php';
register_deactivation_hook(__FILE__, function() {
    foreach (['jao_tick','jao_totvs_sync','k8f_tick','k8f_pay_tick'] as $hook) wp_clear_scheduled_hook($hook);
});
add_action('init', function() {
    if (get_option('jao_schema') !== '2') jao_install();
    jao_schedule_mirror();
    if (!wp_next_scheduled('jao_tick')) wp_schedule_event(time()+60, 'k8f_pay_five', 'jao_tick');
});
add_action('jao_tick', 'jao_refresh_orders');
