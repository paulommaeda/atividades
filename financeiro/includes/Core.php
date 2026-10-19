<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class Core {
    public static function settings(): array { return (array) get_option('k8f_settings', []); }
    public static function seal(string $text): string {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($text, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('Falha protegendo credenciais.'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function unseal(string $value): string {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) { throw new \RuntimeException('Credenciais inválidas. Salve novamente.'); }
        $text = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($text === false) { throw new \RuntimeException('Credenciais inacessíveis. As chaves do WordPress podem ter mudado. Salve novamente.'); }
        return $text;
    }
    public static function credentials(): array {
        $s = self::settings();
        if (empty($s['credentials'])) { throw new \RuntimeException('Configure as credenciais em K8 Financeiro → Configurações.'); }
        $c = json_decode(self::unseal($s['credentials']), true);
        if (!is_array($c) || empty($c['id']) || empty($c['secret']) || empty($c['aes'])) { throw new \RuntimeException('Preencha as três credenciais.'); }
        return $c;
    }
    public static function scope(): string {
        $c = self::credentials();
        return hash('sha256', (self::settings()['environment'] ?? 'production') . ':' . $c['id']);
    }
    public static function value(array $data, string $path) {
        $v = $data;
        foreach (explode('.', $path) as $key) { if (!is_array($v) || !array_key_exists($key, $v)) { return null; } $v = $v[$key]; }
        return $v;
    }
    public static function money($value): ?int {
        if ($value === null || $value === '' || is_bool($value) || !is_scalar($value)) { return null; }
        // API amounts are decimal numbers, never formatted Brazilian thousands.
        $v = (string) $value;
        if (!preg_match('/^-?\d{1,12}(?:\.\d+)?$/D', $v)) { return null; }
        $negative = $v[0] === '-'; $v = ltrim($v, '-');
        $parts = explode('.', $v); $fraction = str_pad($parts[1] ?? '', 3, '0');
        $n = ((int) $parts[0] * 100) + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
        return $negative ? -$n : $n;
    }
    public static function date($value): ?string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || substr($value, 0, 4) < '1900') { return null; }
        try {
            $d = new \DateTimeImmutable($value, wp_timezone());
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) { return null; }
            return $d->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
        } catch (\Throwable $e) { return null; }
    }
    public static function text($v): string { return is_scalar($v) ? sanitize_text_field((string) $v) : ''; }
    public static function normalize(array $row): array {
        $id = self::text($row['Id'] ?? ''); $amount = self::money($row['Valor'] ?? null);
        if ($id === '' || strlen($id) > 100 || $amount === null || !isset($row['Estado'])) { throw new \RuntimeException('Formato de cobrança não reconhecido: Id, Valor ou Estado ausente/inválido. Sincronização interrompida sem avançar a página.'); }
        $s = self::settings();
        $methods = $row['FormasPagamento'] ?? '';
        if (is_array($methods)) { $methods = implode(', ', array_map([self::class, 'text'], $methods)); }
        return [
            'pay_ref' => self::text(self::value($row, $s['map_pay_id'] ?? 'DadosPagamento.TransacaoId')),
            'remote_id' => $id, 'seq' => self::text($row['IdentificadorSequencial'] ?? ''),
            'customer_id' => self::text($row['ClienteId'] ?? ''), 'customer' => self::text($row['ClienteNome'] ?? ''),
            'description' => self::text($row['Descricao'] ?? ''), 'state' => self::text($row['Estado']),
            'kind' => self::text($row['Tipo'] ?? ''), 'methods' => self::text($methods),
            'method_paid' => self::text(self::value($row, $s['map_method'] ?? 'DadosPagamento.FormaPagamento')),
            'amount' => $amount,
            'paid' => self::money(self::value($row, $s['map_paid'] ?? 'DadosPagamento.ValorPago')),
            'fee' => self::money(self::value($row, $s['map_fee'] ?? 'DadosPagamento.ValorTaxa')),
            'net' => self::money(self::value($row, $s['map_net'] ?? 'DadosPagamento.ValorLiquido')),
            'created_at' => self::date($row['Criacao'] ?? null), 'due_at' => self::date($row['Vencimento'] ?? null),
            'paid_at' => self::date(self::value($row, $s['map_date'] ?? 'DadosPagamento.DataPagamento')),
            'synced_at' => current_time('mysql'),
        ];
    }
    public static function csv($value): string {
        $v = (string) ($value ?? '');
        return preg_match('/^[\s\x00-\x1F]*[=+@\-]/u', $v) ? "'" . $v : $v;
    }
}
