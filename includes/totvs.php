<?php
if (!defined('ABSPATH')) exit;
function jao_map_defaults() {
    return ['ra'=>'RA','nome_aluno'=>'NOME_ALUNO|NOMEALUNO|ALUNO|studentName','turma'=>'TURMA|CODTURMA|academicClassName',
    'coligada'=>'CODCOLIGADA','periodo'=>'IDPERLET',
    'cpf_resp1'=>'CPF_RESP1|CPFRESP1|CPFRESPFIN|CPF_RESP_FINANCEIRO|guardianDocument',
    'nome_resp1'=>'NOME_RESP1|NOMERESP1|NOMERESPFIN|guardianName',
    'telefone_resp1'=>'TELEFONE_RESP1|TELEFONERESP1|CELULARRESPFIN|guardianPhone',
    'cpf_resp2'=>'CPF_RESP2|CPFRESP2','nome_resp2'=>'NOME_RESP2|NOMERESP2','telefone_resp2'=>'TELEFONE_RESP2|TELEFONERESP2'];
}
function jao_normalize_students($rows,$maps) {
    $result=[]; $identities=[];
    foreach ($rows as $index=>$row) {
        if (!is_array($row)) throw new RuntimeException('TOTVS: registro inválido.');
        $row=array_change_key_case($row,CASE_UPPER); $s=[];
        foreach ($maps as $field=>$aliases) {
            $s[$field]='';
            foreach (explode('|',$aliases) as $alias) {
                $v=$row[strtoupper(trim($alias))]??'';
                if (is_scalar($v) && trim((string)$v)!=='') { $s[$field]=sanitize_text_field((string)$v); break; }
            }
        }
        if (!$s['ra'] || !$s['nome_aluno'] || !$s['turma']) throw new RuntimeException('Mapeie RA, nome do aluno e turma. Registro '.($index+1).' incompleto; sincronização descartada.');
        if (strlen($s['ra'])>100 || strlen($s['nome_aluno'])>255 || strlen($s['turma'])>150) throw new RuntimeException('TOTVS: campo excede o tamanho permitido.');
        $s['coligada']=$s['coligada']?: (string)(jao_settings()['coligada']??'1');
        $s['key']=hash('sha256',$s['coligada'].'|'.$s['periodo'].'|'.$s['ra']);
        if (isset($identities[$s['key']]) && $identities[$s['key']]!==[$s['nome_aluno'],$s['turma']]) throw new RuntimeException('Aluno com turmas divergentes: filtre o período letivo na DADOSREMAT.2 ou mapeie IDPERLET.');
        $identities[$s['key']]=[$s['nome_aluno'],$s['turma']];
        foreach ([1,2] as $n) {
            $s['cpf_resp'.$n]=jao_digits($s['cpf_resp'.$n]);
            $s['telefone_resp'.$n]=jao_phone($s['telefone_resp'.$n]);
        }
        $result[]=$s;
    }
    if ($result && !array_filter($result,static function($s){return jao_valid_cpf($s['cpf_resp1'])||jao_valid_cpf($s['cpf_resp2']);})) throw new RuntimeException('Nenhum CPF de responsável válido. Confira o mapeamento das colunas.');
    return $result;
}
/** Called only by the protected synchronization, never by a public lookup. */
function jao_totvs_fetch() {
        $s=jao_settings(); $base=rtrim($s['totvs_url']??'','/');
        if (!$base || !jao_secret('totvs_user') || !jao_secret('totvs_password')) throw new RuntimeException('Configure a conexão TOTVS no WordPress.');
        $url=$base.'/api/framework/v1/consultaSQLServer/RealizaConsulta/'.rawurlencode('DADOSREMAT.2').'/'.rawurlencode($s['coligada']??'1').'/'.rawurlencode($s['sistema']??'S');
        if (!empty($s['parameters'])) $url.='?parameters='.rawurlencode($s['parameters']);
        $r=wp_remote_get($url,['timeout'=>40,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>20*1024*1024,'headers'=>['Accept'=>'application/json','Authorization'=>'Basic '.base64_encode(jao_secret('totvs_user').':'.jao_secret('totvs_password'))]]);
        if (is_wp_error($r)) throw new RuntimeException('TOTVS indisponível. Tente novamente em instantes.');
        $code=wp_remote_retrieve_response_code($r);
        if ($code!==200) throw new RuntimeException('Consulta TOTVS falhou (HTTP '.(int)$code.'). Confira conexão, credenciais e permissão para DADOSREMAT.2.');
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if (!is_array($data)) throw new RuntimeException('TOTVS não retornou JSON válido.');
        if (!empty($data['hasNext']) || !empty($data['HasNext']) || !empty($data['hasMore']) || !empty($data['nextPage'])) throw new RuntimeException('TOTVS retornou dados paginados. Configure DADOSREMAT.2 para retornar a lista completa.');
        $rows=$data['items']??$data['Items']??$data;
        if (!is_array($rows) || ($rows && array_keys($rows)!==range(0,count($rows)-1))) throw new RuntimeException('Formato TOTVS não reconhecido. Esperada uma lista JSON.');
        update_option('jao_totvs_status',['time'=>current_time('mysql'),'count'=>count($rows),'columns'=>$rows?array_keys($rows[0]):[],'validated'=>false],false);
        $maps=array_merge(jao_map_defaults(),$s['maps']??[]);
        $students=jao_normalize_students($rows,$maps);
        return $students;
}
/** Compile a complete validated snapshot before touching the live mirror. */
function jao_mirror_compile($rows) {
    $students=[]; $groups=[];
    foreach ($rows as $s) {
        if (strlen($s['coligada'])>50 || strlen($s['periodo'])>100) throw new RuntimeException('Coligada ou período excede o tamanho permitido.');
        $students[$s['key']]=['student_key'=>$s['key'],'ra'=>$s['ra'],'nome_aluno'=>$s['nome_aluno'],'turma'=>$s['turma'],'coligada'=>$s['coligada'],'periodo'=>$s['periodo']];
        foreach ([1,2] as $n) {
            $cpf=$s['cpf_resp'.$n]; if (!jao_valid_cpf($cpf)) continue;
            $name=$s['nome_resp'.$n]; if (strlen($name)>255) throw new RuntimeException('Nome do responsável excede o tamanho permitido.');
            $key=hash('sha256',$s['key'].'|'.$cpf);
            if (!isset($groups[$key])) $groups[$key]=['student_key'=>$s['key'],'cpf'=>$cpf,'nome'=>$name,'phones'=>[]];
            if (!$groups[$key]['nome'] && $name) $groups[$key]['nome']=$name;
            if ($s['telefone_resp'.$n]) $groups[$key]['phones'][$s['telefone_resp'.$n]]=true;
        }
    }
    $links=[];
    foreach ($groups as $key=>$g) {
        $count=count($g['phones']);
        $links[]=['link_hash'=>$key,'student_key'=>$g['student_key'],'cpf'=>$g['cpf'],'nome'=>$g['nome'],
        'telefone'=>$count===1?(string)array_key_first($g['phones']):'', 'telefone_status'=>$count>1?'conflict':($count===1?'valid':'missing')];
    }
    return ['students'=>array_values($students),'guardians'=>$links];
}
function jao_mirror_publish($snapshot) {
    global $wpdb;
    if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('Não foi possível iniciar a atualização local.');
    try {
        // InnoDB: one transaction replaces the full mirror. Readers see old OR new data.
        // Do not TRUNCATE: it would commit implicitly and expose a partial mirror.
        foreach (['jao_guardians','jao_students'] as $table) if ($wpdb->query("DELETE FROM {$wpdb->prefix}$table")===false) throw new RuntimeException('Falha preparando o espelho local.');
        foreach (['students'=>'jao_students','guardians'=>'jao_guardians'] as $key=>$table) foreach ($snapshot[$key] as $row) {
            if ($wpdb->insert($wpdb->prefix.$table,$row)===false) throw new RuntimeException('Falha gravando o espelho local. A versão anterior foi preservada.');
        }
        if ($wpdb->query('COMMIT')===false) throw new RuntimeException('Não foi possível confirmar a atualização local.');
    } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
}
function jao_sync_mirror($allow_empty=false) {
    $lock=jao_lock('totvs');
    $status=(array)get_option('jao_mirror_status',[]);
    $status['last_attempt']=current_time('mysql');
    try {
        $rows=jao_totvs_fetch();
        if (!$rows && !$allow_empty) throw new RuntimeException('DADOSREMAT.2 retornou vazia. O espelho anterior foi mantido. Se a lista vazia estiver correta, confirme isso na sincronização manual.');
        $snapshot=jao_mirror_compile($rows);
        jao_mirror_publish($snapshot);
        $status=['last_attempt'=>$status['last_attempt'],'last_success'=>current_time('mysql'),'students'=>count($snapshot['students']),'guardians'=>count($snapshot['guardians']),'error'=>''];
        update_option('jao_mirror_status',$status,false);
        delete_transient('jao_totvs_rows');
        return $status;
    } catch (Throwable $e) {
        $status['error']=$e->getMessage(); update_option('jao_mirror_status',$status,false); throw $e;
    } finally { jao_unlock($lock); }
}
/** All interactive student authorization reads only WordPress tables. */
function jao_cpf_students($cpf) {
    global $wpdb;
    if (!jao_valid_cpf($cpf)) return [];
    $rows=$wpdb->get_results($wpdb->prepare("SELECT s.student_key AS `key`,s.ra,s.nome_aluno,s.turma,s.coligada,s.periodo,
        g.cpf AS cpf_resp1,g.nome AS nome_resp1,g.telefone AS telefone_resp1,g.telefone_status,
        '' AS cpf_resp2,'' AS nome_resp2,'' AS telefone_resp2
        FROM {$wpdb->prefix}jao_students s INNER JOIN {$wpdb->prefix}jao_guardians g ON g.student_key=s.student_key
        WHERE g.cpf=%s ORDER BY s.nome_aluno,s.student_key",$cpf));
    if ($wpdb->last_error) throw new RuntimeException('Não foi possível consultar o cadastro local. Procure a secretaria.');
    return $rows?:[];
}
function jao_local_classes() {
    global $wpdb;
    $rows=$wpdb->get_col("SELECT DISTINCT turma FROM {$wpdb->prefix}jao_students ORDER BY turma");
    if ($wpdb->last_error) throw new RuntimeException('Falha consultando turmas locais.');
    return $rows?:[];
}
function jao_sync_intervals() { return ['jao_15min'=>'A cada 15 minutos','hourly'=>'A cada hora','jao_6hours'=>'A cada 6 horas','daily'=>'Uma vez por dia','manual'=>'Somente manual']; }
add_filter('cron_schedules',function($s){
    $s['jao_15min']=['interval'=>900,'display'=>'Espelho TOTVS: 15 minutos'];
    $s['jao_6hours']=['interval'=>21600,'display'=>'Espelho TOTVS: 6 horas']; return $s;
});
function jao_schedule_mirror() {
    $interval=jao_settings()['sync_interval']??'hourly';
    if (!isset(jao_sync_intervals()[$interval])) $interval='hourly';
    $current=wp_get_schedule('jao_totvs_sync');
    if ($current && $current!==$interval) wp_clear_scheduled_hook('jao_totvs_sync');
    if ($interval!=='manual' && !wp_next_scheduled('jao_totvs_sync')) wp_schedule_event(time()+300,$interval,'jao_totvs_sync');
}
add_action('jao_totvs_sync',function(){
    try { if (!empty(jao_settings()['totvs_url'])) jao_sync_mirror(); }
    catch (Throwable $e) { /* Error already recorded; previous mirror remains available. */ }
});
