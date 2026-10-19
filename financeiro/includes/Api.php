<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class Api {
    private array $credentials;
    private string $base;
    private string $cache;
    public function __construct(string $module = 'cob') {
        if (!function_exists('openssl_decrypt')) { throw new \RuntimeException('A extensão OpenSSL do PHP é necessária.'); }
        $pay = $module === 'pay';
        $this->credentials = $pay ? Pay::credentials() : Core::credentials();
        $this->base = (Core::settings()['environment'] ?? 'production') === 'sandbox'
            ? 'https://sandbox.meuk8.com.br/CobrancasAPI' : 'https://pagamentos.meuk8.com.br/CobrancasAPI';
        if ($pay) { $this->base = str_replace('CobrancasAPI', 'PagamentosAPI', $this->base); }
        $this->cache = 'k8f_token_' . hash('sha256', $this->base . wp_json_encode($this->credentials));
    }
    private function transport(string $path, array $args): array {
        $r = wp_remote_request($this->base . $path, array_merge([
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 8 * 1024 * 1024,
        ], $args));
        if (is_wp_error($r)) { throw new \RuntimeException('Falha de rede ao consultar o K8. Verifique a saída HTTPS do servidor e tente novamente.'); }
        return $r;
    }
    private function token(): string {
        $cached = get_transient($this->cache);
        if (is_string($cached) && $cached !== '') { return Core::unseal($cached); }
        $r = $this->transport('/token', ['method' => 'POST', 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => [
            'username' => $this->credentials['id'], 'password' => $this->credentials['secret'], 'grant_type' => 'password',
        ]]);
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code !== 200) { throw new \RuntimeException('Autenticação K8 recusada (HTTP ' . $code . '). Confira credenciais, ambiente e permissão da API selecionada.'); }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        $token = $data['access_token'] ?? $data['token'] ?? null;
        if (!is_string($token) || $token === '') { throw new \RuntimeException('K8 não retornou token válido.'); }
        $ttl = max(1, min(3600, (int) ($data['expires_in'] ?? 300) - 30));
        set_transient($this->cache, Core::seal($token), $ttl);
        return $token;
    }
    public static function decode(string $body, string $key): array {
        $body = trim($body); $json = json_decode($body, true);
        if (is_array($json)) { return $json; }
        $encoded = is_string($json) ? $json : $body;
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) { throw new \RuntimeException('Resposta K8 inválida: base64 não reconhecido.'); }
        $plain = openssl_decrypt($bytes, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16));
        $result = $plain === false ? null : json_decode($plain, true);
        if (!is_array($result)) { throw new \RuntimeException('Não foi possível decodificar a resposta K8. Confira a chave AES e o formato de retorno.'); }
        return $result; // [] is a valid final page, not a decryption error.
    }
    public function get(string $path): array {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $r = $this->transport($path, ['method' => 'GET', 'headers' => [
                'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->token(), 'Aeskey' => $this->credentials['aes'],
            ]]);
            $code = (int) wp_remote_retrieve_response_code($r);
            if ($code === 401 && $attempt === 0) { delete_transient($this->cache); continue; }
            if ($code === 429) { throw new \RuntimeException('Limite de consultas do K8 atingido (HTTP 429). Aguarde antes de retomar.'); }
            if ($code < 200 || $code >= 300) { throw new \RuntimeException('Consulta K8 falhou (HTTP ' . $code . '). Nenhum dado financeiro foi alterado no K8.'); }
            return self::decode(wp_remote_retrieve_body($r), $this->credentials['aes']);
        }
        throw new \RuntimeException('Não foi possível renovar a autenticação K8.');
    }
    /** Financial mutations are never automatically retried after an ambiguous response. */
    public function create(string $endpoint, array $body): array {
        if (!in_array($endpoint, ['Cliente','Cobranca'], true)) throw new \RuntimeException('Operação não permitida.');
        $cipher=openssl_encrypt(wp_json_encode($body), 'AES-128-CBC', $this->credentials['aes'], OPENSSL_RAW_DATA, str_repeat("\0",16));
        if ($cipher===false) throw new \RuntimeException('Falha preparando cobrança.');
        $r=$this->transport('/api/'.$endpoint,['method'=>'POST','headers'=>[
            'Authorization'=>'Bearer '.$this->token(),'Aeskey'=>$this->credentials['aes'],'Content-Type'=>'application/json','Accept'=>'application/json'
        ],'body'=>base64_encode($cipher)]);
        $code=wp_remote_retrieve_response_code($r);
        if ($code<200||$code>=300) throw new \RuntimeException('K8 não confirmou a operação (HTTP '.$code.'). Conferência necessária antes de nova tentativa.');
        return self::decode(wp_remote_retrieve_body($r),$this->credentials['aes']);
    }
    public function consultPay(string $endpoint, array $body): array {
        if (!in_array($endpoint, ['ConsultaTransacoes','ConsultaTransacao'], true) || strpos($this->base, '/PagamentosAPI') === false) { throw new \RuntimeException('Operação Pay não permitida.'); }
        $cipher = openssl_encrypt(wp_json_encode($body), 'AES-128-CBC', $this->credentials['aes'], OPENSSL_RAW_DATA, str_repeat("\0", 16));
        if ($cipher === false) { throw new \RuntimeException('Falha ao preparar consulta Pay.'); }
        for ($attempt=0; $attempt<2; $attempt++) {
            $r = $this->transport('/api/' . $endpoint, ['method'=>'POST', 'headers'=>[
                'Accept'=>'application/json', 'Content-Type'=>'application/json',
                'Authorization'=>'Bearer ' . $this->token(), 'Aeskey'=>$this->credentials['aes'],
            ], 'body'=>base64_encode($cipher)]);
            $code = (int) wp_remote_retrieve_response_code($r);
            if ($code === 401 && $attempt === 0) { delete_transient($this->cache); continue; }
            if ($code < 200 || $code >= 300) { throw new \RuntimeException('Consulta K8 Pay falhou (HTTP ' . $code . '). Aguarde e confira credenciais/permissões.'); }
            $data = self::decode(wp_remote_retrieve_body($r), $this->credentials['aes']);
            if (strtoupper((string) ($data['Retorno'] ?? '')) !== 'OK') { throw new \RuntimeException('K8 Pay não confirmou Retorno=OK. A importação não avançou.'); }
            return $data;
        }
        throw new \RuntimeException('Não foi possível autenticar no K8 Pay.');
    }
    public static function rows(array $data): array {
        if ($data === [] || array_keys($data) === range(0, count($data) - 1)) { $rows = $data; }
        else {
            $rows = null;
            foreach (['Items', 'items', 'Data', 'data', 'Resultados', 'Cobrancas'] as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && ($data[$key] === [] || array_keys($data[$key]) === range(0, count($data[$key]) - 1))) { $rows = $data[$key]; break; }
            }
            if ($rows === null) { throw new \RuntimeException('Envelope da listagem K8 não reconhecido. É necessário conferir o formato real da resposta.'); }
        }
        foreach ($rows as $row) { if (!is_array($row) || empty($row['Id'])) { throw new \RuntimeException('Item inválido na listagem K8.'); } }
        return $rows;
    }
    public function page(int $start): array { return self::rows($this->get('/api/Cobranca?Start=' . $start . '&Size=20')); }
    public function charge(string $id): array { return $this->get('/api/Cobranca/' . rawurlencode($id)); }
    public function customer(string $id): array { return $this->get('/api/Cliente/' . rawurlencode($id)); }
}
