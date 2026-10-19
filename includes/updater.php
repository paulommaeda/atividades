<?php
/** GitHub updates through the native WordPress Update URI API. */
if (!defined('ABSPATH')) exit;

final class JAO_GitHub_Updater {
    const REPO = 'paulommaeda/atividades';
    const URL = 'https://github.com/paulommaeda/atividades';
    const CACHE = 'jao_github_update_v1';

    public static function boot() {
        add_filter('update_plugins_github.com', [__CLASS__, 'update'], 10, 4);
        add_filter('plugins_api', [__CLASS__, 'info'], 10, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'source'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear'], 10, 2);
        add_filter('plugin_action_links_'.plugin_basename(JAO_FILE), [__CLASS__, 'links']);
        add_action('admin_post_jao_check_updates', [__CLASS__, 'check']);
    }

    private static function request($url) {
        $r = wp_remote_get($url, ['timeout'=>12, 'redirection'=>0, 'sslverify'=>true,
            'limit_response_size'=>262144,
            'headers'=>['Accept'=>'application/vnd.github+json', 'User-Agent'=>'Journey-Atividades-Updater']]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) return false;
        return wp_remote_retrieve_body($r);
    }

    public static function metadata() {
        $cached = get_site_transient(self::CACHE);
        if ($cached !== false) return $cached ?: false;
        // Back off on network errors and API limits, without advertising a broken package.
        set_site_transient(self::CACHE, [], 5*MINUTE_IN_SECONDS);
        $body = self::request('https://api.github.com/repos/'.self::REPO.'/commits/main');
        $commit = $body ? json_decode($body, true) : null;
        $sha = is_array($commit) ? ($commit['sha'] ?? '') : '';
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{40}$/D', $sha)) return false;
        $header = self::request('https://raw.githubusercontent.com/'.self::REPO.'/'.$sha.'/journey-atividades.php');
        if (!$header) return false;
        $fields = [];
        foreach (['version'=>'Version', 'requires'=>'Requires at least', 'requires_php'=>'Requires PHP'] as $key=>$label) {
            if (!preg_match('/^[ \t]*\*[ \t]*'.preg_quote($label, '/').':[ \t]*([0-9]+(?:\.[0-9]+){1,3})[ \t]*\r?$/m', $header, $m)) return false;
            $fields[$key] = $m[1];
        }
        // Pin download to the same commit used to read the version (never a moving branch).
        $data = array_merge($fields, ['id'=>self::URL, 'slug'=>'journey-atividades',
            'url'=>self::URL, 'package'=>'https://api.github.com/repos/'.self::REPO.'/zipball/'.$sha]);
        set_site_transient(self::CACHE, $data, HOUR_IN_SECONDS);
        return $data;
    }

    public static function update($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== plugin_basename(JAO_FILE)) return $update;
        return self::metadata() ?: $update;
    }

    public static function info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'journey-atividades') return $result;
        $data = self::metadata();
        if (!$data) return $result;
        return (object) [
            'name'=>'Journey — Atividades Opcionais', 'slug'=>$data['slug'], 'version'=>$data['version'],
            'author'=>'Journey', 'homepage'=>self::URL, 'requires'=>$data['requires'],
            'requires_php'=>$data['requires_php'], 'download_link'=>$data['package'],
            'sections'=>['description'=>'Atividades por turma, espelho local TOTVS, validação WhatsApp opcional e pagamentos K8.',
                'changelog'=>'Versão '.$data['version'].'. Consulte o histórico em '.self::URL.'/commits/main.'],
        ];
    }

    public static function source($source, $remote_source, $upgrader, $extra) {
        if (is_wp_error($source) || ($extra['plugin'] ?? '') !== plugin_basename(JAO_FILE)) return $source;
        global $wp_filesystem;
        if (!$wp_filesystem || !$wp_filesystem->exists(trailingslashit($source).'journey-atividades.php')) {
            return new WP_Error('jao_invalid_package', 'O pacote não contém o plugin Journey Atividades.');
        }
        $folder = dirname(plugin_basename(JAO_FILE));
        if ($folder === '.') $folder = 'journey-atividades';
        $target = trailingslashit($remote_source).$folder.'/';
        if (untrailingslashit($source) === untrailingslashit($target)) return $source;
        if (!$wp_filesystem->move($source, $target)) {
            return new WP_Error('jao_update_folder', 'Não foi possível preparar a pasta da atualização.');
        }
        return $target;
    }

    public static function clear($upgrader, $extra) {
        if (($extra['type'] ?? '')==='plugin' &&
            (in_array(plugin_basename(JAO_FILE), (array)($extra['plugins'] ?? []), true) ||
             ($extra['plugin'] ?? '')===plugin_basename(JAO_FILE))) delete_site_transient(self::CACHE);
    }

    public static function links($links) {
        if (current_user_can('update_plugins')) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=jao_check_updates'), 'jao_check_updates');
            $links[] = '<a href="'.esc_url($url).'">Verificar atualizações</a>';
        }
        return $links;
    }

    public static function check() {
        if (!current_user_can('update_plugins')) wp_die('Acesso restrito.');
        check_admin_referer('jao_check_updates');
        delete_site_transient(self::CACHE);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        if (!self::metadata()) wp_die('Não foi possível consultar o GitHub. Aguarde alguns minutos e tente novamente.', 'Atualização indisponível', ['back_link'=>true]);
        wp_safe_redirect(network_admin_url('plugins.php'));
        exit;
    }
}
JAO_GitHub_Updater::boot();
