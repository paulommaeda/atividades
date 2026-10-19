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
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jao_bird_sessions WHERE token_hash=%s AND expires>%d", jao_hash($token), time()));
    if (!$session) jao_fail('Sessão expirada. Consulte seu CPF novamente.', 401);
    return $session;
}
/** CPF lookup identifies a local guardian; it is not proof of ownership of the CPF. */
function jao_require_identified() {
    $session = jao_session();
    if ($session->state !== 'identified') jao_fail('Consulte seu CPF novamente para acessar os alunos.', 401);
    // Re-read the mirror on every operation, including catalog and checkout.
    $students = jao_cpf_students($session->cpf);
    if (!$students) jao_fail('Cadastro alterado ou sem alunos vinculados. Consulte seu CPF novamente.', 403);
    return array($session, $students);
}
function jao_identify() {
    global $wpdb;
    $cpf = jao_digits(jao_post('cpf'));
    if (!jao_valid_cpf($cpf)) throw new RuntimeException('Digite um CPF válido.');
    if (!jao_limit('lookup-ip', $_SERVER['REMOTE_ADDR'] ?? '', 20, 900) ||
        !jao_limit('lookup-cpf', $cpf, 8, 900)) throw new RuntimeException('Muitas consultas. Aguarde 15 minutos.');
    $students = jao_cpf_students($cpf);
    if (!$students) throw new RuntimeException('Não foi possível localizar alunos vinculados a este CPF. Confira o número ou procure a secretaria.');
    $token = bin2hex(random_bytes(32));
    // Keep the existing table/columns so upgrades preserve all data without migration.
    $saved = $wpdb->insert($wpdb->prefix.'jao_bird_sessions', [
        'token_hash'=>jao_hash($token), 'cpf'=>$cpf, 'phone'=>'', 'bird_id'=>'',
        'state'=>'identified', 'attempts'=>0, 'expires'=>time()+900,
    ]);
    if (!$saved) throw new RuntimeException('Não foi possível iniciar. Tente novamente.');
    return ['token'=>$token, 'expires_in'=>900];
}
function jao_students() {
    [$session,$students]=jao_require_identified(); $out=[];
    foreach ($students as $s) $out[$s->key]=['key'=>$s->key,'name'=>$s->nome_aluno,'class'=>$s->turma];
    return ['students'=>array_values($out)];
}
function jao_selected() {
    [$session,$students]=jao_require_identified();
    foreach ($students as $s) if (hash_equals($s->key,jao_post('student'))) return [$session,$s];
    throw new RuntimeException('Aluno não vinculado a este CPF.');
}
