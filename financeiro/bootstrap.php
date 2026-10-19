<?php
if (!defined('ABSPATH')) { exit; }
define('K8F_FILE', __FILE__);
require_once __DIR__ . '/includes/Core.php';
require_once __DIR__ . '/includes/Api.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Sync.php';
require_once __DIR__ . '/includes/Pay.php';
require_once __DIR__ . '/includes/PayAdmin.php';
require_once __DIR__ . '/includes/Admin.php';


add_filter('cron_schedules', function ($s) { $s['k8f_pay_five'] = ['interval' => 300, 'display' => 'K8 Pay: cinco minutos']; $s['k8f_minute'] = ['interval' => 60, 'display' => 'K8 Financeiro: um minuto']; return $s; });
add_action('k8f_tick', ['K8F\\Sync', 'cron']);
add_action('plugins_loaded', function () {
    if (get_option('k8f_schema') !== '2') { \K8F\Store::install(); }
    if (!wp_next_scheduled('k8f_tick')) { wp_schedule_event(time() + 60, 'k8f_minute', 'k8f_tick'); }
    \K8F\Admin::boot();
    \K8F\PayAdmin::boot();
});
