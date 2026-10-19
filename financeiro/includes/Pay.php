<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class Pay {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'k8f_pay'; }
    public static function credentials(): array {
        $s=Core::settings();
        if (empty($s['pay_credentials'])) { throw new \RuntimeException('Configure as credenciais do K8 Pay nas configurações.'); }
        $c=json_decode(Core::unseal($s['pay_credentials']),true);
        if (!is_array($c)||empty($c['id'])||empty($c['secret'])||empty($c['aes'])) { throw new \RuntimeException('Credenciais Pay incompletas.'); }
        return $c;
    }
    public static function scope(): string {
        $c=self::credentials();
        return hash('sha256',Core::scope().':pay:'.$c['id']);
    }
    public static function install(): void {
        global $wpdb; $t=self::table(); $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(64) NOT NULL,
            remote_id varchar(100) NOT NULL,
            charge_id bigint unsigned DEFAULT NULL,
            link_source varchar(20) NOT NULL DEFAULT '',
            linked_at datetime DEFAULT NULL,
            linked_by bigint unsigned DEFAULT NULL,
            kind varchar(60) NOT NULL DEFAULT '',
            state varchar(60) NOT NULL DEFAULT '',
            customer varchar(255) NOT NULL DEFAULT '',
            document varchar(20) NOT NULL DEFAULT '',
            order_number varchar(255) NOT NULL DEFAULT '',
            amount bigint DEFAULT NULL,
            paid bigint DEFAULT NULL,
            fees bigint DEFAULT NULL,
            paid_at datetime DEFAULT NULL,
            settled_at datetime DEFAULT NULL,
            available_at datetime DEFAULT NULL,
            settled tinyint DEFAULT NULL,
            installments longtext DEFAULT NULL,
            installment_count int DEFAULT NULL,
            installment_sum bigint DEFAULT NULL,
            installments_complete tinyint NOT NULL DEFAULT 0,
            synced_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY remote_scope (scope,remote_id),
            KEY scope_charge (scope,charge_id),
            KEY scope_state (scope,state),
            KEY scope_paid (scope,paid_at)
        ) $collate;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))!==$t) { wp_die('Falha criando a tabela K8 Pay.'); }
    }
    public static function boolean($v): ?int {
        if (in_array($v,[true,1,'1','true'],true)) { return 1; }
        if (in_array($v,[false,0,'0','false'],true)) { return 0; }
        return null;
    }
    public static function normalize(array $r): array {
        $id=Core::text($r['Identificador']??'');
        if ($id===''||strlen($id)>100||empty($r['Tipo'])||empty($r['Estado'])) { throw new \RuntimeException('Transação Pay sem identificador, tipo ou estado.'); }
        if (isset($r['Retorno'])&&strtoupper((string)$r['Retorno'])!=='OK') { throw new \RuntimeException('Uma transação Pay retornou erro.'); }
        $items=null; $sum=null; $complete=0;
        if (array_key_exists('Parcelas',$r)&&$r['Parcelas']!==null) {
            if (!is_array($r['Parcelas'])||($r['Parcelas']!==[]&&array_keys($r['Parcelas'])!==range(0,count($r['Parcelas'])-1))) { throw new \RuntimeException('Formato de parcelas Pay não reconhecido.'); }
            $items=[]; $sum=0; $complete=count($r['Parcelas'])>0?1:0;
            foreach ($r['Parcelas'] as $p) {
                if (!is_array($p)) { throw new \RuntimeException('Parcela Pay inválida.'); }
                $value=Core::money($p['Valor']??null);
                if ($value===null) { $complete=0; } else { $sum+=$value; }
                $items[]=['number'=>Core::text($p['Numero']??''),'state'=>Core::text($p['Estado']??''),
                    'amount'=>$value,'paid_at'=>Core::date($p['DataPagamento']??null),
                    'reason'=>Core::text($p['MotivoCancelamento']??'')];
            }
            if (!$complete) { $sum=null; }
        }
        return ['remote_id'=>$id,'kind'=>Core::text($r['Tipo']),'state'=>Core::text($r['Estado']),
            'customer'=>Core::text($r['ClienteNome']??''),'document'=>preg_replace('/\D/','',Core::text($r['ClienteCPFCNPJ']??'')),
            'order_number'=>Core::text($r['NumeroPedido']??''),'amount'=>Core::money($r['Valor']??null),
            'paid'=>Core::money($r['ValorPago']??null),'fees'=>Core::money($r['ValorTaxas']??null),
            'paid_at'=>Core::date($r['DataPagamento']??null),'settled_at'=>Core::date($r['DataLiquidacao']??null),
            'available_at'=>Core::date($r['DataDisponibilizacao']??null),'settled'=>self::boolean($r['Liquidado']??null),
            'installments'=>$items===null?null:wp_json_encode($items),'installment_count'=>$items===null?null:count($items),
            'installment_sum'=>$sum,'installments_complete'=>$complete,'synced_at'=>current_time('mysql')];
    }
    public static function save(array $row): void {
        global $wpdb; $t=self::table(); $scope=self::scope();
        $row['scope']=$scope;
        $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE scope=%s AND remote_id=%s",$scope,$row['remote_id']));
        $ok=$id?$wpdb->update($t,$row,['id'=>$id]):$wpdb->insert($t,$row);
        if ($ok===false) { throw new \RuntimeException('Falha salvando transação Pay.'); }
        // Existing manual links are preserved. Automatic links require an exact ID and exactly one charge.
        self::autoLink($row['remote_id']);
    }
    public static function autoLink(string $remote): void {
        global $wpdb; $t=self::table(); $c=Store::table(); $scope=self::scope();
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE scope=%s AND remote_id=%s",$scope,$remote),ARRAY_A);
        if (!$r||in_array($r['link_source'],['manual','blocked'],true)) { return; }
        $matches=$wpdb->get_col($wpdb->prepare("SELECT id FROM $c WHERE scope=%s AND pay_ref=%s",Core::scope(),$remote));
        $charge=count($matches)===1?(int)$matches[0]:null;
        if ($wpdb->update($t,['charge_id'=>$charge,'link_source'=>$charge?'id':'','linked_at'=>$charge?current_time('mysql'):null,'linked_by'=>null],['id'=>$r['id']])===false) { throw new \RuntimeException('Falha vinculando transação Pay.'); }
    }
    public static function connect(int $chargeId,string $remote,bool $unlink=false): array {
        global $wpdb; $lock=Sync::lock();
        try {
            $t=self::table();$c=Store::table();
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $c WHERE scope=%s AND id=%d",Core::scope(),$chargeId))) { throw new \RuntimeException('Cobrança não encontrada na conta atual.'); }
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE scope=%s AND remote_id=%s",self::scope(),$remote),ARRAY_A);
            if (!$r) { throw new \RuntimeException('Importe primeiro esse ID Pay na conta atual.'); }
            if ($r['charge_id']&&((int)$r['charge_id']!==$chargeId)) { throw new \RuntimeException('Transação já vinculada a outra cobrança. Remova o vínculo anterior antes de continuar.'); }
            $data=['charge_id'=>$unlink?null:$chargeId,'link_source'=>$unlink?'blocked':'manual','linked_at'=>current_time('mysql'),'linked_by'=>get_current_user_id()];
            if ($wpdb->update($t,$data,['id'=>$r['id']])===false) { throw new \RuntimeException('Falha salvando vínculo.'); }
            return self::forCharge($chargeId);
        } finally { Sync::unlock($lock); }
    }
    public static function forCharge(int $id): array {
        global $wpdb; $c=Store::table();
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $c WHERE scope=%s AND id=%d",Core::scope(),$id))) { throw new \RuntimeException('Cobrança não encontrada.'); }
        $t=self::table();$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE scope=%s AND charge_id=%d ORDER BY paid_at DESC,id DESC",self::scope(),$id),ARRAY_A);
        return array_map([self::class,'present'],$rows);
    }
    public static function present(array $r): array {
        $r['installments']=$r['installments']===null?null:json_decode($r['installments'],true);
        $r['net_estimate']=$r['paid']!==null&&$r['fees']!==null?((int)$r['paid']-(int)$r['fees']):null;
        return $r;
    }
    public static function one(string $id): array {
        if (!preg_match('/^[A-Za-z0-9-]{1,100}$/D',$id)) { throw new \RuntimeException('Identificador Pay inválido.'); }
        $r=(new Api('pay'))->consultPay('ConsultaTransacao',['Identificador'=>$id]);
        if (($r['Identificador']??'')!==$id) { throw new \RuntimeException('K8 Pay retornou outro identificador.'); }
        $row=self::normalize($r);self::save($row);return self::present($row);
    }
    public static function key(): string {return 'k8f_pay_sync_'.self::scope();}
    public static function start(string $from,string $to): array {
        foreach ([$from,$to] as $d) {if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)||Core::date($d)===null)throw new \RuntimeException('Informe o período de importação Pay.');}
        if($from>$to||$to>current_time('Y-m-d'))throw new \RuntimeException('Período Pay inválido ou futuro.');
        $s=['from'=>$from,'to'=>$to,'day'=>$from,'kind_index'=>0,'processed'=>0,'done'=>false,'error'=>''];
        update_option(self::key(),$s,false);return $s;
    }
    public static function step(?string $from=null,?string $to=null): array {
        $lock=Sync::lock();
        try {
            $s=$from!==null?self::start($from,(string)$to):(array)get_option(self::key(),[]);
            if(!$s)throw new \RuntimeException('Escolha o período e inicie a importação Pay.');
            if(!empty($s['done']))return $s;
            if(($s['retry_at']??0)>time())throw new \RuntimeException('Aguarde a próxima tentativa Pay após a falha.');
            $day=new \DateTimeImmutable($s['day'].' 00:00:00',wp_timezone()); $end=$day->modify('+1 day');
            $types=['Cartao','Pix','Boleto','BoletoHibrido'];$type=$types[(int)$s['kind_index']];
            $result=(new Api('pay'))->consultPay('ConsultaTransacoes',[
                'DataHoraInicial'=>$day->format('c'),'DataHoraFinal'=>$end->format('c'),'Tipo'=>$type,'TipoFiltro'=>'dataemissao',
            ]);
            if(!isset($result['Transacoes'])||!is_array($result['Transacoes'])||($result['Transacoes']!==[]&&array_keys($result['Transacoes'])!==range(0,count($result['Transacoes'])-1)))throw new \RuntimeException('Lista Transacoes ausente ou em formato inesperado.');
            $rows=array_map([self::class,'normalize'],$result['Transacoes']);
            foreach($rows as $r)self::save($r);
            $s['processed']+=count($rows);$s['kind_index']++;
            if($s['kind_index']>=count($types)){$s['kind_index']=0;$s['day']=$end->format('Y-m-d');}
            $s['done']=$s['day']>$s['to'];$s['last_step']=current_time('mysql');$s['error']='';unset($s['retry_at']);
            if($s['done']){$s['completed_at']=current_time('mysql');}
            update_option(self::key(),$s,false);return $s;
        } catch(\Throwable $e) {
            if(isset($s)&&is_array($s)&&$s){$s['error']=$e->getMessage();$s['retry_at']=time()+120;update_option(self::key(),$s,false);}throw $e;
        } finally {Sync::unlock($lock);}
    }
    public static function query(array $f): array {
        global $wpdb;$t=self::table();$c=Store::table();$w=['p.scope=%s'];$args=[self::scope()];
        foreach(['kind','state']as $field){if(!empty($f[$field])){$w[]="p.$field=%s";$args[]=Core::text($f[$field]);}}
        if(isset($f['settled'])&&$f['settled']!==''){$w[]='p.settled=%d';$args[]=absint($f['settled'])===1?1:0;}
        if(!empty($f['q'])){$q='%'.$wpdb->esc_like(Core::text($f['q'])).'%';$w[]='(p.customer LIKE %s OR p.document LIKE %s OR p.order_number LIKE %s OR p.remote_id LIKE %s)';array_push($args,$q,$q,$q,$q);}
        foreach(['min_installments'=>'>=','max_installments'=>'<=']as $key=>$op){if(isset($f[$key])&&$f[$key]!==''){$w[]="p.installment_count $op %d";$args[]=absint($f[$key]);}}
        if(($f['linked']??'')==='yes')$w[]='p.charge_id IS NOT NULL';
        if(($f['linked']??'')==='no')$w[]='p.charge_id IS NULL';
        $date=in_array($f['date_field']??'', ['paid_at','settled_at','available_at'],true)?$f['date_field']:'paid_at';
        foreach(['from'=>'>=','to'=>'<=']as $key=>$op){if(!empty($f[$key])){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$f[$key])||Core::date($f[$key])===null)throw new \RuntimeException('Data do filtro Pay inválida.');$w[]="p.$date $op %s";$args[]=$f[$key].($key==='from'?' 00:00:00':' 23:59:59');}}
        if(!empty($f['from'])&&!empty($f['to'])&&$f['from']>$f['to'])throw new \RuntimeException('Período do filtro Pay inválido.');
        if(!empty($f['before_id'])){$w[]='p.id<%d';$args[]=absint($f['before_id']);}
        $where=implode(' AND ',$w);$base="FROM $t p LEFT JOIN $c c ON c.id=p.charge_id WHERE $where";
        $page=max(1,absint($f['page']??1));$size=50;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT p.*,c.remote_id charge_remote_id,c.customer charge_customer,c.state charge_state $base ORDER BY p.id DESC LIMIT %d OFFSET %d",array_merge($args,[$size,($page-1)*$size])),ARRAY_A);
        if($wpdb->last_error)throw new \RuntimeException('Falha consultando dados Pay.');
        $m=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) count,SUM(p.amount) amount,COUNT(p.amount) amount_known,SUM(p.paid) paid,SUM(p.fees) fees,COUNT(p.paid) paid_known,COUNT(p.fees) fees_known,SUM(CASE WHEN p.settled=1 THEN 1 ELSE 0 END) settled_count,SUM(CASE WHEN p.charge_id IS NOT NULL THEN 1 ELSE 0 END) linked_count $base",$args),ARRAY_A);
        return ['rows'=>array_map([self::class,'present'],$rows),'metrics'=>$m,'total'=>(int)$m['count'],'pages'=>(int)ceil((int)$m['count']/$size),'page'=>$page,'sync'=>array_merge((array)get_option(self::key(),[]),['cron_error'=>(string)get_option(self::key().'_cron_error','')])];
    }
}
