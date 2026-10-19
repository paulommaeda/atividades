<?php
if (!defined('ABSPATH')) exit;
add_action('template_redirect',function(){
    if (!is_singular()) return; $post=get_post();
    if ($post && (has_shortcode($post->post_content,'journey_atividades')||has_shortcode($post->post_content,'journey_atividades_dashboard'))) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true); nocache_headers();
    }
});
function jao_front($dashboard=false) {
    wp_enqueue_style('jao',plugins_url('assets/app.css',JAO_FILE),[],JAO_VERSION);
    wp_enqueue_script('jao',plugins_url('assets/app.js',JAO_FILE),[],JAO_VERSION,true);
    wp_localize_script('jao','JAO',['url'=>admin_url('admin-ajax.php')]);
    $mode=$dashboard?'dashboard':'enroll';
    return '<section class="jao" data-mode="'.$mode.'"><div class="jao-brand">JOURNEY · ATIVIDADES OPCIONAIS</div><h2>'.($dashboard?'Acompanhamento de inscrições':'Novas experiências para aprender').'</h2><p class="jao-intro">'.($dashboard?'Inscrições, atividades e situação dos pagamentos.':'Escolha as atividades disponíveis para a turma do seu filho.').'</p><div class="jao-notice" role="status" aria-live="polite"></div><div class="jao-view"></div><noscript>Ative o JavaScript para continuar.</noscript></section>';
}
add_shortcode('journey_atividades',function(){return jao_front(false);});
add_shortcode('journey_atividades_dashboard',function(){return jao_front(true);});
