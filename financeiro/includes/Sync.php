<?php
namespace K8F;
if (!defined('ABSPATH')) { exit; }
final class Sync {
    public static function key(): string { return 'k8f_sync_' . Core::scope(); }
    public static function status(): array {
        global $wpdb; $t = Store::table(); $c = Store::customers(); $scope = Core::scope();
        $state = (array) get_option(self::key(), []);
        $state['cached'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE scope=%s", $scope));
        $state['missing'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE scope=%s AND visible=0", $scope));
        $state['customer_pending'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE scope=%s AND synced_at IS NULL", $scope));
        return $state;
    }
    public static function lock(): string {
        global $wpdb;
        $name = 'k8f_' . substr(hash('sha256', $wpdb->prefix . Core::scope()), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name)) !== '1') { throw new \RuntimeException('Outra sincronização está em andamento. Aguarde e tente novamente.'); }
        return $name;
    }
    public static function unlock(string $name): void { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    public static function step(bool $restart = false): array {
        global $wpdb; $name = self::lock(); $key = self::key(); $s = (array) get_option($key, []);
        try {
            if ($restart || empty($s['phase']) || $s['phase'] === 'done') {
                $s = ['phase' => 'charges', 'offset' => 0, 'processed' => 0, 'started' => current_time('mysql'), 'complete_at' => $s['complete_at'] ?? null, 'seen_hashes' => [], 'run' => bin2hex(random_bytes(16))];
            }
            if (!empty($s['retry_at']) && time() < $s['retry_at']) { throw new \RuntimeException('A sincronização está em espera após uma falha. Tente novamente em alguns minutos.'); }
            $api = new Api(); $scope = Core::scope();
            if ($s['phase'] === 'charges') {
                $rows = $api->page((int) $s['offset']);
                if (!$rows) {
                    $t = Store::table();
                    $ok = $wpdb->query($wpdb->prepare("UPDATE $t SET visible=0 WHERE scope=%s AND seen_run<>%s", $scope, $s['run']));
                    if ($ok === false) { throw new \RuntimeException('Falha ao reconciliar a varredura.'); }
                    $s['phase'] = 'customers'; $s['charges_at'] = current_time('mysql');
                }
                else {
                    $ids = array_column($rows, 'Id'); sort($ids); $hash = hash('sha256', wp_json_encode($ids));
                    if (in_array($hash, $s['seen_hashes'] ?? [], true)) { throw new \RuntimeException('O K8 repetiu uma página. A importação foi interrompida para evitar um ciclo infinito. Reinicie a sincronização.'); }
                    // Normalize the entire page before saving; malformed data never advances the cursor.
                    $normalized = array_map([Core::class, 'normalize'], $rows);
                    foreach ($normalized as $row) { $row['seen_run'] = $s['run']; $row['visible'] = 1; Store::save($row, $scope); }
                    $s['offset'] += count($rows); $s['processed'] += count($rows);
                    $s['seen_hashes'][] = $hash;
                    // Continue until a truly empty page, even when K8 clamps Size below 20.
                }
            } elseif ($s['phase'] === 'customers') {
                $c = Store::customers();
                $cut = wp_date('Y-m-d H:i:s', time() - 86400);
                $customers = $wpdb->get_results($wpdb->prepare("SELECT * FROM $c WHERE scope=%s AND (synced_at IS NULL OR synced_at<%s) AND (last_try IS NULL OR last_try<%s) ORDER BY id LIMIT 1", $scope, $cut, $cut), ARRAY_A);
                if (!$customers) { $s['phase'] = 'done'; $s['complete_at'] = current_time('mysql'); $s['next_at'] = time() + 900; unset($s['seen_hashes']); }
                foreach ($customers as $customer) {
                    try {
                        $data = $api->customer($customer['remote_id']);
                        if (empty($data['Id']) || (string) $data['Id'] !== $customer['remote_id']) { throw new \RuntimeException('Cliente não reconhecido.'); }
                        $ok = $wpdb->update($c, ['document' => preg_replace('/\D/', '', Core::text($data['CPFCNPJ'] ?? '')), 'synced_at' => current_time('mysql'), 'last_try' => current_time('mysql'), 'failed' => 0], ['id' => $customer['id']]);
                        if ($ok === false) { throw new \RuntimeException('Falha salvando cliente.'); }
                    } catch (\Throwable $e) {
                        $wpdb->update($c, ['last_try' => current_time('mysql'), 'failed' => 1], ['id' => $customer['id']]);
                        $s['customer_warning'] = 'Há clientes sem CPF/CNPJ atualizado. Nova tentativa automática após 24 horas.';
                    }
                }
            }
            $s['error'] = ''; $s['failures'] = 0; unset($s['retry_at']);
            $s['last_step'] = current_time('mysql'); update_option($key, $s, false);
            return self::status();
        } catch (\Throwable $e) {
            $s['error'] = $e->getMessage(); $s['failures'] = ($s['failures'] ?? 0) + 1;
            $s['retry_at'] = time() + min(3600, 60 * (2 ** min(6, $s['failures'])));
            update_option($key, $s, false); throw $e;
        } finally { self::unlock($name); }
    }
    public static function refresh(int $id): array {
        global $wpdb; $scope = Core::scope(); $t = Store::table(); $name = self::lock();
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE scope=%s AND id=%d", $scope, $id), ARRAY_A);
            if (!$row) { throw new \RuntimeException('Cobrança não encontrada nesta conta.'); }
            $data = (new Api())->charge($row['remote_id']);
            if (($data['Id'] ?? null) !== $row['remote_id']) { throw new \RuntimeException('O K8 retornou um identificador diferente do solicitado.'); }
            $normalized = Core::normalize($data); $normalized['visible'] = 1; Store::save($normalized, $scope);
            return $normalized;
        } finally { self::unlock($name); }
    }
    public static function cron(): void {
        if (empty(Core::settings()['automatic'])) { return; }
        try {
            $s = self::status();
            if (($s['retry_at'] ?? 0) > time() || (($s['phase'] ?? '') === 'done' && ($s['next_at'] ?? 0) > time())) { return; }
            self::step();
        } catch (\Throwable $e) { /* Safe message is already in sync status; never log API payloads. */ }
    }
}
