<?php
/**
 * Licença do ML Media Master.
 *
 * 2.10.0: a licença passa a ser emitida e conferida pelo site tools.mlopesdesign.com.br
 * (módulo ML License Hub, cliente ML_License_Client_V2). O servidor antigo
 * (license.mlopesdesign.com.br/api/license.php) não é mais consultado.
 *
 * Regras do padrão ML:
 * - teste grátis de 30 dias por site; licença anual vale em 1 site; o Pacote Agência
 *   vale em todos os plugins ML (5/10/50 sites);
 * - sem teste/licença válidos, só o painel do plugin fica travado para GRAVAR
 *   (salvar as configurações). A otimização continua funcionando no site: conversão
 *   para WebP no upload (WordPress e ML Gallery Pro), troca de URLs para .webp no
 *   front-end e o redirecionamento de uploads antigos. Nada é apagado.
 * - quem já usava o plugin recebe o teste grátis sozinho na primeira visita ao painel.
 *
 * A option antiga (ml_media_master_license_state) fica no banco (não é mais usada).
 *
 * @package ML_Media_Master
 */

if (!defined('ABSPATH')) {
        exit;
}

// O cliente de licença é carregado ANTES da guarda abaixo: o PHP declara a classe deste
// arquivo já na compilação, então class_exists() é sempre verdadeiro aqui e o return
// impediria o carregamento do cliente (erro fatal quando nenhum outro plugin ML o carregou).
require_once __DIR__ . '/class-ml-license-client.php';

if (class_exists('MLMM_License')) {
        return;
}


final class MLMM_License {
        const API_BASE     = 'https://tools.mlopesdesign.com.br/wp-json/mllh/v1';
        const PUBLIC_KEY   = 'VGZQp2X3RJMuDmQBlIEXS4fqsZD1CnFlIJgfVtY/pHo=';
        const PRODUCT_ID   = 'ml-media-master';
        const BUY_URL      = 'https://tools.mlopesdesign.com.br/precos/';
        const ACCOUNT_URL  = 'https://tools.mlopesdesign.com.br/minha-conta/';
        const AUTO_TRIAL   = 'mlmm_auto_trial_tried';
        const PAGE_SLUG    = 'ml-media-master';
        const SETTINGS_OPT = 'ml_media_master_settings';
        const SETTINGS_GRP = 'ml_media_master_group';
        const TOAST        = 'ml_media_master_admin_toast';

        /** @var ML_License_Client_V2|null */
        private static $client = null;

        /** @var string */
        private static $version = '';

        /** @var bool|null Resultado guardado durante a requisição (zera quando a licença muda). */
        private static $locked = null;

        public static function client(): ML_License_Client_V2 {
                if (null === self::$client) {
                        self::$client = new ML_License_Client_V2([
                                'api_base'   => self::API_BASE,
                                'product'    => self::PRODUCT_ID,
                                'public_key' => self::PUBLIC_KEY,
                                'version'    => self::$version,
                        ]);
                }
                return self::$client;
        }

        /** Teste ou licença válidos neste site. */
        public static function is_active(): bool {
                return self::client()->is_premium();
        }

        /** Painel travado para gravar (sem teste nem licença). */
        public static function is_locked(): bool {
                if (null === self::$locked) {
                        self::$locked = !self::is_active();
                }
                return self::$locked;
        }

        public static function forget(): void {
                self::$locked = null;
        }

        /* ------------------------------------------------------------------ ganchos */

        public static function boot(string $version): void {
                self::$version = $version;
                self::client(); // agenda a renovação diária

                add_action('admin_init', [__CLASS__, 'maybe_auto_trial'], 5);
                add_action('admin_init', [__CLASS__, 'guard_settings_post'], 1);
                add_filter('pre_update_option_' . self::SETTINGS_OPT, [__CLASS__, 'lock_settings_update'], 10, 2);

                add_action('admin_post_mlmm_activate_license', [__CLASS__, 'handle_activate']);
                add_action('admin_post_mlmm_sync_license', [__CLASS__, 'handle_sync']);
                add_action('admin_post_mlmm_remove_license', [__CLASS__, 'handle_remove']);

                $opt = self::client()->option_name();
                foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
                        add_action($hook, static function ($name) use ($opt) {
                                if ($name === $opt) {
                                        MLMM_License::forget();
                                }
                        });
                }
        }

        /** Primeira visita ao painel: começa o teste grátis sozinho (uma tentativa por site). */
        public static function maybe_auto_trial(): void {
                if (wp_doing_ajax() || !current_user_can('manage_options') || get_option(self::AUTO_TRIAL)) {
                        return;
                }
                update_option(self::AUTO_TRIAL, time(), false);
                $state = self::client()->state();
                if (empty($state['active']) && 'none' === ($state['mode'] ?? 'none') && empty($state['trial_used'])) {
                        self::client()->start_trial();
                }
        }

        /**
         * Sem licença: o formulário "Salvar configurações" (options.php) volta para o painel
         * com um aviso, sem gravar. Roda antes do options.php processar o envio.
         */
        public static function guard_settings_post(): void {
                global $pagenow;
                if ('options.php' !== $pagenow || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
                        return;
                }
                $page = isset($_POST['option_page']) ? sanitize_key(wp_unslash((string) $_POST['option_page'])) : '';
                if (self::SETTINGS_GRP !== $page || !self::is_locked()) {
                        return;
                }
                self::redirect_with_toast(false, self::locked_message(), 'configuracoes');
        }

        /**
         * Segunda barreira: sem licença, as configurações do ML Media Master não mudam
         * (mantém o valor salvo). A otimização segue com as configurações atuais.
         *
         * @param mixed $value
         * @param mixed $old
         * @return mixed
         */
        public static function lock_settings_update($value, $old) {
                return self::is_locked() ? $old : $value;
        }

        public static function locked_message(): string {
                return 'Ative a licença (ou comece o teste grátis) para alterar as configurações do ML Media Master. A otimização das imagens continua funcionando normalmente.';
        }

        /** Aviso discreto nas telas do plugin (fora da aba Licença). */
        public static function render_locked_notice(): void {
                if (!self::is_locked()) {
                        return;
                }
                echo '<div class="mlmm-lock-notice" role="status"><strong>Sem licença ativa:</strong> as configurações estão só para leitura. A otimização das imagens continua funcionando no site. <a href="#mlmm-tab-licenca" class="mlmm-tab-link" data-tab="licenca">Ativar licença</a></div>';
        }

        /* ------------------------------------------------------------------ tela */

        /** @return array<string, mixed> */
        public static function summary(): array {
                $state  = self::client()->state();
                $active = !empty($state['active']);
                $mode   = (string) ($state['mode'] ?? 'none');
                $plan   = strtolower((string) ($state['plan'] ?? ''));
                $days   = $state['days_left'] ?? null;

                $plans = [
                        'trial'    => 'Teste grátis',
                        'monthly'  => 'Mensal',
                        'annual'   => 'Anual',
                        'yearly'   => 'Anual',
                        'lifetime' => 'Vitalícia',
                        'manual'   => 'Cortesia',
                        'courtesy' => 'Cortesia',
                ];
                $plan_label = $plans[$plan] ?? ('' !== $plan ? ucfirst($plan) : '—');

                if ($active && 'trial' === $mode) {
                        $label   = 'Teste grátis';
                        $message = null === $days ? 'Teste grátis ativo neste site.' : sprintf(1 === (int) $days ? 'Teste grátis ativo: falta %d dia.' : 'Teste grátis ativo: faltam %d dias.', (int) $days);
                } elseif ($active) {
                        $label   = 'Licença ativa';
                        $message = 'Licença ativa neste site.';
                } else {
                        $label = 'Sem licença';
                        if ('trial_expired' === ($state['status'] ?? '')) {
                                $message = 'O teste grátis terminou. Ative uma licença para voltar a alterar as configurações. A otimização das imagens continua funcionando.';
                        } elseif ('' !== (string) ($state['message'] ?? '')) {
                                $message = (string) $state['message'];
                        } else {
                                $message = 'Comece o teste grátis de 30 dias ou ative a sua licença.';
                        }
                }

                $expires_label = '';
                if (!empty($state['expires_at'])) {
                        $ts = strtotime((string) $state['expires_at']);
                        if ($ts) {
                                $expires_label = wp_date((string) get_option('date_format', 'd/m/Y'), $ts);
                        }
                }

                return [
                        'active'          => $active,
                        'mode'            => $mode,
                        'is_trial'        => $active && 'trial' === $mode,
                        'is_lifetime'     => 'lifetime' === $plan,
                        'label'           => $label,
                        'message'         => $message,
                        'plan_label'      => $active ? $plan_label : '—',
                        'expires_label'   => $expires_label,
                        'days_left'       => $days,
                        'key_masked'      => (string) ($state['key_masked'] ?? ''),
                        'can_start_trial' => !$active && empty($state['trial_used']) && 'license' !== $mode,
                        'domain'          => (string) wp_parse_url(home_url(), PHP_URL_HOST),
                ];
        }

        /* ------------------------------------------------------------------ ações */

        public static function handle_activate(): void {
                self::guard_request('mlmm_activate_license');
                $key    = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash((string) $_POST['license_key'])) : '';
                $result = self::client()->activate($key);
                if (is_wp_error($result)) {
                        self::redirect_with_toast(false, $result->get_error_message());
                }
                self::redirect_with_toast(true, 'Licença ativada neste site.');
        }

        /** Começa o teste grátis ou confere a licença agora. */
        public static function handle_sync(): void {
                self::guard_request('mlmm_sync_license');
                $op     = isset($_POST['mlmm_op']) ? sanitize_key(wp_unslash((string) $_POST['mlmm_op'])) : 'check';
                $result = 'trial' === $op ? self::client()->start_trial() : self::client()->refresh();
                if (is_wp_error($result)) {
                        self::redirect_with_toast(false, $result->get_error_message());
                }
                self::redirect_with_toast(true, 'trial' === $op ? 'Teste grátis de 30 dias ativo neste site.' : 'Licença conferida.');
        }

        public static function handle_remove(): void {
                self::guard_request('mlmm_remove_license');
                self::client()->deactivate();
                self::redirect_with_toast(true, 'Licença removida deste site. A chave pode ser usada em outro site.');
        }

        private static function guard_request(string $nonce_action): void {
                if (!current_user_can('manage_options')) {
                        wp_die(esc_html('Permissão insuficiente.'));
                }
                check_admin_referer($nonce_action);
        }

        public static function redirect_with_toast(bool $success, string $message, string $tab = 'licenca'): void {
                set_transient(self::TOAST, [
                        'message' => $message,
                        'type'    => $success ? 'success' : 'error',
                        'timeout' => $success ? 6000 : 9000,
                ], 120);
                wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG) . '#mlmm-tab-' . rawurlencode($tab));
                exit;
        }
}
