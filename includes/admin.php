<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu',function(){
    add_menu_page('Atividades opcionais','Atividades Journey','manage_options','jao','jao_admin_activities','dashicons-welcome-learn-more',30);
    add_submenu_page('jao','Integrações','Integrações','manage_options','jao-settings','jao_admin_settings');
    add_submenu_page('jao','Conferência','Conferência de pedidos','manage_options','jao-review','jao_admin_review');
});
function jao_admin_guard() { if (!current_user_can('manage_options')) wp_die('Acesso restrito.'); }
function jao_admin_notice($message,$error=false) { echo '<div class="notice notice-'.($error?'error':'success').'"><p>'.esc_html($message).'</p></div>'; }
function jao_admin_settings() {
    jao_admin_guard(); $s=jao_settings();
    echo '<div class="wrap"><h1>Integrações · Atividades Journey</h1>';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_admin_referer('jao_settings');
        try {
            if (isset($_POST['test_totvs'])) {
                $result=jao_sync_mirror(!empty($_POST['confirm_empty'])); jao_admin_notice('Espelho atualizado: '.$result['students'].' alunos e '.$result['guardians'].' vínculos com responsáveis.');
            } else {
                $previous_whatsapp = jao_whatsapp_required($s);
                $s['whatsapp_validation'] = jao_post('whatsapp_validation') === '1' ? '1' : '0';
                if ($previous_whatsapp !== jao_whatsapp_required($s)) $s['auth_revision'] = bin2hex(random_bytes(16));
                foreach (['totvs_url','coligada','sistema','parameters','checkout_url','due_days'] as $key) $s[$key]=jao_post($key);
                foreach (['totvs_url','checkout_url'] as $key) {
                    $url=wp_parse_url($s[$key]);
                    if (($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])) throw new RuntimeException('Informe URLs HTTPS sem credenciais, parâmetros ou fragmentos.');
                    $s[$key]=esc_url_raw($s[$key]);
                }
                if (!ctype_digit($s['coligada'])||!preg_match('/^[A-Za-z]$/D',$s['sistema'])) throw new RuntimeException('Confira coligada e código do sistema TOTVS.');
                $secrets=[]; foreach (['totvs_user','totvs_password','nx_id','nx_token'] as $key) {
                    $v=isset($_POST[$key])&&is_string($_POST[$key])?trim(wp_unslash($_POST[$key])):'';
                    $secrets[$key]=$v!==''?$v:jao_secret($key);
                }
                if (jao_whatsapp_required($s) && (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $secrets['nx_id']) || !$secrets['nx_token'])) throw new RuntimeException('Para ligar a validação pelo WhatsApp, preencha o ID da API NX e o token.');
                foreach (jao_map_defaults() as $key=>$default) $s['maps'][$key]=jao_post('map_'.$key);
                $s['sync_interval']=isset(jao_sync_intervals()[jao_post('sync_interval')])?jao_post('sync_interval'):'hourly';
                update_option('jao_secrets',\K8F\Core::seal(wp_json_encode($secrets)),false); update_option('jao_settings',$s,false);
                if (jao_post('dash_password')!=='') update_option('jao_dashboard_hash',wp_hash_password(wp_unslash($_POST['dash_password'])),false);
                delete_transient('jao_totvs_rows'); jao_schedule_mirror(); jao_admin_notice('Configurações salvas. Clique em Sincronizar agora para atualizar o espelho com estas configurações.');
            }
        } catch (Throwable $e) { $s=jao_settings(); jao_admin_notice($e->getMessage(),true); }
    }
    $mirror=(array)get_option('jao_mirror_status',[]);
    echo '<h2>Espelho local da TOTVS</h2><p>Última sincronização concluída: '.esc_html($mirror['last_success']??'Ainda não realizada').' · Alunos: '.(int)($mirror['students']??0).' · Vínculos de responsáveis: '.(int)($mirror['guardians']??0).'</p>';
    if (!empty($mirror['error'])) jao_admin_notice('Última tentativa: '.($mirror['last_attempt']??'').' — '.$mirror['error'],true);
    $next=wp_next_scheduled('jao_totvs_sync');
    echo '<p>Próxima sincronização: '.esc_html($next?wp_date('d/m/Y H:i',$next):'Somente manual').'. O agendamento depende da execução do WP-Cron.</p>';
    $last=get_option('jao_totvs_status',[]);
    if ($last) echo '<p>Última consulta: '.esc_html($last['time'].' · '.$last['count'].' registros').'</p><details><summary>Colunas retornadas pela TOTVS</summary><p>'.esc_html(implode(', ',$last['columns'])).'</p></details>';
    echo '<form method="post">'; wp_nonce_field('jao_settings');
    echo '<h2>Acesso dos responsáveis</h2><p><label><input type="checkbox" name="whatsapp_validation" value="1" '.checked(jao_whatsapp_required($s),true,false).'> Exigir validação pelo WhatsApp</label></p><p class="description">Ligado: exige código enviado ao WhatsApp cadastrado. Desligado: permite acessar os alunos apenas com o CPF cadastrado. Ao mudar esta opção, os responsáveis precisam consultar o CPF novamente.</p>';
    $fields=['totvs_url'=>['URL base da API TOTVS (ex.: https://rm.exemplo.com:8051)','text',''],
        'totvs_user'=>['Usuário TOTVS','password',''],'totvs_password'=>['Senha TOTVS','password',''],
        'coligada'=>['Coligada da consulta','text','1'],'sistema'=>['Sistema da consulta (normalmente S — Educacional)','text','S'],
        'parameters'=>['Parâmetros da DADOSREMAT.2 (se houver; ex.: CODCOLIGADA=1;IDPERLET=2026)','text',''],
        'nx_id'=>['ID da API NX Digital (para validação WhatsApp)','password',''],'nx_token'=>['Token da API NX Digital / WhatsApp','password',''],
        'checkout_url'=>['URL base do checkout K8 (confirme a URL para o ambiente escolhido)','url','https://pagamentos.k8bank.com.br/cobrancasui/checkout'],
        'due_days'=>['Vencimento após quantos dias (1 a 30)','number','5'],'dash_password'=>['Nova senha do painel externo (vazio mantém a atual)','password','']];
    echo '<table class="form-table">';
    foreach ($fields as $key=>$f) {
        $value=$f[1]==='password'?'':($s[$key]??$f[2]);
        echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($f[0]).'</label></th><td><input class="regular-text" id="'.esc_attr($key).'" name="'.esc_attr($key).'" type="'.esc_attr($f[1]).'" value="'.esc_attr($value).'" autocomplete="new-password">';
        if ($f[1]==='password') echo '<p class="description">Deixe vazio para preservar o valor salvo.</p>';
        echo '</td></tr>';
    }
    echo '</table><p><label>Frequência da sincronização <select name="sync_interval">';
    foreach (jao_sync_intervals() as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($s['sync_interval']??'hourly',$value,false).'>'.esc_html($label).'</option>';
    echo '</select></label></p>';
    echo '<h2>Mapeamento das colunas da DADOSREMAT.2</h2><p>Use o nome exato da coluna. Alternativas podem ser separadas por |. Responsável 2 é opcional. Nunca use o telefone do aluno no campo do responsável.</p><table class="form-table">';
    foreach (jao_map_defaults() as $key=>$default) echo '<tr><th>'.esc_html($key).'</th><td><input class="large-text" name="map_'.esc_attr($key).'" value="'.esc_attr($s['maps'][$key]??$default).'"></td></tr>';
    echo '</table><button class="button button-primary">Salvar configurações</button></form><form method="post" style="margin-top:20px">';wp_nonce_field('jao_settings');
    echo '<p><label><input type="checkbox" name="confirm_empty" value="1"> Confirmo que, se a TOTVS retornar uma lista vazia, o espelho local deve ficar vazio (não altera pedidos).</label></p><button class="button" name="test_totvs" value="1">Sincronizar agora</button></form><p>A consulta deve retornar todos os alunos do período desejado, com RA, turma e os responsáveis vinculados. O acesso por CPF consulta exclusivamente o espelho no WordPress. Falhas de sincronização preservam a última versão concluída. Alterações feitas no Portal do Aluno passam a valer após a próxima sincronização.</p><p>Configure Cobranças e Pay em <a href="'.esc_url(admin_url('admin.php?page=k8f-settings')).'">K8 Financeiro → Configurações</a>. As mesmas credenciais de Cobranças são usadas nas inscrições.</p><p>Shortcodes: <code>[journey_atividades]</code> e <code>[journey_atividades_dashboard]</code>.</p></div>';
}
function jao_admin_activities() {
    jao_admin_guard(); global $wpdb; $table=$wpdb->prefix.'jao_activities';
    echo '<div class="wrap"><h1>Atividades opcionais</h1>';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_admin_referer('jao_activity');
        try {
            $id=absint(jao_post('id')); $name=jao_post('name'); $price=str_replace(',','.',jao_post('price'));
            if (!$name||strlen($name)>200||!preg_match('/^\d{1,7}(\.\d{1,2})?$/D',$price)||($cents=\K8F\Core::money($price))<1) throw new RuntimeException('Informe nome e valor positivo (ex.: 150,00, sem ponto de milhar).');
            $classes=isset($_POST['classes'])&&is_array($_POST['classes'])?array_values(array_unique(array_map('sanitize_text_field',wp_unslash($_POST['classes'])))):[];
            $known=jao_local_classes();
            if (!$classes||array_diff($classes,$known)) throw new RuntimeException('Selecione turmas da lista TOTVS sincronizada.');
            $r=['name'=>$name,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'price'=>$cents,'classes'=>wp_json_encode($classes),'active'=>isset($_POST['active'])?1:0];
            $ok=$id?$wpdb->update($table,$r,['id'=>$id]):$wpdb->insert($table,$r);
            if ($ok===false) throw new RuntimeException('Erro salvando atividade.');
            jao_admin_notice('Atividade salva. Pedidos existentes mantêm os valores registrados na compra.');
        } catch(Throwable $e) {jao_admin_notice($e->getMessage(),true);}
    }
    $edit=absint($_GET['edit']??0); $a=$edit?$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$edit),ARRAY_A):null;
    $classes=jao_local_classes();
    if (!$classes) echo '<p>Primeiro salve as integrações e clique em “Sincronizar agora”.</p>';
    echo '<form method="post" style="max-width:800px">'; wp_nonce_field('jao_activity');
    echo '<input type="hidden" name="id" value="'.(int)($a['id']??0).'"><p><label>Nome<br><input class="large-text" name="name" required maxlength="200" value="'.esc_attr($a['name']??'').'"></label></p><p><label>Descrição<br><textarea class="large-text" name="description" rows="3">'.esc_textarea($a['description']??'').'</textarea></label></p><p><label>Valor por inscrição (R$)<br><input name="price" inputmode="decimal" required value="'.esc_attr(isset($a['price'])?number_format($a['price']/100,2,',',''):'').'"></label></p><fieldset><legend>Disponível para as turmas:</legend>';
    foreach ($classes as $class) echo '<label style="display:inline-block;margin:8px"><input type="checkbox" name="classes[]" value="'.esc_attr($class).'" '.checked(in_array($class,json_decode($a['classes']??'[]',true)?:[],true),true,false).'> '.esc_html($class).'</label>';
    echo '</fieldset><p><label><input type="checkbox" name="active" '.checked((int)($a['active']??1),1,false).'> Ativa</label></p><button class="button button-primary">Salvar atividade</button> <a class="button" href="'.esc_url(admin_url('admin.php?page=jao')).'">Nova atividade</a></form><h2>Atividades cadastradas</h2><table class="widefat striped"><thead><tr><th>Atividade</th><th>Valor</th><th>Turmas</th><th>Situação</th><th></th></tr></thead><tbody>';
    foreach ($wpdb->get_results("SELECT * FROM $table ORDER BY name",ARRAY_A) as $r) echo '<tr><td>'.esc_html($r['name']).'</td><td>'.esc_html(jao_money($r['price'])).'</td><td>'.esc_html(implode(', ',json_decode($r['classes'],true)?:[])).'</td><td>'.($r['active']?'Ativa':'Inativa').'</td><td><a href="'.esc_url(add_query_arg('edit',$r['id'],admin_url('admin.php?page=jao'))).'">Editar</a></td></tr>';
    echo '</tbody></table></div>';
}
function jao_admin_review() {
    jao_admin_guard(); global $wpdb;
    echo '<div class="wrap"><h1>Conferência de pedidos</h1><p>Pedidos sem confirmação ficam bloqueados para impedir uma segunda cobrança. Confira primeiro no K8. Vincule a cobrança existente ou libere somente se confirmou que nenhuma cobrança foi criada.</p>';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_admin_referer('jao_review'); $lock='';
        try {
            $id=absint(jao_post('id'));
            $o=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jao_orders WHERE id=%d",$id),ARRAY_A);
            if (!$o||$o['state']!=='Conferir'||$o['remote_id']) throw new RuntimeException('Pedido não está aguardando conferência.');
            $lock=jao_lock('student:'.$o['student_key']);
            // Recheck inside the same student lock used by checkout.
            $o=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jao_orders WHERE id=%d",$id),ARRAY_A);
            if ($o['state']!=='Conferir'||$o['remote_id']) throw new RuntimeException('Pedido já conferido.');
            if (jao_post('remote_id')) {
                if (!hash_equals($o['scope'],\K8F\Core::scope())) throw new RuntimeException('Selecione a conta e o ambiente K8 originais.');
                $raw=(new \K8F\Api())->charge(jao_post('remote_id')); if(isset($raw[0]))$raw=$raw[0];
                $r=\K8F\Core::normalize($raw);
                $items=json_decode($o['items'],true)?:[];
                $customer=(new \K8F\Api())->customer($r['customer_id']);
                if (isset($customer[0])) $customer=$customer[0];
                if ($r['remote_id']!==jao_post('remote_id')||$r['amount']!==(int)$o['total']||jao_digits($customer['CPFCNPJ']??$customer['CpfCnpj']??'')!==$o['cpf']||sanitize_text_field(jao_description($o['student'],$items))!==$r['description']) throw new RuntimeException('Valor, responsável ou descrição da cobrança não corresponde ao pedido.');
                $other=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}jao_orders WHERE remote_id=%s AND scope=%s",$r['remote_id'],$o['scope']));
                if ($other) throw new RuntimeException('Cobrança já vinculada a outro pedido.');
                jao_db_update('jao_orders',['remote_id'=>$r['remote_id'],'state'=>'Pendente','error'=>'Vínculo conferido pelo administrador '.get_current_user_id()],['id'=>$id]);
                jao_admin_notice('Cobrança vinculada. O status será consultado automaticamente.');
            } elseif (isset($_POST['confirmed_absent'])) {
                if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('Falha iniciando atualização.');
                try {
                    jao_db_update('jao_orders',['state'=>'Sem cobrança','error'=>'Ausência de cobrança confirmada pelo administrador '.get_current_user_id().' em '.current_time('mysql')],['id'=>$id]);
                    if ($wpdb->delete($wpdb->prefix.'jao_reservations',['order_id'=>$id])===false) throw new RuntimeException('Falha liberando atividades.');
                    if ($wpdb->query('COMMIT')===false) throw new RuntimeException('Falha confirmando alteração.');
                } catch(Throwable $e) {$wpdb->query('ROLLBACK');throw $e;}
                jao_admin_notice('Pedido encerrado sem cobrança. Responsável pode selecionar as atividades novamente.');
            } else throw new RuntimeException('Informe a cobrança existente ou confirme sua ausência no K8.');
        } catch(Throwable $e){jao_admin_notice($e->getMessage(),true);} finally {if($lock)jao_unlock($lock);}
    }
    $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}jao_orders WHERE state='Conferir' ORDER BY id DESC LIMIT 100",ARRAY_A);
    if (!$rows) echo '<p>Nenhum pedido aguardando conferência.</p>';
    foreach ($rows as $r) {
        echo '<div class="card" style="max-width:900px"><h2>#'.(int)$r['id'].' · '.esc_html($r['student']).'</h2><p>'.esc_html(jao_money($r['total']).' · '.$r['guardian'].' · '.$r['created_at']).'</p><p>'.esc_html($r['error']).'</p><form method="post">'; wp_nonce_field('jao_review');
        echo '<input type="hidden" name="id" value="'.(int)$r['id'].'"><p><label>ID da cobrança existente no K8 <input name="remote_id" class="regular-text"></label></p><p><label><input type="checkbox" name="confirmed_absent"> Conferi no K8 e confirmo que nenhuma cobrança foi criada para este pedido.</label></p><button class="button">Concluir conferência</button></form></div>';
    }
    echo '</div>';
}
