<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class Store {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'k8f_charges'; }
    public static function customers(): string { global $wpdb; return $wpdb->prefix . 'k8f_customers'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table(); $c = self::customers(); $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(64) NOT NULL,
            remote_id varchar(100) NOT NULL,
            pay_ref varchar(100) NOT NULL DEFAULT '',
            seq varchar(100) NOT NULL DEFAULT '',
            customer_id varchar(100) NOT NULL DEFAULT '',
            customer varchar(255) NOT NULL DEFAULT '',
            description text NOT NULL,
            state varchar(60) NOT NULL DEFAULT '',
            kind varchar(60) NOT NULL DEFAULT '',
            methods varchar(255) NOT NULL DEFAULT '',
            method_paid varchar(60) NOT NULL DEFAULT '',
            amount bigint NOT NULL DEFAULT 0,
            paid bigint DEFAULT NULL,
            fee bigint DEFAULT NULL,
            net bigint DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            due_at datetime DEFAULT NULL,
            paid_at datetime DEFAULT NULL,
            synced_at datetime NOT NULL,
            seen_run varchar(32) NOT NULL DEFAULT '',
            visible tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY remote_scope (scope,remote_id),
            KEY scope_state (scope,state),
            KEY scope_due (scope,due_at),
            KEY scope_created (scope,created_at),
            KEY scope_paid (scope,paid_at),
            KEY scope_customer (scope,customer_id)
        ) $collate;");
        dbDelta("CREATE TABLE $c (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(64) NOT NULL,
            remote_id varchar(100) NOT NULL,
            document varchar(20) NOT NULL DEFAULT '',
            synced_at datetime DEFAULT NULL,
            last_try datetime DEFAULT NULL,
            failed tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY remote_scope (scope,remote_id)
        ) $collate;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) !== $t || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($c))) !== $c) { wp_die('Não foi possível criar as tabelas do K8 Financeiro. Verifique as permissões do banco.'); }
        Pay::install();
        update_option('k8f_schema', '2', false);
    }
    public static function save(array $row, string $scope): void {
        global $wpdb; $t = self::table(); $c = self::customers();
        $row['scope'] = $scope;
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE scope=%s AND remote_id=%s", $scope, $row['remote_id']));
        $ok = $id ? $wpdb->update($t, $row, ['id' => $id]) : $wpdb->insert($t, $row);
        if ($ok === false) { throw new \RuntimeException('Falha ao salvar cobrança no banco local. Verifique permissões e espaço disponível.'); }
        if ($row['customer_id'] !== '') {
            $ok = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $c (scope,remote_id) VALUES (%s,%s)", $scope, $row['customer_id']));
            if ($ok === false) { throw new \RuntimeException('Falha ao registrar cliente no banco local.'); }
        }
    }
    public static function payJoin(): string {
        global $wpdb;
        if (empty(Core::settings()['pay_credentials'])) { return ''; }
        $t=Pay::table(); $scope=$wpdb->prepare('%s',Pay::scope());
        return " LEFT JOIN (SELECT charge_id,MIN(id) only_id FROM $t WHERE scope=$scope AND charge_id IS NOT NULL GROUP BY charge_id HAVING COUNT(*)=1) pg ON pg.charge_id=c.id LEFT JOIN $t g ON g.id=pg.only_id";
    }
    public static function enrich(array $rows): array {
        global $wpdb;
        $group=[];
        if ($rows && !empty(Core::settings()['pay_credentials'])) {
            $ids=implode(',',array_map('absint',array_column($rows,'id'))); $t=Pay::table();
            $items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE scope=%s AND charge_id IN ($ids) ORDER BY id",Pay::scope()),ARRAY_A);
            if ($wpdb->last_error) { throw new \RuntimeException('Falha consultando os vínculos Pay.'); }
            foreach($items as $item) { $group[$item['charge_id']][]=$item; }
        }
        foreach($rows as &$r) {
            $items=$group[$r['id']]??[]; $one=count($items)===1?$items[0]:null;
            $r['pay_count']=count($items);
            $r['pay_ids']=implode(' | ',array_column($items,'remote_id'));
            $r['pay_state']=implode(' | ',array_unique(array_column($items,'state')));
            $r['pay_kind']=implode(' | ',array_unique(array_column($items,'kind')));
            foreach(['installment_count'=>'pay_installments','fees'=>'pay_fees','settled'=>'pay_settled','settled_at'=>'pay_settled_at','available_at'=>'pay_available_at'] as $src=>$dest) { $r[$dest]=$one[$src]??null; }
            $r['paid_source']=$r['paid']!==null?'Cob':($one!==null && $one['paid']!==null?'Pay':'');
            $r['fee_source']=$r['fee']!==null?'Cob':($one!==null && $one['fees']!==null?'Pay':'');
            if($r['paid']===null && $one!==null) { $r['paid']=$one['paid']; }
            if($r['fee']===null && $one!==null) { $r['fee']=$one['fees']; }
            $parts=[];
            foreach($items as $item) {
                foreach(json_decode($item['installments']??'[]',true)?:[] as $part) {
                    $parts[]=['pay_id'=>$item['remote_id']]+$part;
                }
            }
            $r['pay_parts']=wp_json_encode($parts);
        }
        unset($r);return $rows;
    }
    public static function where(array $f, string $scope): array {
        global $wpdb;
        $w = ['c.scope=%s']; $p = [$scope];
        if (empty($f['include_missing'])) { $w[] = 'c.visible=1'; }
        foreach (['state', 'kind', 'method_paid'] as $key) {
            if (!empty($f[$key]) && is_scalar($f[$key])) { $w[] = "c.$key=%s"; $p[] = sanitize_text_field($f[$key]); }
        }
        if (!empty($f['methods']) && is_scalar($f['methods'])) { $w[] = 'c.methods LIKE %s'; $p[] = '%' . $wpdb->esc_like(sanitize_text_field($f['methods'])) . '%'; }
        if (!empty($f['q']) && is_scalar($f['q'])) {
            $v = '%' . $wpdb->esc_like(sanitize_text_field($f['q'])) . '%';
            $w[] = '(c.customer LIKE %s OR c.description LIKE %s OR c.remote_id LIKE %s OR c.seq LIKE %s OR u.document LIKE %s)';
            $digits = preg_replace('/\D/', '', (string) $f['q']);
            array_push($p, $v, $v, $v, $v, $digits !== '' ? '%' . $digits . '%' : $v);
        }
        $date = in_array($f['date_field'] ?? '', ['created_at', 'due_at', 'paid_at'], true) ? $f['date_field'] : 'created_at';
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (!empty($f[$key])) {
                $v = is_scalar($f[$key]) ? (string) $f[$key] : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) || Core::date($v) === null) { throw new \RuntimeException('Informe datas válidas.'); }
                $w[] = "c.$date $op %s"; $p[] = $v . ($key === 'from' ? ' 00:00:00' : ' 23:59:59');
            }
        }
        if (!empty($f['from']) && !empty($f['to']) && $f['from'] > $f['to']) { throw new \RuntimeException('A data inicial deve ser anterior à data final.'); }
        foreach (['min' => '>=', 'max' => '<='] as $key => $op) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $v = Core::money($f[$key]); if ($v === null) { throw new \RuntimeException('Filtro de valor inválido.'); }
                $w[] = "c.amount $op %d"; $p[] = $v;
            }
        }
        if (!empty($f['overdue'])) { $w[] = "c.state IN ('Pendente','Inadimplente','Protestado') AND c.due_at < %s"; $p[] = current_time('Y-m-d') . ' 00:00:00'; }
        if (!empty($f['ids'])) {
            $ids = array_slice(array_filter(array_map('absint', (array) $f['ids'])), 0, 500);
            if (!$ids) { $w[] = '1=0'; } else { $w[] = 'c.id IN (' . implode(',', $ids) . ')'; }
        }
        $conditions=[]; $args=[];
        foreach(['pay_state'=>'state','pay_kind'=>'kind','pay_settled'=>'settled'] as $input=>$column) {
            if(isset($f[$input]) && $f[$input]!=='' && is_scalar($f[$input])) { $conditions[]="gp.$column=%s"; $args[]=sanitize_text_field($f[$input]); }
        }
        foreach(['pay_min_installments'=>'>=','pay_max_installments'=>'<='] as $input=>$op) {
            if(isset($f[$input]) && $f[$input]!=='') {
                if(!is_scalar($f[$input]) || !preg_match('/^\d+$/D',(string)$f[$input])) { throw new \RuntimeException('Quantidade de parcelas inválida.'); }
                $conditions[]="gp.installment_count $op %d"; $args[]=absint($f[$input]);
            }
        }
        $linked=$f['pay_linked']??'';
        if($conditions || in_array($linked,['yes','no'],true)) {
            if(empty(Core::settings()['pay_credentials'])) { if($conditions || $linked==='yes')$w[]='1=0'; }
            else {
                $t=Pay::table(); $sub="SELECT 1 FROM $t gp WHERE gp.charge_id=c.id AND gp.scope=%s";
                $w[]=($linked==='no'?'NOT EXISTS (':'EXISTS (').$sub.')'; $p[]=Pay::scope();
                if($conditions) { $w[]='EXISTS ('.$sub.' AND '.implode(' AND ',$conditions).')'; array_push($p,Pay::scope(),...$args); }
            }
        }
        return [implode(' AND ', $w), $p, $date];
    }
    public static function query(array $f, bool $summary = true): array {
        global $wpdb; $t = self::table(); $c = self::customers();
        [$w, $p, $date] = self::where($f, Core::scope());
        $join=self::payJoin();
        $base = "FROM $t c LEFT JOIN $c u ON u.scope=c.scope AND u.remote_id=c.customer_id $join WHERE $w";
        $sort = in_array($f['sort'] ?? '', ['created_at','due_at','paid_at','amount','customer','state'], true) ? $f['sort'] : 'created_at';
        $direction = ($f['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
        $size = min(100, max(10, absint($f['size'] ?? 25))); $page = max(1, absint($f['page'] ?? 1));
        $offset = ($page - 1) * $size;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT c.*,COALESCE(u.document,'') document $base ORDER BY c.$sort $direction,c.id $direction LIMIT %d OFFSET %d", array_merge($p, [$size, $offset])), ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('Falha consultando o banco local.'); }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base", $p));
        $result = ['rows' => self::enrich($rows), 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $size)];
        if (!$summary) { return $result; }
        $today = esc_sql(current_time('Y-m-d') . ' 00:00:00');
        $paid=$join?'COALESCE(c.paid,g.paid)':'c.paid'; $fee=$join?'COALESCE(c.fee,g.fees)':'c.fee';
        $m = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) count, COALESCE(SUM(c.amount),0) nominal,
            COALESCE(SUM(CASE WHEN c.state='Paga' THEN c.amount ELSE 0 END),0) paid_nominal,
            COALESCE(SUM(CASE WHEN c.state IN ('Pendente','Inadimplente','Protestado') THEN c.amount ELSE 0 END),0) open_nominal,
            COALESCE(SUM(CASE WHEN c.state IN ('Pendente','Inadimplente','Protestado') AND c.due_at<'$today' THEN c.amount ELSE 0 END),0) overdue_nominal,
            COALESCE(SUM(CASE WHEN c.state='Cancelada' THEN c.amount ELSE 0 END),0) cancelled,
            SUM($paid) paid, COUNT($paid) paid_known, SUM($fee) fee, COUNT($fee) fee_known,
            SUM(c.net) net, COUNT(c.net) net_known,
            SUM(CASE WHEN c.state='Paga' THEN 1 ELSE 0 END) paid_count
            $base", $p), ARRAY_A);
        $result['metrics'] = $m;
        $result['states'] = $wpdb->get_results($wpdb->prepare("SELECT c.state label,COUNT(*) count,SUM(c.amount) amount $base GROUP BY c.state ORDER BY amount DESC", $p), ARRAY_A);
        $result['methods'] = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(c.method_paid,''),'Não informado') label,COUNT(*) count,SUM(c.amount) amount $base GROUP BY label ORDER BY amount DESC", $p), ARRAY_A);
        $result['months'] = $wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(c.$date,'%%Y-%%m') label,COUNT(*) count,SUM(c.amount) amount $base AND c.$date IS NOT NULL GROUP BY label ORDER BY label DESC LIMIT 12", $p), ARRAY_A);
        $result['months'] = array_reverse($result['months']);
        $result['options'] = [];
        foreach (['state','kind','method_paid'] as $column) {
            $result['options'][$column] = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT $column FROM $t WHERE scope=%s AND $column<>'' ORDER BY $column", Core::scope()));
        }
        $result['sync'] = Sync::status();
        return $result;
    }
}
