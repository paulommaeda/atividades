<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class PayAdmin {
    public static function boot(): void {
        add_action('wp_ajax_k8f_pay',[self::class,'ajax']);
        add_action('admin_post_k8f_pay_save',[self::class,'save']);
        add_action('admin_enqueue_scripts',function($hook){
            if($hook==='toplevel_page_k8f')wp_enqueue_script('k8f-pay',plugins_url('assets/pay.js',K8F_FILE),['k8f'],'1.2.0',true);
        });
        add_action('k8f_pay_tick',[self::class,'cron']);
        if(!wp_next_scheduled('k8f_pay_tick'))wp_schedule_event(time()+300,'k8f_pay_five','k8f_pay_tick');
    }
    private static function guard(): void {if(!current_user_can('manage_options'))wp_die('Acesso restrito.','',['response'=>403]);}
    public static function ajax(): void {
        self::guard();check_ajax_referer('k8f','nonce');nocache_headers();
        try{
            $f=json_decode(wp_unslash($_POST['filters']??'{}'),true);if(!is_array($f))$f=[];
            switch(sanitize_key($_POST['op']??'')){
                case 'query':$data=Pay::query($f);break;
                case 'charge':$data=Pay::forCharge(absint($_POST['id']??0));break;
                case 'link':$data=Pay::connect(absint($_POST['id']??0),Core::text(wp_unslash($_POST['remote']??'')),!empty($_POST['unlink']));break;
                case 'one':$lock=Sync::lock();try{$data=Pay::one(Core::text(wp_unslash($_POST['remote']??'')));}finally{Sync::unlock($lock);}break;
                case 'sync':$data=Pay::step(isset($_POST['from'])?Core::text($_POST['from']):null,isset($_POST['to'])?Core::text($_POST['to']):null);break;
                case 'test':$today=current_time('Y-m-d');$r=(new Api('pay'))->consultPay('ConsultaTransacoes',['DataHoraInicial'=>$today.'T00:00:00','DataHoraFinal'=>$today.'T23:59:59','Tipo'=>'Cartao','TipoFiltro'=>'dataemissao']);if(!isset($r['Transacoes'])||!is_array($r['Transacoes']))throw new \RuntimeException('Conexão respondeu, mas a lista Transacoes não foi reconhecida.');$data=['message'=>'Conexão Pay válida. Consulta de cartão autorizada.'];break;
                case 'export':self::export($f);exit;
                default:throw new \RuntimeException('Operação Pay inválida.');
            }
            wp_send_json_success($data);
        }catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],400);}
    }
    public static function save(): void {
        self::guard();check_admin_referer('k8f_pay_save');
        try{
            $s=Core::settings();
            if(!empty($_POST['pay_copy']))$c=Core::credentials();
            else{
                $c=empty($s['pay_credentials'])?['id'=>'','secret'=>'','aes'=>'']:Pay::credentials();
                foreach(['id','secret','aes']as $field){$v=trim((string)wp_unslash($_POST['pay_'.$field]??''));if($v!=='')$c[$field]=$v;}
            }
            if(empty($c['id'])||empty($c['secret'])||empty($c['aes']))throw new \RuntimeException('Preencha as três credenciais Pay.');
            $map=trim((string)wp_unslash($_POST['map_pay_id']??'DadosPagamento.TransacaoId'));
            if(!preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D',$map))throw new \RuntimeException('Caminho do ID Pay inválido.');
            $s['pay_credentials']=Core::seal(wp_json_encode($c));$s['map_pay_id']=$map;$s['pay_automatic']=!empty($_POST['pay_automatic']);
            update_option('k8f_settings',$s,false);wp_safe_redirect(admin_url('admin.php?page=k8f-settings&saved=1'));exit;
        }catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'Configuração Pay',['back_link'=>true]);}
    }
    public static function settings(): void {
        $s=Core::settings(); ?>
        <hr><h2>K8 Pay · Gateway e parcelas de cartão</h2>
        <p>Use as credenciais do módulo Pagamentos. O ambiente segue a configuração Cobranças acima. Nenhuma transação será criada, capturada ou cancelada.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="k8f_pay_save"><?php wp_nonce_field('k8f_pay_save'); ?>
        <table class="form-table"><tbody>
        <tr><th>Reaproveitar credenciais</th><td><label><input type="checkbox" name="pay_copy" value="1"> Copiar as credenciais de Cobranças (somente se também autorizadas para K8 Pay)</label></td></tr>
        <?php foreach(['id'=>'Client ID Pay','secret'=>'Client Secret Pay','aes'=>'Chave AES Pay']as $field=>$label): ?>
        <tr><th><?php echo esc_html($label); ?></th><td><input type="password" class="regular-text" name="pay_<?php echo esc_attr($field); ?>" autocomplete="new-password" placeholder="Vazio mantém a credencial salva"></td></tr>
        <?php endforeach; ?>
        <tr><th>ID Pay dentro da cobrança</th><td><input class="regular-text" name="map_pay_id" value="<?php echo esc_attr($s['map_pay_id']??'DadosPagamento.TransacaoId'); ?>"><p class="description">Caminho candidato: confirme em Ver → Consultar nomes dos campos da API. Apenas uma igualdade exata com o Identificador Pay permite vínculo automático. Sem ID confirmado, associe manualmente nos detalhes da cobrança. Após alterar, sincronize Cobranças e depois Pay.</p></td></tr>
        <tr><th>Atualização Pay automática</th><td><label><input type="checkbox" name="pay_automatic" value="1" <?php checked(!empty($s['pay_automatic'])); ?>> Habilitar consultas Pay pelo WP-Cron</label><p class="description">A cada 5 minutos alterna entre retomar/importar os últimos 3 dias e atualizar a transação armazenada há mais tempo. Não é tempo real.</p></td></tr>
        </tbody></table><?php submit_button('Salvar K8 Pay'); ?></form>
        <?php
    }
    public static function cron(): void {
        if(empty(Core::settings()['pay_automatic']))return;
        try{
            $key=Pay::key();$turn=(int)get_option($key.'_turn',0);update_option($key.'_turn',$turn+1,false);
            if($turn%2===0){
                global $wpdb;$t=Pay::table();$cut=wp_date('Y-m-d H:i:s',time()-900);
                $id=$wpdb->get_var($wpdb->prepare("SELECT remote_id FROM $t WHERE scope=%s AND synced_at<%s ORDER BY synced_at ASC,id ASC LIMIT 1",Pay::scope(),$cut));
                if($id){$lock=Sync::lock();try{Pay::one($id);}finally{Sync::unlock($lock);}return;}
            }
            $state=(array)get_option($key,[]);
            if(($state['retry_at']??0)>time())return;
            if(!$state||!empty($state['done'])){
                Pay::step(wp_date('Y-m-d',time()-2*86400),current_time('Y-m-d'));
            }else{Pay::step();}
            delete_option($key.'_cron_error');
        }catch(\Throwable $e){if(isset($key))update_option($key.'_cron_error',$e->getMessage(),false);}
    }
    private static function export(array $f): void {
        $f['page']=1;$probe=Pay::query($f);
        header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="k8-pay-parcelas.csv"');header('X-Content-Type-Options: nosniff');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['ID cobrança','ID Pay','Cliente','CPF/CNPJ','Pedido','Tipo','Estado transação','Liquidado informado','Quantidade de parcelas retornadas','Número da parcela','Estado da parcela','Valor da parcela (R$)','Pagamento da parcela','Taxas da transação (R$) — não somar por parcela'], ';','"','');
        $data=$probe;
        while($data['rows']){
            foreach($data['rows']as $r){
                $parts=$r['installments']?:[['number'=>'','state'=>'','amount'=>null,'paid_at'=>null]];
                foreach($parts as $i=>$p){
                    $fields=[$r['charge_remote_id']??'',$r['remote_id'],$r['customer'],$r['document'],$r['order_number'],$r['kind'],$r['state'],$r['settled']===null?'':((int)$r['settled']===1?'Sim':'Não'),$r['installment_count'],$p['number'],$p['state'],$p['amount']===null?'':number_format((int)$p['amount']/100,2,',',''),$p['paid_at'],$i===0&&$r['fees']!==null?number_format((int)$r['fees']/100,2,',',''):''];
                    fputcsv($out,array_map([Core::class,'csv'],$fields),';','"','');
                }
            }
            $last=end($data['rows']);$f['before_id']=$last['id'];$data=Pay::query($f);
        }
        fclose($out);
    }
    public static function panel(): void { ?>
        <details class="kf-panel"><summary>Atualizar dados de pagamento das cobranças</summary>
        <p class="kf-note">Importe o período para preencher os dados Pay nas cobranças correspondentes. Transações sem vínculo não entram nos totais; associe pelo ID nos detalhes da cobrança. Consulte a cobertura com o portal K8.</p>
        <div id="kp-message" role="status"></div>
        <div class="kf-actions kp-import"><label>Criação no Pay: de <input type="date" id="kp-import-from" value="<?php echo esc_attr(wp_date('Y-m-d',time()-30*86400)); ?>"></label><label>até <input type="date" id="kp-import-to" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label><button id="kp-start">Importar período</button><button id="kp-resume">Retomar</button><button id="kp-stop" hidden>Pausar</button><button id="kp-test">Testar conexão Pay</button></div>
        <p id="kp-sync-status" class="kf-note"></p>
        </details>
    <?php }
}
