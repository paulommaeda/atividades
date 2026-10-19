<?php
if (!defined('ABSPATH')) exit;
function jao_activity_rows($class) {
    global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}jao_activities WHERE active=1 ORDER BY name",ARRAY_A);
    return array_values(array_filter($rows,static function($r)use($class){return in_array($class,json_decode($r['classes'],true)?:[],true);}));
}
function jao_catalog() {
    global $wpdb; [$session,$s]=jao_selected();
    $reserved=$wpdb->get_results($wpdb->prepare("SELECT r.activity_id,o.id,o.state,o.remote_id,o.cpf,o.scope FROM {$wpdb->prefix}jao_reservations r INNER JOIN {$wpdb->prefix}jao_orders o ON o.id=r.order_id WHERE r.student_key=%s",$s->key),ARRAY_A);
    $by=[]; $orders=[];
    foreach ($reserved as $r) {
        $by[$r['activity_id']]=$r;
        if ($r['cpf']===$session->cpf) $orders[$r['id']]=['id'=>(int)$r['id'],'state'=>$r['state'],'url'=>$r['remote_id']&&$r['state']!=='Paga'&&$r['scope']===\K8F\Core::scope()?jao_checkout_url($r['remote_id']):''];
    }
    $items=[];
    foreach (jao_activity_rows($s->turma) as $r) $items[]=['id'=>(int)$r['id'],'name'=>$r['name'],'description'=>$r['description'],'price'=>(int)$r['price'],'reserved'=>isset($by[$r['id']]),'state'=>$by[$r['id']]['state']??''];
    return ['items'=>$items,'orders'=>array_values($orders)];
}
function jao_checkout_url($id) {
    $s=jao_settings(); $base=$s['checkout_url']??'https://pagamentos.k8bank.com.br/cobrancasui/checkout';
    return add_query_arg('ID',$id,$base);
}
function jao_description($name,$items) { return 'Nome do Aluno: '.$name."\nAtividades escolhidas: ".implode(', ',array_column($items,'name')); }
function jao_checkout() {
    global $wpdb; [$session,$s]=jao_selected();
    $ids=json_decode(jao_post('ids'),true);
    if (!is_array($ids)||!$ids||count($ids)>100||count(array_filter($ids,'is_numeric'))!==count($ids)) throw new RuntimeException('Selecione as atividades.');
    $ids=array_values(array_unique(array_map('absint',$ids))); sort($ids);
    $method=jao_post('method'); if (!in_array($method,['Pix','Boleto','Cartao'],true)) throw new RuntimeException('Forma de pagamento inválida.');
    $lock=jao_lock('student:'.$s->key); $id=0; $committed=false;
    try {
        $items=[]; $total=0;
        foreach (jao_activity_rows($s->turma) as $r) if (in_array((int)$r['id'],$ids,true)) { $items[]=['id'=>(int)$r['id'],'name'=>$r['name'],'price'=>(int)$r['price']]; $total+=(int)$r['price']; }
        if (count($items)!==count($ids)||$total<1) throw new RuntimeException('Uma atividade foi alterada ou não está disponível. Recarregue a lista.');
        if (jao_post('expected_total')!==(string)$total) throw new RuntimeException('Os valores foram atualizados. Recarregue a lista e confira o novo total.');
        $existing=$wpdb->get_results($wpdb->prepare("SELECT r.activity_id,o.* FROM {$wpdb->prefix}jao_reservations r INNER JOIN {$wpdb->prefix}jao_orders o ON o.id=r.order_id WHERE r.student_key=%s AND r.activity_id IN (".implode(',',$ids).')',$s->key),ARRAY_A);
        if ($existing) {
            $old=$existing[0]; $oldids=array_map('intval',array_column(json_decode($old['items'],true)?:[],'id')); sort($oldids);
            if ($oldids===$ids&&$old['cpf']===$session->cpf&&$old['remote_id']&&$old['state']!=='Paga'&&$old['scope']===\K8F\Core::scope()) return ['url'=>jao_checkout_url($old['remote_id']),'order'=>(int)$old['id']];
            throw new RuntimeException('Há atividades já inscritas ou com cobrança em andamento. Confira os pedidos existentes ou procure a secretaria.');
        }
        $scope=\K8F\Core::scope(); // Validate K8 config before reserving anything.
        if (!trim($s->nome_resp1)) throw new RuntimeException('Nome do responsável ausente no cadastro TOTVS. Procure a secretaria.');
        if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('Não foi possível iniciar a inscrição.');
        $ok=$wpdb->insert($wpdb->prefix.'jao_orders',['student_key'=>$s->key,'ra'=>$s->ra,'student'=>$s->nome_aluno,'class'=>$s->turma,'cpf'=>$session->cpf,'guardian'=>$s->nome_resp1,'items'=>wp_json_encode($items),'total'=>$total,'method'=>$method,'state'=>'Conferir','scope'=>$scope,'created_at'=>current_time('mysql'),'error'=>'Operação iniciada; aguardando confirmação K8.']);
        if (!$ok) throw new RuntimeException('Falha ao registrar pedido.');
        $id=(int)$wpdb->insert_id;
        foreach ($items as $item) if (!$wpdb->insert($wpdb->prefix.'jao_reservations',['student_key'=>$s->key,'activity_id'=>$item['id'],'order_id'=>$id])) throw new RuntimeException('Uma atividade já possui inscrição em andamento.');
        if ($wpdb->query('COMMIT')===false) throw new RuntimeException('Falha ao confirmar registro local.');
        $committed=true;
        $api=new \K8F\Api();
        $client=$api->get('/api/Cliente?Cpfcnpj='.rawurlencode($session->cpf));
        if (isset($client[0])) $client=$client[0];
        if (!$client) $client=$api->create('Cliente',['Nome'=>$s->nome_resp1,'CPFCNPJ'=>$session->cpf]);
        if (empty($client['Id'])) throw new RuntimeException('K8 não retornou o identificador do responsável.');
        $due=(new DateTimeImmutable('now',wp_timezone()))->modify('+'.max(1,min(30,(int)(jao_settings()['due_days']??5))).' days')->format('c');
        $data=$api->create('Cobranca',['ClienteId'=>$client['Id'],'ClienteNome'=>$s->nome_resp1,'Descricao'=>jao_description($s->nome_aluno,$items),'Valor'=>$total/100,'Vencimento'=>$due,'Tipo'=>'Avulsa','FormasPagamento'=>$method,'NumeroOperacao'=>'JAO-'.$id]);
        if (empty($data['Id'])||!is_scalar($data['Id'])) throw new RuntimeException('K8 não retornou o identificador da cobrança.');
        jao_db_update('jao_orders',['remote_id'=>(string)$data['Id'],'state'=>'Pendente','error'=>''],['id'=>$id]);
        return ['url'=>jao_checkout_url($data['Id']),'order'=>$id];
    } catch (Throwable $e) {
        if (!$committed) $wpdb->query('ROLLBACK');
        elseif ($id) { $wpdb->update($wpdb->prefix.'jao_orders',['error'=>$e->getMessage()],['id'=>$id]); throw new RuntimeException('Pedido #'.$id.' registrado, mas o pagamento precisa de conferência pela secretaria. Não foi criada uma nova tentativa automática.'); }
        throw $e;
    } finally { jao_unlock($lock); }
}
function jao_refresh_one($id) {
    global $wpdb;
    $o=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jao_orders WHERE id=%d",$id),ARRAY_A);
    if (!$o||!$o['remote_id']) throw new RuntimeException('Pedido sem cobrança vinculada. Confira pelo administrador.');
    if (!hash_equals($o['scope'],\K8F\Core::scope())) throw new RuntimeException('Pedido pertence a outra conta ou ambiente K8.');
    $lock=jao_lock('student:'.$o['student_key']);
    try {
        $raw=(new \K8F\Api())->charge($o['remote_id']);
        if (isset($raw[0])) $raw=$raw[0];
        $row=\K8F\Core::normalize($raw);
        if ($row['remote_id']!==$o['remote_id']||$row['amount']!==(int)$o['total']) throw new RuntimeException('Cobrança retornada não confere com o pedido.');
        \K8F\Store::save($row,$o['scope']);
        if (!in_array($row['state'],['Pendente','Paga','Inadimplente','Cancelada'],true)) throw new RuntimeException('Estado K8 não reconhecido. Confira no financeiro.');
        jao_db_update('jao_orders',['state'=>$row['state'],'synced_at'=>current_time('mysql'),'error'=>''],['id'=>$id]);
        if ($row['state']==='Cancelada') $wpdb->delete($wpdb->prefix.'jao_reservations',['order_id'=>$id]);
        return $row['state'];
    } finally { jao_unlock($lock); }
}
function jao_refresh_orders() {
    global $wpdb;
    try {
        $scope=\K8F\Core::scope();
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}jao_orders WHERE scope=%s AND remote_id<>'' AND state IN ('Pendente','Inadimplente','Paga') ORDER BY COALESCE(synced_at,'1970-01-01') ASC LIMIT 10",$scope));
        foreach ($ids as $id) {
            try { jao_refresh_one($id); }
            catch (Throwable $e) { $wpdb->update($wpdb->prefix.'jao_orders',['error'=>$e->getMessage(),'synced_at'=>current_time('mysql')],['id'=>$id]); }
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}jao_bird_sessions WHERE expires<%d",time()));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}jao_bird_limits WHERE expires<%d",time()));
    } catch (Throwable $e) { update_option('jao_cron_error',$e->getMessage(),false); }
}
