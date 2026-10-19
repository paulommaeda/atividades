<?php
if (!defined('ABSPATH')) exit;
function jao_install() {
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $cc=$wpdb->get_charset_collate();
    $schemas=[
    'jao_students'=>"student_key varchar(64) NOT NULL, ra varchar(100) NOT NULL, nome_aluno varchar(255) NOT NULL, turma varchar(150) NOT NULL, coligada varchar(50) NOT NULL, periodo varchar(100) NOT NULL, PRIMARY KEY  (student_key), KEY turma (turma)",
    'jao_guardians'=>"link_hash varchar(64) NOT NULL, student_key varchar(64) NOT NULL, cpf varchar(11) NOT NULL, nome varchar(255) NOT NULL, telefone varchar(20) NOT NULL, telefone_status varchar(20) NOT NULL, PRIMARY KEY  (link_hash), KEY cpf (cpf), KEY student_key (student_key)",
    'jao_bird_sessions'=>"token_hash varchar(64) NOT NULL, cpf varchar(11) NOT NULL, phone varchar(20) NOT NULL, bird_id varchar(100) NOT NULL, state varchar(16) NOT NULL, attempts int unsigned NOT NULL DEFAULT 0, expires bigint unsigned NOT NULL, PRIMARY KEY  (token_hash), KEY expires (expires)",
    'jao_bird_limits'=>"bucket varchar(64) NOT NULL, hits int unsigned NOT NULL DEFAULT 0, expires bigint unsigned NOT NULL, PRIMARY KEY  (bucket), KEY expires (expires)",
    'jao_activities'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(200) NOT NULL, description text NOT NULL, price bigint unsigned NOT NULL, classes longtext NOT NULL, active tinyint NOT NULL DEFAULT 1, PRIMARY KEY  (id)",
    'jao_orders'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, student_key varchar(64) NOT NULL, ra varchar(100) NOT NULL, student varchar(255) NOT NULL, class varchar(150) NOT NULL, cpf varchar(11) NOT NULL, guardian varchar(255) NOT NULL, items longtext NOT NULL, total bigint unsigned NOT NULL, method varchar(20) NOT NULL, state varchar(40) NOT NULL, remote_id varchar(100) NOT NULL DEFAULT '', scope varchar(64) NOT NULL, created_at datetime NOT NULL, synced_at datetime DEFAULT NULL, error text NOT NULL, PRIMARY KEY  (id), KEY student_key (student_key), KEY remote_scope (remote_id,scope), KEY state (state)",
    'jao_reservations'=>"student_key varchar(64) NOT NULL, activity_id bigint unsigned NOT NULL, order_id bigint unsigned NOT NULL, PRIMARY KEY  (student_key,activity_id), KEY order_id (order_id)"
    ];
    foreach ($schemas as $name=>$sql) {
        // dbDelta requires one definition per line.
        $sql=preg_replace('/, (?=[a-zA-Z_]+ (?:varchar|bigint|int|tinyint|text|longtext|datetime)|PRIMARY KEY|KEY )/', ",\n", $sql);
        dbDelta("CREATE TABLE {$wpdb->prefix}$name (\n$sql\n) ENGINE=InnoDB $cc;");
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.$name)))) wp_die('Falha criando tabelas de atividades.');
    }
    if (!get_option('jao_dashboard_hash')) add_option('jao_dashboard_hash',wp_hash_password('Journey15530'),'','no');
    if (!get_option('jao_secrets')) {
        $old=['nx_id'=>get_option('sba_nx_api_id',''),'nx_token'=>get_option('sba_nx_token','')];
        update_option('jao_secrets',\K8F\Core::seal(wp_json_encode($old)),false);
    }
    if (empty(\K8F\Core::settings()['credentials']) && get_option('k8p_client_id')) {
        $s=\K8F\Core::settings(); $s['credentials']=\K8F\Core::seal(wp_json_encode(['id'=>get_option('k8p_client_id'),'secret'=>get_option('k8p_client_secret'),'aes'=>get_option('k8p_aes_key')]));
        $s['environment']=get_option('k8p_producao')==='1'?'production':'sandbox'; update_option('k8f_settings',$s,false);
    }
    delete_transient('jao_totvs_rows');
    update_option('jao_schema','2',false);
}
function jao_settings() { return (array)get_option('jao_settings',[]); }
function jao_secret($key) {
    $raw=get_option('jao_secrets','');
    $all=$raw?json_decode(\K8F\Core::unseal($raw),true):[];
    return (string)($all[$key]??'');
}
function jao_money($cents) { return 'R$ '.number_format((int)$cents/100,2,',','.'); }
function jao_lock($key) {
    global $wpdb; $key='jao_'.substr(hash('sha256',$wpdb->prefix.'|'.$key),0,50);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1') throw new RuntimeException('Há uma solicitação em andamento. Aguarde e tente novamente.');
    return $key;
}
function jao_unlock($key) { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
function jao_db_update($table,$data,$where) { global $wpdb; if ($wpdb->update($wpdb->prefix.$table,$data,$where)===false) throw new RuntimeException('Falha ao salvar. Procure a secretaria antes de tentar novamente.'); }
function jao_dispatch() {
    try {
        $ip=jao_ajax_guard(); $op=jao_post('op');
        $routes=['identify'=>'jao_identify','students'=>'jao_students','catalog'=>'jao_catalog','checkout'=>'jao_checkout','dash_session'=>'jao_dash_session','dash_login'=>'jao_dash_login','dash_query'=>'jao_dash_query','dash_refresh'=>'jao_dash_refresh','dash_export'=>'jao_dash_export','dash_logout'=>'jao_dash_logout'];
        if (!isset($routes[$op])) throw new RuntimeException('Operação inválida.');
        $data=$routes[$op](); wp_send_json_success($data);
    } catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],400); }
}
add_action('wp_ajax_jao','jao_dispatch');
add_action('wp_ajax_nopriv_jao','jao_dispatch');
