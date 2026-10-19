<?php
if (!defined("ABSPATH")) exit;
function jao_digits($value) {
    return preg_replace('/\D/', '', is_scalar($value) ? (string) $value : '');
}
function jao_valid_cpf($cpf) {
    if (!preg_match('/^\d{11}$/D', $cpf) || preg_match('/^(\d)\1{10}$/D', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int) $cpf[$i] * ($t + 1 - $i);
        $digit = ($sum * 10) % 11;
        if ($digit === 10) $digit = 0;
        if ((int) $cpf[$t] !== $digit) return false;
    }
    return true;
}
function jao_phone($value) {
    // Cadastro brasileiro: DDD + número ou 55 + DDD + número. Não presume DDD.
    $n = jao_digits($value);
    if (strlen($n) === 10 || strlen($n) === 11) $n = '55' . $n;
    return preg_match('/^55[1-9][0-9](?:[2-5][0-9]{7}|9[0-9]{8})$/D', $n) ? '+' . $n : '';
}

function jao_registered_phone($cpf, $students) {
    $phones = array();
    foreach ($students as $student) {
        if (($student->telefone_status ?? '') === 'conflict') return '';
        foreach (array(1,2) as $n) {
            if (jao_digits($student->{'cpf_resp'.$n}) !== $cpf) continue;
            $phone = jao_phone($student->{'telefone_resp'.$n} ?? '');
            // Reutiliza apenas telefone cadastrado para o MESMO CPF em outro vínculo.
            if ($phone) $phones[$phone] = true;
        }
    }
    return count($phones) === 1 ? (string) array_key_first($phones) : '';
}
/** Enabled by default; only an explicit 0 disables WhatsApp. */
function jao_whatsapp_required($settings = null) {
    $settings = is_array($settings) ? $settings : jao_settings();
    return (string)($settings['whatsapp_validation'] ?? '1') !== '0';
}
/** A policy change invalidates all sessions, even after switching back. */
function jao_session_hash($token) {
    $revision = jao_settings()['auth_revision'] ?? '1';
    return jao_hash('session|'.$revision.'|'.$token);
}
function jao_require_whatsapp() {
    if (!jao_whatsapp_required()) throw new RuntimeException('A validação pelo WhatsApp está desligada. Consulte seu CPF novamente.');
}
function jao_hash($value) { return hash_hmac('sha256', $value, wp_salt('auth')); }
function jao_limit($scope, $identity, $max, $seconds) {
    global $wpdb;
    $window = (int) floor(time() / $seconds);
    $key = jao_hash($scope . '|' . $identity . '|' . $window);
    $expiry = ($window + 1) * $seconds;
    // Contador atômico no MySQL; evita corrida de get/set_transient em requisições paralelas.
    $ok = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}jao_bird_limits (bucket,hits,expires)
        VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1", $key, $expiry));
    if ($ok === false) return false;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM {$wpdb->prefix}jao_bird_limits WHERE bucket=%s", $key)) <= $max;
}
function jao_cooldown($phone) {
    global $wpdb;
    $key = jao_hash('cooldown:' . $phone);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}jao_bird_limits WHERE bucket=%s AND expires<=%d", $key, time()));
    return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}jao_bird_limits (bucket,hits,expires) VALUES (%s,1,%d)", $key, time()+60)) === 1;
}
function jao_fail($message, $status = 400) { wp_send_json_error(array('message'=>$message), $status); }
function jao_ajax_guard() {
    nocache_headers();
    header('Cache-Control: no-store, private, max-age=0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jao_fail('Método não permitido.', 405);
    if (!is_ssl()) jao_fail('O acesso exige uma conexão HTTPS.', 403);
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? wp_parse_url($_SERVER['HTTP_ORIGIN']) : false;
    $site = wp_parse_url(home_url('/'));
    if (!$origin || strtolower($origin['host'] ?? '') !== strtolower($site['host'] ?? '') ||
        ($origin['scheme'] ?? '') !== ($site['scheme'] ?? '') || ($origin['port'] ?? 443) !== ($site['port'] ?? 443)) {
        jao_fail('Origem não permitida.', 403);
    }
    // REMOTE_ADDR é controlado pelo servidor; não confiar em cabeçalhos de proxy enviados pelo visitante.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!jao_limit('requests', $ip, 120, 600)) jao_fail('Muitas solicitações. Aguarde alguns minutos.', 429);
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}jao_bird_sessions WHERE expires < %d LIMIT 200", time()));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}jao_bird_limits WHERE expires < %d LIMIT 200", time()));
    return $ip;
}
function jao_post($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
}
function jao_session() {
    global $wpdb;
    $token = jao_post('token');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) jao_fail('Sessão expirada. Consulte seu CPF novamente.', 401);
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jao_bird_sessions WHERE token_hash=%s AND expires>%d", jao_session_hash($token), time()));
    if (!$session) jao_fail('Sessão expirada. Consulte seu CPF novamente.', 401);
    return $session;
}
function jao_require_access() {
    $session = jao_session();
    $whatsapp = jao_whatsapp_required();
    $expected_state = $whatsapp ? 'verified' : 'identified';
    if ($session->state !== $expected_state) jao_fail('Consulte seu CPF novamente para continuar o acesso.', 401);
    $students = jao_cpf_students($session->cpf);
    if (!$students) jao_fail('Cadastro alterado ou sem alunos vinculados. Consulte seu CPF novamente.', 403);
    if ($whatsapp && jao_registered_phone($session->cpf, $students) !== $session->phone) {
        jao_fail('Cadastro alterado. Inicie uma nova validação.', 403);
    }
    return array($session, $students);
}

function jao_bird_check() {
    jao_require_whatsapp();
    $session = jao_session();
    $code = jao_post('code');
    if (!preg_match('/^\d{6}$/D', $code)) jao_fail('Digite os 6 números do código.');
    if ($session->state !== 'pending' || (int) $session->attempts >= 5) jao_fail('Validação encerrada. Solicite um novo código.', 401);
    if (jao_registered_phone($session->cpf, jao_cpf_students($session->cpf)) !== $session->phone) jao_fail('Cadastro alterado. Comece novamente.', 403);
    global $wpdb;
    $lock = 'jao_' . substr(jao_hash('phone:' . $session->phone), 0, 48);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') jao_fail('Há uma solicitação em andamento. Aguarde.', 429);
    $next_token = bin2hex(random_bytes(32));
    $error = 'Código incorreto, expirado ou validação encerrada. Confira o código ou comece novamente.';
    $success = false;
    try {
        $claimed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}jao_bird_sessions SET state='checking', attempts=attempts+1
            WHERE token_hash=%s AND state='pending' AND attempts<5 AND expires>%d", $session->token_hash, time()));
        if ($claimed === 1) {
            $success = hash_equals($session->bird_id, jao_otp_hash(jao_post('token'),$code));
            if ($success) {
                // Rotação do token após OTP; CPF e telefone continuam definidos exclusivamente pelo servidor.
                $success = $wpdb->update($wpdb->prefix . 'jao_bird_sessions', array('state'=>'verified',
                    'token_hash'=>jao_session_hash($next_token), 'bird_id'=>'', 'expires'=>time()+900), array('token_hash'=>$session->token_hash, 'state'=>'checking')) === 1;
            } else {
                $retry = (int) $session->attempts + 1 < 5;
                $wpdb->update($wpdb->prefix . 'jao_bird_sessions', array('state'=>$retry ? 'pending' : 'failed'), array('token_hash'=>$session->token_hash));
            }
        }
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    if (!$success) jao_fail($error, 401);
    wp_send_json_success(array('token'=>$next_token, 'expires_in'=>900));
}
/** Selects the configured flow on the server; the browser cannot disable OTP. */
function jao_identify() {
    global $wpdb;
    $cpf = jao_digits(jao_post('cpf'));
    if (!jao_valid_cpf($cpf)) throw new RuntimeException('Digite um CPF válido.');
    if (!jao_limit('lookup-ip', $_SERVER['REMOTE_ADDR'] ?? '', 20, 900) ||
        !jao_limit('lookup-cpf', $cpf, 8, 900)) throw new RuntimeException('Muitas consultas. Aguarde 15 minutos.');
    $students = jao_cpf_students($cpf);
    if (!$students) throw new RuntimeException('Não foi possível localizar alunos vinculados a este CPF. Confira o número ou procure a secretaria.');
    $whatsapp = jao_whatsapp_required();
    $phone = $whatsapp ? jao_registered_phone($cpf, $students) : '';
    if ($whatsapp && !$phone) throw new RuntimeException('Não foi possível validar o cadastro. Confira seu CPF ou solicite à secretaria a atualização do WhatsApp.');
    $token = bin2hex(random_bytes(32));
    $expires_in = $whatsapp ? 300 : 900;
    $saved = $wpdb->insert($wpdb->prefix.'jao_bird_sessions', [
        'token_hash'=>jao_session_hash($token), 'cpf'=>$cpf, 'phone'=>$phone, 'bird_id'=>'',
        'state'=>$whatsapp ? 'preview' : 'identified', 'attempts'=>0, 'expires'=>time()+$expires_in,
    ]);
    if (!$saved) throw new RuntimeException('Não foi possível iniciar. Tente novamente.');
    $result = ['token'=>$token, 'requires_whatsapp'=>$whatsapp, 'expires_in'=>$expires_in];
    if ($whatsapp) $result['last4'] = substr(jao_digits($phone), -4);
    return $result;
}
function jao_send() {
    jao_require_whatsapp();
    global $wpdb;
    $session=jao_session();
    if ($session->state!=='preview') throw new RuntimeException('Inicie novamente para solicitar outro código.');
    $phone=jao_registered_phone($session->cpf,jao_cpf_students($session->cpf));
    if (!$phone || $phone!==$session->phone) throw new RuntimeException('Cadastro alterado. Consulte seu CPF novamente.');
    if (!jao_limit('send-ip',$_SERVER['REMOTE_ADDR']??'',10,3600)||!jao_limit('send-cpf',$session->cpf,3,900)||!jao_limit('send-global','site',100,3600)||!jao_cooldown($phone)) throw new RuntimeException('Aguarde antes de solicitar outro código. Máximo de 3 envios a cada 15 minutos.');
    $lock=jao_lock('phone:'.$phone);
    try {
        $claimed=$wpdb->update($wpdb->prefix.'jao_bird_sessions',['state'=>'sending'],['token_hash'=>$session->token_hash,'state'=>'preview']);
        if ($claimed!==1) throw new RuntimeException('Solicitação já processada. Comece novamente.');
        $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $id=jao_secret('nx_id'); $secret=jao_secret('nx_token');
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id)||!$secret) throw new RuntimeException('Canal de WhatsApp ainda não configurado.');
        jao_db_update('jao_bird_sessions',['bird_id'=>jao_otp_hash(jao_post('token'),$code),'expires'=>time()+300],['token_hash'=>$session->token_hash]);
        $r=wp_remote_post('https://appapi.nxsystems.com.br/v2/api/external/'.$id.'/',[
            'timeout'=>20,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>65536,
            'headers'=>['Authorization'=>'Bearer '.$secret,'Content-Type'=>'application/json','Accept'=>'application/json'],
            'body'=>wp_json_encode(['body'=>'Seu código de acesso às atividades opcionais é: '.$code.'. Válido por 5 minutos. Não compartilhe.','number'=>jao_digits($phone),'externalKey'=>'jao-'.bin2hex(random_bytes(16)),'isClosed'=>false])]);
        if (is_wp_error($r)) throw new RuntimeException('Falha solicitando o código ao WhatsApp. Aguarde e tente novamente.');
        $status=wp_remote_retrieve_response_code($r); $data=json_decode(wp_remote_retrieve_body($r),true);
        if ($status<200||$status>=300||!is_array($data)||!$data||!empty($data['error'])||!empty($data['errors'])||(isset($data['success'])&&in_array($data['success'],[false,0,'false','0'],true))||in_array(strtolower((string)($data['status']??'')),['error','failed','failure'],true)) throw new RuntimeException('O provedor não confirmou a solicitação. Procure a secretaria caso não receba.');
        jao_db_update('jao_bird_sessions',['state'=>'pending'],['token_hash'=>$session->token_hash]);
        return ['message'=>'Código solicitado. Confira seu WhatsApp.','expires_in'=>300];
    } catch (Throwable $e) { $wpdb->delete($wpdb->prefix.'jao_bird_sessions',['token_hash'=>$session->token_hash]); throw $e; }
    finally { jao_unlock($lock); }
}
function jao_students() {
    [$session,$students]=jao_require_access(); $out=[];
    foreach ($students as $s) $out[$s->key]=['key'=>$s->key,'name'=>$s->nome_aluno,'class'=>$s->turma];
    return ['students'=>array_values($out)];
}
function jao_selected() {
    [$session,$students]=jao_require_access();
    foreach ($students as $s) if (hash_equals($s->key,jao_post('student'))) return [$session,$s];
    throw new RuntimeException('Aluno não vinculado a este CPF.');
}
function jao_otp_hash($token,$code) { return 'otp:'.jao_hash('otp|'.$token.'|'.$code); }
