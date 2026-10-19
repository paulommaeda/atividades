<?php
if (!defined('ABSPATH')) exit;
function jao_dash_login() {
    if (!jao_limit('dash-login',$_SERVER['REMOTE_ADDR']??'',5,900)) throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos.');
    $pass=isset($_POST['password'])&&is_string($_POST['password'])?wp_unslash($_POST['password']):'';
    if (!wp_check_password($pass,get_option('jao_dashboard_hash',''))) throw new RuntimeException('Senha incorreta.');
    $token=bin2hex(random_bytes(32)); $csrf=bin2hex(random_bytes(32));
    set_transient('jao_dash_'.jao_hash($token),['csrf'=>$csrf,'version'=>hash('sha256',get_option('jao_dashboard_hash',''))],8*HOUR_IN_SECONDS);
    setcookie('jao_dash',$token,['expires'=>time()+8*HOUR_IN_SECONDS,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    return ['csrf'=>$csrf];
}
function jao_dash_guard($check_csrf=true) {
    $token=isset($_COOKIE['jao_dash'])&&is_string($_COOKIE['jao_dash'])?$_COOKIE['jao_dash']:'';
    $data=preg_match('/^[a-f0-9]{64}$/D',$token)?get_transient('jao_dash_'.jao_hash($token)):false;
    if (!$data||!hash_equals($data['version'],hash('sha256',get_option('jao_dashboard_hash','')))) throw new RuntimeException('Acesso expirado. Entre com a senha do painel.');
    if ($check_csrf&&!hash_equals($data['csrf'],jao_post('csrf'))) throw new RuntimeException('Sessão inválida. Entre novamente.');
    return $data;
}
function jao_dash_session() { $d=jao_dash_guard(false); return ['csrf'=>$d['csrf']]; }
function jao_dash_logout() {
    jao_dash_guard(); delete_transient('jao_dash_'.jao_hash($_COOKIE['jao_dash']));
    setcookie('jao_dash','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    return ['ok'=>true];
}
function jao_dash_where() {
    global $wpdb; $w=['1=1']; $p=[];
    foreach (['class','state'] as $field) if (jao_post($field)!=='') { $w[]="o.`$field`=%s"; $p[]=jao_post($field); }
    if (jao_post('search')!=='') { $w[]='(o.student LIKE %s OR o.ra LIKE %s OR o.guardian LIKE %s)'; $v='%'.$wpdb->esc_like(jao_post('search')).'%'; array_push($p,$v,$v,$v); }
    if ($a=absint(jao_post('activity'))) { $w[]='JSON_CONTAINS(o.items,%s)'; $p[]=wp_json_encode(['id'=>$a]); }
    foreach (['from'=>'>=','to'=>'<='] as $field=>$op) if ($date=jao_post($field)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) throw new RuntimeException('Data inválida.');
        $w[]="o.created_at $op %s"; $p[]=$date.($field==='from'?' 00:00:00':' 23:59:59');
    }
    $sql=implode(' AND ',$w); return $p?$wpdb->prepare($sql,$p):$sql;
}
function jao_dash_query() {
    jao_dash_guard(); global $wpdb; $where=jao_dash_where(); $page=max(1,absint(jao_post('page'))); $offset=($page-1)*50;
    $summary=$wpdb->get_row("SELECT COUNT(*) orders,COALESCE(SUM(total),0) total,COALESCE(SUM(CASE WHEN state='Paga' THEN total ELSE 0 END),0) paid,COALESCE(SUM(CASE WHEN state IN ('Pendente','Inadimplente') THEN total ELSE 0 END),0) pending, SUM(state='Conferir') review FROM {$wpdb->prefix}jao_orders o WHERE $where",ARRAY_A);
    $rows=$wpdb->get_results($wpdb->prepare("SELECT o.* FROM {$wpdb->prefix}jao_orders o WHERE $where ORDER BY id DESC LIMIT 50 OFFSET %d",$offset),ARRAY_A);
    if ($wpdb->last_error) throw new RuntimeException('Falha consultando inscrições.');
    foreach ($rows as &$r) { $r['items']=json_decode($r['items'],true)?:[]; unset($r['cpf'],$r['scope'],$r['student_key']); } unset($r);
    return ['rows'=>$rows,'summary'=>$summary,'page'=>$page,'pages'=>max(1,(int)ceil(($summary['orders']??0)/50)),
        'classes'=>$wpdb->get_col("SELECT DISTINCT class FROM {$wpdb->prefix}jao_orders ORDER BY class"),
        'activities'=>$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}jao_activities ORDER BY name",ARRAY_A)];
}
function jao_dash_refresh() {
    jao_dash_guard();
    if (!jao_limit('refresh',$_SERVER['REMOTE_ADDR']??'',30,300)) throw new RuntimeException('Aguarde antes de atualizar mais cobranças.');
    return ['state'=>jao_refresh_one(absint(jao_post('id')))];
}
function jao_dash_export() {
    jao_dash_guard(); global $wpdb; $where=jao_dash_where();
    $max=(int)$wpdb->get_var("SELECT MAX(o.id) FROM {$wpdb->prefix}jao_orders o WHERE $where");
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="inscricoes-atividades.csv"'); header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Pedido','Aluno','RA','Turma','Responsável','Atividades','Total R$','Situação','Método','ID K8','Criado em','Consultado em'], ';','"','');
    $last=0;
    do {
        $rows=$wpdb->get_results($wpdb->prepare("SELECT o.* FROM {$wpdb->prefix}jao_orders o WHERE $where AND o.id>%d AND o.id<=%d ORDER BY o.id LIMIT 500",$last,$max),ARRAY_A);
        foreach ($rows as $r) {
            $line=[$r['id'],$r['student'],$r['ra'],$r['class'],$r['guardian'],implode(' | ',array_column(json_decode($r['items'],true)?:[],'name')),number_format($r['total']/100,2,',',''),$r['state'],$r['method'],$r['remote_id'],$r['created_at'],$r['synced_at']];
            fputcsv($out,array_map(['K8F\\Core','csv'],$line),';','"',''); $last=(int)$r['id'];
        }
    } while(count($rows)===500);
    fclose($out); exit;
}
