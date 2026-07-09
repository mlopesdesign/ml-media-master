<?php
/**
 * Plugin Name: ML Media Master
 * Plugin URI: https://mlopesdesign.com.br
 * Description: ML Media Master para WordPress: converte JPG, JPEG e PNG para WebP automaticamente no upload (WordPress e ML Gallery Pro). GIF nunca convertido. Licença Free/Full por serial.
 * Version: 2.9.59.0
 * Update URI: https://github.com/mlopesdesign/ml-media-master
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Marcio Lopes
 * Author URI: https://mlopesdesign.com.br
 * License: GPL2+
 * Text Domain: ml-media-master
 */

if (!defined('ABSPATH')) {
        exit;
}

require_once __DIR__ . '/includes/class-mlmm-gallery-pro.php';
require_once __DIR__ . '/includes/class-mlmm-updater.php';

final class ML_Media_Master {
        private const OPTION_KEY = 'ml_media_master_settings';
        private const VERSION = '2.9.59.0';
        private const LICENSE_OPTION = 'ml_media_master_license_state';
        private const LICENSE_SERVER_URL = 'https://license.mlopesdesign.com.br/api/license.php';
        private const PRODUCT_ID = 'ml-media-master';
        private const LICENSE_CACHE_TTL = 21600;
        private const ADMIN_TOAST_TRANSIENT = 'ml_media_master_admin_toast';
        private const VERSION_SEEN_OPTION = 'ml_media_master_version_seen';


        /**
         * @var array<string, mixed>
         */
        private $settings = [];

        /**
         * @var array<string, bool>
         */
        private $binary_cache = [];

        /**
         * @var array<string, string>|null
         */
        private $uploads_base_cache = null;
        private $gallery_pro_compat = null;

        private function get_current_language(): string {
                $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
                $locale = strtolower($locale);

                if (strpos($locale, 'pt') === 0) {
                        return 'pt';
                }

                if (strpos($locale, 'es') === 0) {
                        return 'es';
                }

                return 'en';
        }

        private function t(string $pt, string $en = '', string $es = ''): string {
                $lang = $this->get_current_language();

                if ($lang === 'pt') {
                        return $pt;
                }

                if ($lang === 'es') {
                        return $es !== '' ? $es : ($en !== '' ? $en : $pt);
                }

                return $en !== '' ? $en : $pt;
        }

        private function get_logo_url(): string {
                return esc_url(plugin_dir_url(__FILE__) . 'assets/images/logo-wordpress.png');
        }

        /**
         * @return array<string, string>
         */
        private function get_admin_js_strings(): array {
                return [
                        'saved' => $this->t('Configurações salvas.', 'Settings saved.', 'Configuración guardada.'),
                        'whatsNewTitle' => $this->t('Novidades da versão', "What's new", 'Novedades de la versión'),
                ];
        }
        public function __construct() {
                $this->settings = $this->get_settings();
                $this->gallery_pro_compat = class_exists('MLMM_Gallery_Pro_Compat') ? new MLMM_Gallery_Pro_Compat($this) : null;

                add_action('admin_menu', [$this, 'register_admin_menu']);
                add_action('admin_init', [$this, 'register_settings']);
                add_action('admin_init', [$this, 'maybe_handle_license_actions']);
                add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
                add_action('template_redirect', [$this, 'maybe_redirect_missing_upload_to_webp'], 1);

                add_filter('wp_handle_upload', [$this, 'process_upload'], 20, 2);
                add_filter('wp_generate_attachment_metadata', [$this, 'fix_metadata_after_webp'], 20, 2);

                add_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_sizes'], 999, 3);
                add_filter('big_image_size_threshold', [$this, 'filter_big_image_threshold'], 999, 1);

                add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url_to_webp'], 20, 2);
                add_filter('wp_get_attachment_image_src', [$this, 'filter_attachment_image_src_to_webp'], 20, 4);
                add_filter('wp_calculate_image_srcset', [$this, 'filter_attachment_srcset_to_webp'], 20, 5);

                add_filter('the_content', [$this, 'filter_html_content_urls_to_webp'], 20, 1);
                add_filter('post_thumbnail_html', [$this, 'filter_html_content_urls_to_webp'], 20, 1);
                add_filter('widget_text', [$this, 'filter_html_content_urls_to_webp'], 20, 1);
                add_filter('widget_block_content', [$this, 'filter_html_content_urls_to_webp'], 20, 1);
                add_filter('render_block', [$this, 'filter_html_content_urls_to_webp'], 20, 1);

                // ML Gallery Pro integration: convert local uploads to WebP immediately
                // after MLGP persists new items to disk and to the database.
                // Priority 20 runs after MLGP has finished all its own post-store work.
                add_action('mlgp_after_items_stored', [$this, 'handle_mlgp_items_stored'], 20, 2);

                // GitHub Releases auto-updater (native WordPress update flow).
                if (class_exists('MLMM_Updater')) {
                        (new MLMM_Updater(__FILE__, self::VERSION))->init();
                }
        }

        /**
         * @return array<string, mixed>
         */
        private function get_default_settings(): array {
                return [
                        'enabled'                     => 1,
                        'max_long_edge'               => 1280,
                        'target_min_kb'               => 150,
                        'target_max_kb'               => 200,
                        'webp_min_quality'            => 45,
                        'webp_max_quality'            => 90,
                        'delete_original'             => 1,
                        'disable_generated_sizes'     => 0,
                        'disable_wp_big_image_scale'  => 1,
                        'serve_existing_webp_uploads' => 1,
                        'allowed_mimes'               => ['image/jpeg', 'image/jpg', 'image/png'],
                ];
        }

        /**
         * @return array<string, mixed>
         */
        private function get_settings(): array {
                $saved = get_option(self::OPTION_KEY, []);
                if (!is_array($saved)) {
                        $saved = [];
                }

                return array_merge($this->get_default_settings(), $saved);
        }

        public function register_admin_menu(): void {
                add_menu_page(
                        $this->t('ML Media Master', 'ML Media Master', 'ML Media Master'),
                        $this->t('ML Media Master', 'ML Media Master', 'ML Media Master'),
                        'manage_options',
                        'ml-media-master',
                        [$this, 'render_settings_page'],
                        'dashicons-format-image',
                        58
                );
        }

        public function enqueue_admin_assets(string $hook): void {
                if ($hook !== 'toplevel_page_ml-media-master') {
                        return;
                }

                $handle_css = 'ml-media-master-admin';
                $handle_js = 'ml-media-master-admin';

                wp_enqueue_style(
                        $handle_css,
                        plugin_dir_url(__FILE__) . 'assets/admin.css',
                        [],
                        self::VERSION
                );

                wp_enqueue_script(
                        $handle_js,
                        plugin_dir_url(__FILE__) . 'assets/admin.js',
                        ['jquery'],
                        self::VERSION,
                        true
                );

                wp_localize_script(
                        $handle_js,
                        'MLMM_ADMIN',
                        [
                                'strings' => $this->get_admin_js_strings(),
                        ]
                );
        }

        public function register_settings(): void {
                register_setting(
                        'ml_media_master_group',
                        self::OPTION_KEY,
                        [$this, 'sanitize_settings']
                );

                add_settings_section(
                        'ml_media_master_main',
                        $this->t('Configurações principais', 'Main settings', 'Configuración principal'),
                        [$this, 'render_main_section'],
                        'ml-media-master'
                );

                add_settings_field(
                        'enabled',
                        $this->t('Ativar otimização', 'Enable optimization', 'Activar optimización'),
                        [$this, 'render_checkbox_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'   => 'enabled',
                                'label' => $this->t('Ativar o processamento automático no upload.', 'Enable automatic processing on upload.', 'Activar el procesamiento automático en la carga.'),
                        ]
                );

                add_settings_field(
                        'max_long_edge',
                        $this->t('Aresta longa máxima (px)', 'Maximum long edge (px)', 'Lado largo máximo (px)'),
                        [$this, 'render_number_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'         => 'max_long_edge',
                                'min'         => 200,
                                'max'         => 5000,
                                'step'        => 1,
                                'description' => $this->t('Ex.: 1280, 1600, 1920.', 'Ex.: 1280, 1600, 1920.', 'Ej.: 1280, 1600, 1920.'),
                        ]
                );

                add_settings_field(
                        'target_min_kb',
                        $this->t('Tamanho mínimo alvo (KB)', 'Target minimum size (KB)', 'Tamaño mínimo objetivo (KB)'),
                        [$this, 'render_number_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'         => 'target_min_kb',
                                'min'         => 10,
                                'max'         => 5000,
                                'step'        => 1,
                                'description' => $this->t('Faixa inferior desejada do WebP final.', 'Desired lower range for the final WebP.', 'Rango inferior deseado para el WebP final.'),
                        ]
                );

                add_settings_field(
                        'target_max_kb',
                        $this->t('Tamanho máximo alvo (KB)', 'Target maximum size (KB)', 'Tamaño máximo objetivo (KB)'),
                        [$this, 'render_number_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'         => 'target_max_kb',
                                'min'         => 10,
                                'max'         => 5000,
                                'step'        => 1,
                                'description' => $this->t('Faixa superior desejada do WebP final.', 'Desired upper range for the final WebP.', 'Rango superior deseado para el WebP final.'),
                        ]
                );

                add_settings_field(
                        'webp_min_quality',
                        $this->t('Qualidade mínima WebP', 'Minimum WebP quality', 'Calidad mínima WebP'),
                        [$this, 'render_number_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'         => 'webp_min_quality',
                                'min'         => 1,
                                'max'         => 100,
                                'step'        => 1,
                                'description' => $this->t('Qualidade mínima permitida na busca do arquivo alvo.', 'Minimum quality allowed when searching for the target file.', 'Calidad mínima permitida al buscar el archivo objetivo.'),
                        ]
                );

                add_settings_field(
                        'webp_max_quality',
                        $this->t('Qualidade máxima WebP', 'Maximum WebP quality', 'Calidad máxima WebP'),
                        [$this, 'render_number_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'         => 'webp_max_quality',
                                'min'         => 1,
                                'max'         => 100,
                                'step'        => 1,
                                'description' => $this->t('Qualidade máxima permitida na busca do arquivo alvo.', 'Maximum quality allowed when searching for the target file.', 'Calidad máxima permitida al buscar el archivo objetivo.'),
                        ]
                );

                add_settings_field(
                        'delete_original',
                        $this->t('Remover original', 'Remove original', 'Eliminar original'),
                        [$this, 'render_checkbox_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'   => 'delete_original',
                                'label' => $this->t('Apagar o arquivo original após gerar o WebP com sucesso.', 'Delete the original file after generating WebP successfully.', 'Eliminar el archivo original después de generar el WebP correctamente.'),
                        ]
                );

                add_settings_field(
                        'disable_generated_sizes',
                        $this->t('Desativar subtamanhos do WordPress', 'Disable WordPress sub-sizes', 'Desactivar sub-tamaños de WordPress'),
                        [$this, 'render_checkbox_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'   => 'disable_generated_sizes',
                                'label' => $this->t('Gerar apenas o arquivo principal, sem tamanhos intermediários.', 'Generate only the main file, without intermediate sizes.', 'Generar solo el archivo principal, sin tamaños intermedios.'),
                        ]
                );

                add_settings_field(
                        'disable_wp_big_image_scale',
                        $this->t('Desativar redimensionamento grande do core', 'Disable core large image scaling', 'Desactivar el redimensionado grande del core'),
                        [$this, 'render_checkbox_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'   => 'disable_wp_big_image_scale',
                                'label' => $this->t('Desabilitar o big_image_size_threshold do WordPress.', 'Disable WordPress big_image_size_threshold.', 'Desactivar el big_image_size_threshold de WordPress.'),
                        ]
                );

                add_settings_field(
                        'serve_existing_webp_uploads',
                        $this->t('Servir WebP dos uploads antigos', 'Serve WebP for legacy uploads', 'Servir WebP de cargas antiguas'),
                        [$this, 'render_checkbox_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'   => 'serve_existing_webp_uploads',
                                'label' => $this->t('Quando existir .webp correspondente em uploads, servir .webp no frontend do WordPress.', 'When a matching .webp exists in uploads, serve .webp on the WordPress frontend.', 'Cuando exista un .webp correspondiente en uploads, servir .webp en el frontend de WordPress.'),
                        ]
                );

        }

        /**
         * @param mixed $input
         * @return array<string, mixed>
         */
        public function sanitize_settings($input): array {
                $defaults = $this->get_default_settings();
                $current  = $this->get_settings();

                $input = is_array($input) ? $input : [];

                $output = [];

                $output['enabled']                     = !empty($input['enabled']) ? 1 : 0;
                $output['delete_original']             = !empty($input['delete_original']) ? 1 : 0;
                $output['disable_generated_sizes']     = !empty($input['disable_generated_sizes']) ? 1 : 0;
                $output['disable_wp_big_image_scale']  = !empty($input['disable_wp_big_image_scale']) ? 1 : 0;
                $output['serve_existing_webp_uploads'] = !empty($input['serve_existing_webp_uploads']) ? 1 : 0;

                $output['max_long_edge'] = isset($input['max_long_edge'])
                        ? max(200, min(5000, (int) $input['max_long_edge']))
                        : (int) $current['max_long_edge'];

                $output['target_min_kb'] = isset($input['target_min_kb'])
                        ? max(10, min(5000, (int) $input['target_min_kb']))
                        : (int) $current['target_min_kb'];

                $output['target_max_kb'] = isset($input['target_max_kb'])
                        ? max(10, min(5000, (int) $input['target_max_kb']))
                        : (int) $current['target_max_kb'];

                $output['webp_min_quality'] = isset($input['webp_min_quality'])
                        ? max(1, min(100, (int) $input['webp_min_quality']))
                        : (int) $current['webp_min_quality'];

                $output['webp_max_quality'] = isset($input['webp_max_quality'])
                        ? max(1, min(100, (int) $input['webp_max_quality']))
                        : (int) $current['webp_max_quality'];

                if ($output['target_min_kb'] > $output['target_max_kb']) {
                        $temp = $output['target_min_kb'];
                        $output['target_min_kb'] = $output['target_max_kb'];
                        $output['target_max_kb'] = $temp;
                }

                if ($output['webp_min_quality'] > $output['webp_max_quality']) {
                        $temp = $output['webp_min_quality'];
                        $output['webp_min_quality'] = $output['webp_max_quality'];
                        $output['webp_max_quality'] = $temp;
                }

                $output['allowed_mimes'] = $defaults['allowed_mimes'];

                $this->settings = array_merge($defaults, $output);

                set_transient(self::ADMIN_TOAST_TRANSIENT, [
                        'message' => $this->t('Configurações salvas com sucesso.', 'Settings saved successfully.', 'Configuración guardada correctamente.'),
                        'type'    => 'success',
                        'timeout' => 5000,
                ], 60);

                return $this->settings;
        }

        public function render_main_section(): void {
                echo '<p>' . esc_html($this->t('Defina o comportamento do processamento automático de imagens no upload padrão do WordPress, nos uploads antigos e no HTML renderizado.', 'Set the behavior for automatic image processing on standard WordPress uploads, legacy uploads and rendered HTML.', 'Define el comportamiento del procesamiento automático de imágenes en la carga estándar de WordPress, las cargas antiguas y el HTML renderizado.')) . '</p>';
        }

        /**
         * @param array<string, mixed> $args
         */
        public function render_checkbox_field(array $args): void {
                $key      = (string) $args['key'];
                $label    = isset($args['label']) ? (string) $args['label'] : '';
                $note     = isset($args['note']) ? (string) $args['note'] : '';
                $disabled = !empty($args['disabled']);
                $value    = !empty($this->settings[$key]) ? 1 : 0;

                echo '<label>';
                echo '<input type="checkbox" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="1" ' . checked(1, $value, false) . ($disabled ? ' disabled="disabled" aria-disabled="true"' : '') . ' />';
                echo ' ' . esc_html($label);
                echo '</label>';
                if ($disabled) {
                        echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="0" />';
                }
                if ($note !== '') {
                        echo '<p class="description">' . esc_html($note) . '</p>';
                }
        }

        /**
         * @param array<string, mixed> $args
         */
        public function render_number_field(array $args): void {
                $key         = (string) $args['key'];
                $min         = isset($args['min']) ? (int) $args['min'] : 0;
                $max         = isset($args['max']) ? (int) $args['max'] : 999999;
                $step        = isset($args['step']) ? (int) $args['step'] : 1;
                $description = isset($args['description']) ? (string) $args['description'] : '';
                $value       = isset($this->settings[$key]) ? (int) $this->settings[$key] : '';

                echo '<input type="number" class="regular-text" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" step="' . esc_attr((string) $step) . '" />';
                if ($description !== '') {
                        $desc = $description;
                        echo '<p class="description">' . esc_html($desc) . '</p>';
                }
        }


        /**
         * @return array<string, mixed>
         */
        private function get_default_license_state(): array {
                return [
                        'mode' => 'free',
                        'valid' => false,
                        'status' => 'free',
                        'message' => 'Versão Free ativa. Informe um serial para liberar todos os recursos.',
                        'license_key' => '',
                        'plan' => 'free',
                        'domain' => '',
                        'site_url' => site_url(),
                        'checked_at' => '',
                        'expires_at' => '',
                        'grace_until' => '',
                        'days_left' => '',
                        'license_source' => 'free',
                        'site_fingerprint' => '',
                        'premium' => 0,
                        'trial_started' => 0,
                ];
        }

        /**
         * @return array<string, mixed>
         */
        private function get_license_state(): array {
                $saved = get_option(self::LICENSE_OPTION, []);
                if (!is_array($saved)) {
                        $saved = [];
                }

                return array_merge($this->get_default_license_state(), $saved);
        }

        /**
         * @param array<string, mixed> $state
         */
        private function save_license_state(array $state): void {
                $state = array_merge($this->get_default_license_state(), $state);
                update_option(self::LICENSE_OPTION, $state, false);
        }

        private function has_premium_access(): bool {
                $state = $this->get_license_state();
                $status = strtolower((string) ($state['status'] ?? ''));
                $plan = strtolower((string) ($state['plan'] ?? ''));
                $premium_flag = !empty($state['premium']);
                $valid = !empty($state['valid']);
                $license_source = strtolower((string) ($state['license_source'] ?? ''));
                $trial_started = !empty($state['trial_started']);
                $expires_at = trim((string) ($state['expires_at'] ?? ''));

                if ($status === 'trial_active') {
                        return true;
                }

                if ($trial_started && in_array($license_source, ['server_trial', 'trial', 'license_hub_trial'], true)) {
                        if ($expires_at === '') {
                                return true;
                        }

                        $expires_ts = strtotime($expires_at);
                        if ($expires_ts !== false && $expires_ts >= current_time('timestamp')) {
                                return true;
                        }
                }

                if (in_array($status, ['active', 'lifetime'], true) && $valid) {
                        return true;
                }

                if ($premium_flag && in_array($status, ['active', 'trial_active', 'lifetime'], true)) {
                        return true;
                }

                return $valid && in_array($plan, ['full', 'premium', 'lifetime', 'trial'], true) && !in_array($status, ['expired', 'trial_expired', 'blocked', 'invalid', 'free'], true);
        }

        private function is_full_license_active(): bool {
                return $this->has_premium_access();
        }

        private function get_site_fingerprint(): string {
                return hash('sha256', strtolower($this->get_current_domain_for_license()) . '|' . site_url() . '|' . home_url() . '|' . ABSPATH);
        }

        private function get_license_api_action(string $license_key): string {
                return trim($license_key) !== '' ? 'validate_license' : 'start_trial';
        }

        private function get_plan_label(string $plan): string {
                $plan = strtolower($plan);
                $map = [
                        'free' => 'Free',
                        'trial' => 'Trial',
                        'full' => 'Full',
                        'premium' => 'Premium',
                        'lifetime' => 'Vitalício',
                ];

                return $map[$plan] ?? ucfirst($plan);
        }

        private function get_status_label(string $status): string {
                $status = strtolower($status);
                $map = [
                        'free' => 'Free ativo',
                        'trial_active' => 'Trial ativo',
                        'trial_expired' => 'Trial expirado',
                        'active' => 'Licença ativa',
                        'lifetime' => 'Vitalícia ativa',
                        'expired' => 'Licença expirada',
                        'blocked' => 'Bloqueada',
                        'domain_mismatch' => 'Domínio divergente',
                        'inactive' => 'Inativa',
                        'bad_request' => 'Dados incompletos',
                        'error' => 'Erro de conexão',
                        'invalid' => 'Inválida',
                        'not_found' => 'Não encontrada',
                        'deactivated' => 'Desativada',
                ];

                return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
        }

        private function get_current_domain_for_license(): string {
                $host = wp_parse_url(site_url(), PHP_URL_HOST);
                return strtolower((string) $host);
        }

        /**
         * @return array<string, mixed>
         */
        private function validate_license_remote(string $license_key): array {
                $license_key = trim($license_key);
                if ($license_key === '') {
                        return [
                                'valid' => false,
                                'status' => 'missing_key',
                                'message' => 'Informe um serial para validar a licença.',
                        ];
                }

                $response = wp_remote_post(
                        self::LICENSE_SERVER_URL,
                        [
                                'timeout' => 20,
                                'redirection' => 3,
                                'headers' => [
                                        'Accept' => 'application/json',
                                ],
                                'body' => [
                                        'action' => $this->get_license_api_action($license_key),
                                        'product_id' => self::PRODUCT_ID,
                                        'license_key' => $license_key,
                                        'domain' => $this->get_current_domain_for_license(),
                                        'site_url' => site_url(),
                                        'home_url' => home_url(),
                                        'version' => self::VERSION,
                                        'admin_email' => get_option('admin_email', ''),
                                        'site_fingerprint' => $this->get_site_fingerprint(),
                                ],
                        ]
                );

                if (is_wp_error($response)) {
                        return [
                                'valid' => false,
                                'status' => 'request_failed',
                                'message' => 'Falha ao conectar ao servidor de licenças: ' . $response->get_error_message(),
                        ];
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                $body = (string) wp_remote_retrieve_body($response);
                $body = preg_replace('/^ï»¿/', '', $body);
                $body = trim((string) $body);
                $data = json_decode($body, true);

                if (!is_array($data) && $body !== '') {
                        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $body, $matches)) {
                                $decoded_fragment = json_decode((string) $matches[0], true);
                                if (is_array($decoded_fragment)) {
                                        $data = $decoded_fragment;
                                }
                        }
                }

                if (!is_array($data) && $body !== '') {
                        $json_start = strpos($body, '{');
                        $json_end = strrpos($body, '}');
                        if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
                                $json_fragment = substr($body, $json_start, ($json_end - $json_start + 1));
                                $decoded_fragment = json_decode($json_fragment, true);
                                if (is_array($decoded_fragment)) {
                                        $data = $decoded_fragment;
                                }
                        }
                }

                if (!is_array($data)) {
                        $body_preview = trim(wp_strip_all_tags($body));
                        if ($body_preview !== '') {
                                $body_preview = function_exists('mb_substr') ? mb_substr($body_preview, 0, 220) : substr($body_preview, 0, 220);
                        }

                        return [
                                'valid' => false,
                                'status' => 'invalid_response',
                                'message' => 'Resposta inválida do servidor de licenças. HTTP ' . $code . ($body_preview !== '' ? ' | Retorno: ' . $body_preview : ' | Retorno vazio.'),
                                'http_code' => $code,
                                'raw_body' => $body,
                        ];
                }

                if (isset($data['success']) && !isset($data['valid']) && !isset($data['status'])) {
                        $success = !empty($data['success']);
                        $data = [
                                'valid' => $success,
                                'status' => $success ? 'active' : 'invalid',
                                'message' => isset($data['message']) ? (string) $data['message'] : ($success ? 'Licença validada com sucesso.' : 'Licença inválida.'),
                                'plan' => isset($data['plan']) ? (string) $data['plan'] : ($success ? 'full' : 'free'),
                                'domain' => isset($data['domain']) ? (string) $data['domain'] : $this->get_current_domain_for_license(),
                        ];
                }

                $data['http_code'] = $code;
                return $data;
        }

        public function maybe_handle_license_actions(): void {
                if (!is_admin() || !current_user_can('manage_options')) {
                        return;
                }

                if (!isset($_GET['page']) || $_GET['page'] !== 'ml-media-master') {
                        return;
                }

                $action = isset($_POST['mlmm_license_action']) ? sanitize_text_field((string) $_POST['mlmm_license_action']) : '';
                if ($action === '') {
                        return;
                }

                check_admin_referer('mlmm_license_action_nonce');

                if ($action === 'deactivate_license') {
                        $this->save_license_state($this->get_default_license_state());
                        $this->set_admin_toast(true, $this->t('Licença removida deste site. A versão Free continua ativa.', 'License removed from this site. The Free version remains active.', 'Licencia eliminada de este sitio. La versión Free sigue activa.'));
                        $this->redirect_to_plugin_page('licenca');
                        return;
                }

                if ($action === 'validate_license' || $action === 'validate' || $action === 'sync_license') {
                        $saved_state = $this->get_license_state();
                        $license_key = isset($_POST['mlmm_license_key']) ? sanitize_text_field((string) $_POST['mlmm_license_key']) : (string) ($saved_state['license_key'] ?? '');

                        if ($action === 'sync_license' && trim($license_key) === '') {
                                $now_ts = current_time('timestamp');
                                $expires_ts = strtotime('+30 days', $now_ts);
                                $state = [
                                        'mode' => 'full',
                                        'valid' => true,
                                        'status' => 'trial_active',
                                        'message' => 'Trial ativo neste site.',
                                        'license_key' => '',
                                        'plan' => 'trial',
                                        'domain' => $this->get_current_domain_for_license(),
                                        'site_url' => site_url(),
                                        'checked_at' => current_time('mysql'),
                                        'expires_at' => date_i18n('Y-m-d H:i:s', $expires_ts),
                                        'grace_until' => '',
                                        'days_left' => '30',
                                        'license_source' => 'server_trial',
                                        'site_fingerprint' => $this->get_site_fingerprint(),
                                        'premium' => 1,
                                        'trial_started' => 1,
                                ];
                                $this->save_license_state($state);
                                $this->set_admin_toast(true, $state['message']);
                                $this->redirect_to_plugin_page('licenca');
                        }

                        $result = $this->validate_license_remote($license_key);
                        $status = isset($result['status']) ? sanitize_key((string) $result['status']) : (!empty($result['valid']) ? 'active' : 'invalid');
                        $plan = isset($result['plan']) && $result['plan'] !== '' ? sanitize_text_field((string) $result['plan']) : (!empty($result['valid']) ? ($status === 'trial_active' ? 'trial' : 'full') : 'free');
                        $premium = !empty($result['premium']) || $status === 'trial_active' || in_array($plan, ['full', 'premium', 'lifetime', 'trial'], true);
                        $state = [
                                'mode' => $premium ? 'full' : 'free',
                                'valid' => !empty($result['valid']) || $status === 'trial_active',
                                'status' => $status,
                                'message' => isset($result['message']) ? (string) $result['message'] : '',
                                'license_key' => $license_key,
                                'plan' => $plan,
                                'domain' => isset($result['domain']) ? (string) $result['domain'] : $this->get_current_domain_for_license(),
                                'site_url' => site_url(),
                                'checked_at' => current_time('mysql'),
                                'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : '',
                                'grace_until' => isset($result['grace_until']) ? (string) $result['grace_until'] : '',
                                'days_left' => isset($result['days_left']) ? (string) $result['days_left'] : '',
                                'license_source' => isset($result['license_source']) ? (string) $result['license_source'] : ($license_key !== '' ? 'license' : 'server_trial'),
                                'site_fingerprint' => $this->get_site_fingerprint(),
                                'premium' => $premium ? 1 : 0,
                                'trial_started' => $status === 'trial_active' ? 1 : (!empty($saved_state['trial_started']) ? 1 : 0),
                        ];
                        $this->save_license_state($state);
                        $ok = !empty($state['valid']) || in_array($status, ['free', 'trial_expired'], true);
                        $this->set_admin_toast($ok, $state['message'] !== '' ? $state['message'] : 'Sincronização da licença concluída.');
                        $this->redirect_to_plugin_page('licenca');
                }
        }
        private function get_feature_matrix(): array {
                return [
                        ['label' => 'Conversão automática para WebP no upload', 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => 'Integração ML Gallery Pro', 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => 'Fallback de URL antiga para WebP', 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => $this->t('Suporte prioritário', 'Priority support', 'Soporte prioritario'), 'free' => $this->t('Não', 'No', 'No'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                ];
        }

        private function render_license_panel(): void {
                $state = $this->get_license_state();
                $premium = $this->has_premium_access();
                $plan_label = $this->get_plan_label((string) ($state['plan'] ?? 'free'));
                $status_label = $this->get_status_label((string) ($state['status'] ?? 'free'));
                $days_left = isset($state['days_left']) && $state['days_left'] !== '' ? (int) $state['days_left'] : null;
                $license_source = !empty($state['license_source']) ? (string) $state['license_source'] : 'free';
                $fingerprint = !empty($state['site_fingerprint']) ? (string) $state['site_fingerprint'] : $this->get_site_fingerprint();

                echo '<section class="mlmm-tab-panel" data-tab-panel="licenca">';
                echo '<div class="mlmm-grid mlmm-grid-4" style="margin-bottom:16px;">';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Plano atual</span><strong>' . esc_html($plan_label) . '</strong><small>' . esc_html($premium ? 'Todos os recursos liberados' : 'Modo Free operacional por padrão') . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Status</span><strong>' . esc_html($status_label) . '</strong><small>' . esc_html((string) ($state['status'] ?? 'free')) . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Premium</span><strong>' . esc_html($premium ? 'Liberado' : 'Limitado') . '</strong><small>' . esc_html($premium ? 'Trial/Full/Vitalício com recursos completos' : 'Upgrade libera recursos completos e suporte prioritário') . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Dias restantes</span><strong>' . esc_html($days_left !== null ? (string) $days_left : '—') . '</strong><small>' . esc_html(!empty($state['expires_at']) ? (string) $state['expires_at'] : 'Sem expiração informada') . '</small></div>';
                echo '</div>';

                echo '<div class="mlmm-grid mlmm-grid-2">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Licença</h2><p class="mlmm-muted">Ative uma licença Full/Vitalícia ou sincronize o site com o Hub para receber Trial de 30 dias e depois degradar com elegância para Free.</p></div><div class="mlmm-chip">' . esc_html($status_label) . '</div></div>';
                echo '<div class="mlmm-grid mlmm-grid-2">';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Produto</strong><div>ML Media Master</div></div>';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Product ID</strong><div>' . esc_html(self::PRODUCT_ID) . '</div></div>';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Domínio atual</strong><div>' . esc_html($this->get_current_domain_for_license()) . '</div></div>';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Site fingerprint</strong><div style="word-break:break-all;">' . esc_html($fingerprint) . '</div></div>';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Origem</strong><div>' . esc_html($license_source) . '</div></div>';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Última validação</strong><div>' . esc_html((string) (($state['checked_at'] ?? '') ?: '—')) . '</div></div>';
                echo '<div class="mlmm-card mlmm-card-inline"><strong>Expira em</strong><div>' . esc_html((string) (($state['expires_at'] ?? '') ?: '—')) . '</div></div>';
                echo '</div>';
                echo '<form method="post" style="margin-top:14px;">';
                wp_nonce_field('mlmm_license_action_nonce');
                echo '<input type="hidden" name="mlmm_license_action" value="validate_license">';
                echo '<p><label for="mlmm-license-key"><strong>Serial / License Key</strong></label><br><input id="mlmm-license-key" type="text" name="mlmm_license_key" value="' . esc_attr((string) ($state['license_key'] ?? '')) . '" class="regular-text" style="width:100%;max-width:420px" placeholder="MLI-XXXXX-XXXXX-XXXXX-XXXXX"></p>';
                echo '<p style="display:flex;gap:10px;flex-wrap:wrap;">';
                echo '<button type="submit" class="button button-primary">Ativar licença</button>';
                echo '</p>';
                echo '</form>';
                echo '<form method="post" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">';
                wp_nonce_field('mlmm_license_action_nonce');
                echo '<input type="hidden" name="mlmm_license_action" value="sync_license">';
                echo '<input type="hidden" name="mlmm_license_key" value="' . esc_attr((string) ($state['license_key'] ?? '')) . '">';
                echo '<button type="submit" class="button">Iniciar / sincronizar Trial</button>';
                if (!empty($state['license_key']) || !empty($state['trial_started'])) {
                        echo '<button type="submit" name="mlmm_license_action" value="deactivate_license" class="button">Remover licença deste site</button>';
                }
                echo '</form>';
                echo '</div>';

                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Planos</h2><p class="mlmm-muted">Comparativo comercial direto para upgrade do cliente final, sem alterar a configuração salva do site.</p></div><div class="mlmm-chip">Free / Trial / Full / Vitalício</div></div>';
                echo '<table class="widefat striped"><thead><tr><th>Recurso</th><th>Free</th><th>Trial</th><th>Full / Vitalício</th></tr></thead><tbody>';
                foreach ($this->get_feature_matrix() as $row) {
                        $trial_value = (string) ($row['full'] ?? 'Sim');
                        echo '<tr><td>' . esc_html((string) $row['label']) . '</td><td>' . esc_html((string) $row['free']) . '</td><td>' . esc_html($trial_value) . '</td><td>' . esc_html((string) $row['full']) . '</td></tr>';
                }
                echo '</tbody></table>';
                echo '<p style="margin-top:12px;">A tabela acima é comercial. Ela não altera a configuração salva do site e não reescreve seu lote atual.</p>';
                echo '<p style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">';
                echo '<a class="button button-primary" href="https://mlopesdesign.com.br" target="_blank" rel="noopener noreferrer">Assinar / Comprar agora</a>';
                echo '<a class="button" href="https://mlopesdesign.com.br" target="_blank" rel="noopener noreferrer">Abrir site oficial</a>';
                echo '</p>';
                echo '</div>';
                echo '</div>';
                echo '</section>';
        }


        /**
         * @return array{message:string,type:string,timeout:int}|null
         */
        private function consume_admin_toast(): ?array {
                $toast = get_transient(self::ADMIN_TOAST_TRANSIENT);
                if (!is_array($toast) || empty($toast['message'])) {
                        return null;
                }

                delete_transient(self::ADMIN_TOAST_TRANSIENT);

                return [
                        'message' => (string) $toast['message'],
                        'type'    => !empty($toast['type']) ? (string) $toast['type'] : 'success',
                        'timeout' => !empty($toast['timeout']) ? (int) $toast['timeout'] : 5000,
                ];
        }

        /**
         * @return array{message:string,type:string,timeout:int}|null
         */
        private function consume_version_toast(): ?array {
                $seen = (string) get_option(self::VERSION_SEEN_OPTION, '');
                if ($seen === self::VERSION) {
                        return null;
                }

                update_option(self::VERSION_SEEN_OPTION, self::VERSION, false);

                return [
                        'message' => $this->t(
                                'ML Media Master ' . self::VERSION . ' instalado. Atualização automática corrigida: o botão Verificar novamente agora consulta a release atual do GitHub sem ficar preso ao cache anterior.',
                                'ML Media Master ' . self::VERSION . ' installed. Automatic updates fixed: Check again now queries the current GitHub release instead of remaining stuck on stale cache.',
                                'ML Media Master ' . self::VERSION . ' instalado. Actualización automática corregida: Verificar de nuevo ahora consulta la release actual de GitHub sin quedar atrapado en la caché anterior.'
                        ),
                        'type'    => 'success',
                        'timeout' => 10000,
                ];
        }
        public function render_settings_page(): void {
                if (!current_user_can('manage_options')) {
                        return;
                }

                $this->settings = $this->get_settings();
                $deferred_toast = $this->consume_admin_toast();
                $version_toast = $this->consume_version_toast();

                echo '<div class="wrap mlmm-admin-wrap">';
                echo '<div id="mlmm-toast-area" aria-live="polite" aria-atomic="true"></div>';

                if (is_array($deferred_toast) && !empty($deferred_toast['message'])) {
                        echo '<div id="mlmm-deferred-toast" data-message="' . esc_attr($deferred_toast['message']) . '" data-type="' . esc_attr($deferred_toast['type']) . '" data-timeout="' . esc_attr((string) $deferred_toast['timeout']) . '"></div>';
                }
                if (is_array($version_toast) && !empty($version_toast['message'])) {
                        echo '<div id="mlmm-version-toast" data-message="' . esc_attr($version_toast['message']) . '" data-type="' . esc_attr($version_toast['type']) . '" data-timeout="' . esc_attr((string) $version_toast['timeout']) . '"></div>';
                }

                echo '<div class="mlmm-hero">';
                echo '<div class="mlmm-hero-brand">';
                echo '<div class="mlmm-hero-mark"><img src="' . $this->get_logo_url() . '" alt="ML Lopes Design"></div>';
                echo '<div class="mlmm-hero-copy">';
                echo '<span class="mlmm-hero-eyebrow">ML Media Master</span>';
                echo '<h1>ML Media Master</h1>';
                echo '<p>' . esc_html($this->t('Conversão automática de JPG, JPEG e PNG para WebP no upload do WordPress e do ML Gallery Pro. GIF nunca é convertido.', 'Automatic JPG, JPEG and PNG to WebP conversion on WordPress and ML Gallery Pro upload. GIF is never converted.', 'Conversión automática de JPG, JPEG y PNG a WebP en la carga de WordPress y ML Gallery Pro. GIF nunca se convierte.')) . '</p>';
                echo '</div>';
                echo '</div>';
                echo '<div class="mlmm-hero-meta"><span class="mlmm-version-badge">v' . esc_html(self::VERSION) . '</span></div>';
                echo '</div>';

                echo '<div class="mlmm-tab-nav" role="tablist" aria-label="Seções do ML Media Master">';
                echo '<button type="button" class="mlmm-tab-button is-active" data-tab="dashboard" role="tab" aria-selected="true">Dashboard</button>';
                echo '<button type="button" class="mlmm-tab-button' . (!$this->is_mlgp_available() ? ' is-disabled' : '') . '" data-tab="mlgallery" role="tab" aria-selected="false"' . (!$this->is_mlgp_available() ? ' disabled="disabled" aria-disabled="true"' : '') . '>ML Gallery Pro</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="configuracoes" role="tab" aria-selected="false">Configurações</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="licenca" role="tab" aria-selected="false">Licença e Planos</button>';
                echo '</div>';

                echo '<div class="mlmm-tab-panels">';

                // ---- Dashboard ----
                echo '<section class="mlmm-tab-panel is-active" data-tab-panel="dashboard">';
                echo '<div class="mlmm-summary-grid">';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Conversão no upload</span><strong>' . esc_html(!empty($this->settings['enabled']) ? $this->t('Ativa', 'Active', 'Activa') : $this->t('Desativada', 'Disabled', 'Desactivada')) . '</strong><small>' . esc_html($this->t('JPG/JPEG/PNG para WebP automaticamente', 'JPG/JPEG/PNG to WebP automatically', 'JPG/JPEG/PNG a WebP automáticamente')) . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Ambiente pronto</span><strong>' . esc_html($this->has_any_conversion_engine() ? $this->t('Sim', 'Yes', 'Sí') : 'Revisar') . '</strong><small>' . esc_html($this->has_any_conversion_engine() ? 'Motor ' . $this->get_conversion_engine_label() . ' disponível' : 'Nenhum motor disponível') . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Remoção segura</span><strong>' . (!empty($this->settings['delete_original']) ? 'Ativa' : 'Desativada') . '</strong><small>Originais só saem após validação do WebP</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Suporte legado</span><strong>' . (!empty($this->settings['serve_existing_webp_uploads']) ? 'Ativo' : 'Desativado') . '</strong><small>Uploads antigos e URLs diretas</small></div>';
                if ($this->is_mlgp_available()) {
                        $mlgp_summary = $this->gallery_pro_compat->get_dashboard_summary();
                        echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">ML Gallery Pro</span><strong>' . esc_html((string) ($mlgp_summary['local_items'] ?? 0)) . '</strong><small>' . esc_html((string) ($mlgp_summary['message'] ?? 'Compatibilidade carregada')) . '</small></div>';
                }
                echo '</div>';

                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Ambiente</h2><p class="mlmm-muted">Diagnóstico das dependências do servidor para conversão WebP.</p></div></div>';
                echo '<div id="mlmm-environment-table">';
                $this->render_environment_table();
                echo '</div>';
                echo '</div>';
                echo '</section>';

                // ---- ML Gallery Pro ----
                echo '<section class="mlmm-tab-panel" data-tab-panel="mlgallery">';
                $this->render_mlgp_tab();
                echo '</section>';

                // ---- Configurações ----
                echo '<section class="mlmm-tab-panel" data-tab-panel="configuracoes">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Configurações</h2><p class="mlmm-muted">Parâmetros do motor de conversão, comportamento do upload e integração com frontend.</p></div></div>';
                echo '<form method="post" action="options.php">';
                settings_fields('ml_media_master_group');
                do_settings_sections('ml-media-master');
                submit_button($this->t('Salvar configurações', 'Save settings', 'Guardar configuración'));
                echo '</form>';
                echo '</div>';
                echo '</section>';

                // ---- Licença ----
                $this->render_license_panel();

                echo '</div>';
                echo '</div>';
        }

        private function render_environment_table(): void {
                echo '<table class="widefat striped"><tbody>';
                echo '<tr><td><strong>Motor ativo</strong></td><td>' . esc_html($this->get_conversion_engine_label()) . '</td></tr>';
                echo '<tr><td><strong>exec()</strong></td><td>' . esc_html($this->is_exec_available() ? $this->t('Disponível', 'Available', 'Disponible') : $this->t('Indisponível', 'Unavailable', 'No disponible')) . '</td></tr>';
                echo '<tr><td><strong>convert</strong></td><td>' . esc_html($this->binary_exists('convert') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>identify</strong></td><td>' . esc_html($this->binary_exists('identify') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>cwebp</strong></td><td>' . esc_html($this->binary_exists('cwebp') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>Imagick PHP</strong></td><td>' . esc_html($this->has_imagick_engine() ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>GD + WebP</strong></td><td>' . esc_html($this->has_gd_webp_engine() ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>jpegoptim</strong></td><td>' . esc_html($this->binary_exists('jpegoptim') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>jpegtran</strong></td><td>' . esc_html($this->binary_exists('jpegtran') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>pngquant</strong></td><td>' . esc_html($this->binary_exists('pngquant') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>optipng</strong></td><td>' . esc_html($this->binary_exists('optipng') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>Diretório temporário</strong></td><td>' . esc_html(is_writable(get_temp_dir()) ? $this->t('Gravável', 'Writable', 'Grabable') : $this->t('Não gravável', 'Not writable', 'No grabable')) . '</td></tr>';
                echo '</tbody></table>';
        }

        private function redirect_to_plugin_page(string $tab = ''): void {
                $url = admin_url('admin.php?page=ml-media-master');
                if ($tab !== '') {
                        $url .= '#mlmm-tab-' . rawurlencode($tab);
                }
                wp_safe_redirect($url);
                exit;
        }

        private function set_admin_toast(bool $success, string $message): void {
                // Deferred toast shown inside render_settings_page — never in the WP admin header.
                set_transient(
                        self::ADMIN_TOAST_TRANSIENT,
                        [
                                'message' => $message,
                                'type'    => $success ? 'success' : 'error',
                                'timeout' => $success ? 6000 : 9000,
                        ],
                        120
                );
        }

        private function is_mlgp_available(): bool {
                return $this->gallery_pro_compat instanceof MLMM_Gallery_Pro_Compat && $this->gallery_pro_compat->is_available();
        }

        /**
         * @param mixed $threshold
         * @return mixed
         */
        public function filter_big_image_threshold($threshold) {
                if (!empty($this->settings['disable_wp_big_image_scale'])) {
                        return false;
                }

                return $threshold;
        }

        /**
         * @param array<string, array<string, mixed>> $sizes
         * @param array<string, mixed> $metadata
         * @param int $attachment_id
         * @return array<string, array<string, mixed>>
         */
        public function filter_intermediate_sizes(array $sizes, array $metadata, int $attachment_id): array {
                unset($metadata, $attachment_id);

                if (!empty($this->settings['disable_generated_sizes'])) {
                        return [];
                }

                return $sizes;
        }

        /**
         * @param string $url
         * @param int    $post_id
         * @return string
         */
        public function filter_attachment_url_to_webp(string $url, int $post_id): string {
                unset($post_id);

                $this->settings = $this->get_settings();

                if (empty($this->settings['serve_existing_webp_uploads'])) {
                        return $url;
                }

                return $this->maybe_swap_upload_url_to_webp($url);
        }

        /**
         * @param array<int, mixed>|false $image
         * @param int                     $attachment_id
         * @param mixed                   $size
         * @param bool                    $icon
         * @return array<int, mixed>|false
         */
        public function filter_attachment_image_src_to_webp($image, int $attachment_id, $size, bool $icon) {
                unset($attachment_id, $size, $icon);

                $this->settings = $this->get_settings();

                if (empty($this->settings['serve_existing_webp_uploads'])) {
                        return $image;
                }

                if (!is_array($image) || empty($image[0]) || !is_string($image[0])) {
                        return $image;
                }

                $image[0] = $this->maybe_swap_upload_url_to_webp($image[0]);

                return $image;
        }

        /**
         * @param array<string, array<string, mixed>>|false $sources
         * @param array<int, int>                           $size_array
         * @param string                                    $image_src
         * @param array<string, mixed>                      $image_meta
         * @param int                                       $attachment_id
         * @return array<string, array<string, mixed>>|false
         */
        public function filter_attachment_srcset_to_webp($sources, array $size_array, string $image_src, array $image_meta, int $attachment_id) {
                unset($size_array, $image_src, $image_meta, $attachment_id);

                $this->settings = $this->get_settings();

                if (empty($this->settings['serve_existing_webp_uploads'])) {
                        return $sources;
                }

                if (!is_array($sources)) {
                        return $sources;
                }

                foreach ($sources as $width => $source) {
                        if (!is_array($source) || empty($source['url']) || !is_string($source['url'])) {
                                continue;
                        }

                        $sources[$width]['url'] = $this->maybe_swap_upload_url_to_webp($source['url']);
                }

                return $sources;
        }


        /**
         * @param mixed $content
         * @return mixed
         */
        public function filter_html_content_urls_to_webp($content) {
                $this->settings = $this->get_settings();

                if (empty($this->settings['serve_existing_webp_uploads'])) {
                        return $content;
                }

                if (!is_string($content) || $content === '') {
                        return $content;
                }

                $uploads = wp_get_upload_dir();
                if (empty($uploads['baseurl'])) {
                        return $content;
                }

                $baseurl = preg_quote((string) $uploads['baseurl'], '/');

                $content = (string) preg_replace_callback(
                        '/' . $baseurl . '[^"\')\s>]+\.(?:jpe?g|png)/i',
                        function (array $matches): string {
                                $url = isset($matches[0]) ? (string) $matches[0] : '';

                                return $this->maybe_swap_upload_url_to_webp($url);
                        },
                        $content
                );

                return $content;
        }


        public function maybe_redirect_missing_upload_to_webp(): void {
                if (is_admin() || wp_doing_ajax()) {
                        return;
                }

                if (!is_404()) {
                        return;
                }

                $this->settings = $this->get_settings();
                if (empty($this->settings['serve_existing_webp_uploads'])) {
                        return;
                }

                $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
                if ($request_uri === '') {
                        return;
                }

                $path = wp_parse_url($request_uri, PHP_URL_PATH);
                if (!is_string($path) || $path === '' || !preg_match('/\.(jpe?g|png)$/i', $path)) {
                        return;
                }

                $uploads = wp_get_upload_dir();
                $baseurl_path = isset($uploads['baseurl']) ? wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH) : '';
                $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
                if (!is_string($baseurl_path) || $baseurl_path === '' || $basedir === '') {
                        return;
                }

                $baseurl_path = '/' . trim($baseurl_path, '/');
                if (strpos($path, $baseurl_path . '/') !== 0) {
                        return;
                }

                $relative = substr($path, strlen($baseurl_path));
                if (!is_string($relative) || $relative === '') {
                        return;
                }

                $absolute_path = rtrim($basedir, '/') . '/' . ltrim($relative, '/');
                $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $absolute_path);
                if (!is_string($webp_path) || $webp_path === '') {
                        return;
                }

                if (!$this->ensure_webp_exists_from_original($absolute_path, $webp_path)) {
                        return;
                }

                $redirect_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
                if (!is_string($redirect_path) || $redirect_path === '') {
                        return;
                }

                $query = wp_parse_url($request_uri, PHP_URL_QUERY);
                $redirect_url = home_url($redirect_path);
                if (is_string($query) && $query !== '') {
                        $redirect_url .= '?' . $query;
                }

                wp_safe_redirect($redirect_url, 302, 'ML Media Master');
                exit;
        }

        private function maybe_swap_upload_url_to_webp(string $url): string {
                if ($url === '' || !preg_match('/\.(jpe?g|png)$/i', $url)) {
                        return $url;
                }

                $uploads = wp_get_upload_dir();
                if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
                        return $url;
                }

                $baseurl = (string) $uploads['baseurl'];
                $basedir = (string) $uploads['basedir'];

                if (strpos($url, $baseurl) !== 0) {
                        return $url;
                }

                $relative = substr($url, strlen($baseurl));
                if (!is_string($relative) || $relative === '') {
                        return $url;
                }

                $relative_path = wp_parse_url($relative, PHP_URL_PATH);
                if (!is_string($relative_path) || $relative_path === '') {
                        return $url;
                }

                $relative_path = '/' . ltrim($relative_path, '/');
                $absolute_path = rtrim($basedir, '/') . $relative_path;

                $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $absolute_path);
                $webp_url  = preg_replace('/\.(jpe?g|png)$/i', '.webp', $url);

                if (!$webp_path || !$webp_url) {
                        return $url;
                }

                if ($this->ensure_webp_exists_from_original($absolute_path, $webp_path)) {
                        return $webp_url;
                }

                return $url;
        }
        public function handle_mlgp_items_stored(array $items, int $gallery_id): void {
                $this->settings = $this->get_settings();

                if (empty($this->settings['enabled'])) {
                        return;
                }

                if (!$this->has_any_conversion_engine()) {
                        return;
                }

                $allowed_mimes = isset($this->settings['allowed_mimes']) && is_array($this->settings['allowed_mimes'])
                        ? array_values(array_filter(array_map('strval', $this->settings['allowed_mimes'])))
                        : [];

                if (empty($allowed_mimes)) {
                        $allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png'];
                }

                foreach ($items as $item) {
                        if (!is_array($item)) {
                                continue;
                        }

                        $mime = isset($item['mime_type']) ? strtolower((string) $item['mime_type']) : '';
                        $updates = [];

                        // Variants use convert_file_to_webp_copy (wp_get_image_editor, no resize)
                        // so their exact pixel dimensions are preserved.
                        //
                        // The main file uses convert_existing_file_to_webp_targeted which applies
                        // the plugin's quality/resize pipeline and may delete the original when
                        // delete_original=ON.
                        $variant_defs = [
                                ['file_path',   true],
                                ['thumb_path',  false],
                                ['medium_path', false],
                                ['large_path',  false],
                        ];

                        foreach ($variant_defs as [$path_key, $is_main]) {
                                $path = isset($item[$path_key]) ? (string) $item[$path_key] : '';
                                if ($path === '' || !file_exists($path) || !is_readable($path)) {
                                        continue;
                                }

                                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

                                if ($ext === 'webp' || $ext === 'gif') {
                                        continue;
                                }

                                $variant_mime = $mime !== '' ? $mime : $this->guess_mime_from_extension($path);
                                if ($variant_mime === 'image/gif' || $this->is_gif_path($path)) {
                                        continue;
                                }

                                if ($variant_mime !== '' && !in_array($variant_mime, $allowed_mimes, true)) {
                                        continue;
                                }

                                $webp_path = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
                                if ($webp_path === '' || $webp_path === $path) {
                                        continue;
                                }

                                if ($is_main) {
                                        $conversion = $this->convert_existing_file_to_webp_targeted($path, $webp_path, true);
                                        if ((!empty($conversion['success']) || !empty($conversion['already_existed'])) && $this->is_valid_generated_file($webp_path)) {
                                                $updates[$path_key] = $webp_path;
                                        }
                                } else {
                                        if (!$this->is_valid_generated_file($webp_path)) {
                                                $this->convert_file_to_webp_copy($path, $webp_path, $this->get_conversion_quality());
                                        }

                                        if ($this->is_valid_generated_file($webp_path)) {
                                                $updates[$path_key] = $webp_path;
                                        }
                                }
                        }

                        if (!empty($updates)) {
                                $this->sync_mlgp_item_record_after_upload($gallery_id, $item, $updates);
                        }
                }
        }

        /**
         * After MLGP upload-time conversion, immediately relink the stored database row
         * from JPG/JPEG/PNG paths to the new WebP paths so delete_original=ON does not
         * leave the gallery pointing at removed originals.
         *
         * @param array<string,mixed>  $item
         * @param array<string,string> $converted_paths
         */
        private function sync_mlgp_item_record_after_upload(int $gallery_id, array $item, array $converted_paths): void {
                global $wpdb;

                if (!isset($wpdb) || !is_object($wpdb)) {
                        return;
                }

                $table = $wpdb->prefix . 'mlgp_gallery_items';
                $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ($table_exists !== $table) {
                        return;
                }

                $original_main_path = isset($item['file_path']) ? (string) $item['file_path'] : '';
                if ($gallery_id <= 0 || $original_main_path === '') {
                        return;
                }

                $row = $wpdb->get_row(
                        $wpdb->prepare(
                                "SELECT * FROM {$table} WHERE gallery_id = %d AND file_path = %s ORDER BY id DESC LIMIT 1",
                                $gallery_id,
                                $original_main_path
                        ),
                        ARRAY_A
                );

                if ((!is_array($row) || empty($row['id'])) && $original_main_path !== '') {
                        $original_file_name = wp_basename($original_main_path);
                        if ($original_file_name !== '') {
                                $row = $wpdb->get_row(
                                        $wpdb->prepare(
                                                "SELECT * FROM {$table} WHERE gallery_id = %d AND file_name = %s ORDER BY id DESC LIMIT 1",
                                                $gallery_id,
                                                $original_file_name
                                        ),
                                        ARRAY_A
                                );
                        }
                }

                if (!is_array($row) || empty($row['id'])) {
                        return;
                }

                $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
                if (!is_array($columns) || empty($columns)) {
                        return;
                }
                $column_map = array_fill_keys(array_map('strval', $columns), true);

                $url_map = [
                        'file_path'   => 'file_url',
                        'thumb_path'  => 'thumb_url',
                        'medium_path' => 'medium_url',
                        'large_path'  => 'large_url',
                ];

                $update = [];
                $formats = [];

                foreach ($converted_paths as $path_key => $new_path) {
                        if (!isset($column_map[$path_key]) || $new_path === '' || !$this->is_valid_generated_file($new_path)) {
                                continue;
                        }

                        $update[$path_key] = $new_path;
                        $formats[] = '%s';

                        $url_key = $url_map[$path_key] ?? '';
                        if ($url_key !== '' && isset($column_map[$url_key])) {
                                $old_url = isset($row[$url_key]) ? (string) $row[$url_key] : '';
                                if ($old_url !== '') {
                                        $new_url = (string) preg_replace('/\.(jpe?g|png)(?=([?#].*)?$)/i', '.webp', $old_url);
                                        if ($new_url !== '') {
                                                $update[$url_key] = $new_url;
                                                $formats[] = '%s';
                                        }
                                }
                        }

                        if ($path_key === 'file_path') {
                                if (isset($column_map['file_name'])) {
                                        $update['file_name'] = wp_basename($new_path);
                                        $formats[] = '%s';
                                }
                                if (isset($column_map['mime_type'])) {
                                        $update['mime_type'] = 'image/webp';
                                        $formats[] = '%s';
                                }
                                $size = @getimagesize($new_path);
                                if (is_array($size)) {
                                        if (isset($column_map['width'])) {
                                                $update['width'] = (int) ($size[0] ?? 0);
                                                $formats[] = '%d';
                                        }
                                        if (isset($column_map['height'])) {
                                                $update['height'] = (int) ($size[1] ?? 0);
                                                $formats[] = '%d';
                                        }
                                }
                                if (isset($column_map['file_size'])) {
                                        $update['file_size'] = (int) @filesize($new_path);
                                        $formats[] = '%d';
                                }
                        }
                }

                if (empty($update)) {
                        return;
                }

                $updated = $wpdb->update(
                        $table,
                        $update,
                        ['id' => (int) $row['id']],
                        $formats,
                        ['%d']
                );

                if ($updated === false) {
                        return;
                }

                // Special upload-optimizer build: after the MLGP row points to WebP,
                // remove the previous JPG/JPEG/PNG variants. Never remove GIF/WebP.
                foreach ($converted_paths as $path_key => $new_path) {
                        $old_path = isset($row[$path_key]) ? (string) $row[$path_key] : '';
                        if ($old_path === '' || $new_path === '' || $old_path === $new_path) {
                                continue;
                        }
                        $old_ext = strtolower((string) pathinfo($old_path, PATHINFO_EXTENSION));
                        if (!in_array($old_ext, ['jpg', 'jpeg', 'png'], true)) {
                                continue;
                        }
                        if ($this->is_valid_generated_file($new_path) && file_exists($old_path) && is_writable($old_path)) {
                                @unlink($old_path);
                        }
                }
        }

        private function is_gif_path(string $path): bool {
                return $path !== '' && (bool) preg_match('/\.gif$/i', $path);
        }

        /**
         * @param array<string, mixed> $upload
         * @param string               $context
         * @return array<string, mixed>
         */
        public function process_upload(array $upload, string $context): array {
                unset($context);

                // v2.9.58.0: never replace/delete the uploaded file inside wp_handle_upload.
                // WordPress must receive the original JPG/PNG intact so it can finish
                // metadata generation without triggering "server cannot process image" errors.
                // Conversion now happens in fix_metadata_after_webp(), after core has accepted
                // the upload and generated attachment metadata.
                return $upload;
        }

        /**
         * @param array<string, mixed> $metadata
         * @return array<string, mixed>
         */
        public function fix_metadata_after_webp(array $metadata, int $attachment_id): array {
                $this->settings = $this->get_settings();

                if (empty($this->settings['enabled'])) {
                        return $metadata;
                }

                if (!$this->has_any_conversion_engine()) {
                        return $metadata;
                }

                $file = get_attached_file($attachment_id);
                if (!$file || !is_string($file) || !file_exists($file) || !is_readable($file)) {
                        return $metadata;
                }

                $mime = strtolower((string) get_post_mime_type($attachment_id));
                if ($mime === 'image/webp' || $mime === 'image/gif' || $this->is_gif_path($file)) {
                        if ($mime === 'image/webp') {
                                $size = @getimagesize($file);
                                if ($size) {
                                        $metadata['width']  = (int) $size[0];
                                        $metadata['height'] = (int) $size[1];
                                        $metadata['file']   = _wp_relative_upload_path($file);
                                }
                        }
                        return $metadata;
                }

                $allowed_mimes = isset($this->settings['allowed_mimes']) && is_array($this->settings['allowed_mimes'])
                        ? $this->settings['allowed_mimes']
                        : $this->get_default_settings()['allowed_mimes'];

                if (!in_array($mime, $allowed_mimes, true)) {
                        return $metadata;
                }

                $original_file = (string) $file;
                $webp_file = preg_replace('/\.(jpe?g|png)$/i', '.webp', $original_file);
                if (!$webp_file || $webp_file === $original_file) {
                        return $metadata;
                }

                $converted_main = $this->convert_existing_file_to_webp_targeted($original_file, $webp_file, false);
                if (empty($converted_main['success']) || !$this->is_valid_generated_file($webp_file)) {
                        return $metadata;
                }

                $upload_dir = wp_get_upload_dir();
                $base_url = isset($upload_dir['baseurl']) ? (string) $upload_dir['baseurl'] : '';

                update_attached_file($attachment_id, $webp_file);
                wp_update_post([
                        'ID' => $attachment_id,
                        'post_mime_type' => 'image/webp',
                        'guid' => $base_url !== '' ? trailingslashit($base_url) . ltrim(_wp_relative_upload_path($webp_file), '/') : get_the_guid($attachment_id),
                ]);

                $size = @getimagesize($webp_file);
                if ($size) {
                        $metadata['width']  = (int) $size[0];
                        $metadata['height'] = (int) $size[1];
                }
                $metadata['file'] = _wp_relative_upload_path($webp_file);
                $metadata['filesize'] = (int) @filesize($webp_file);

                $originals_to_delete = [$original_file];
                $original_dir = dirname($original_file);

                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                        foreach ($metadata['sizes'] as $size_key => $size_data) {
                                if (!is_array($size_data) || empty($size_data['file']) || !is_string($size_data['file'])) {
                                        continue;
                                }

                                $sub_file_name = (string) $size_data['file'];
                                if (!preg_match('/\.(jpe?g|png)$/i', $sub_file_name)) {
                                        continue;
                                }

                                $sub_original = trailingslashit($original_dir) . $sub_file_name;
                                if (!file_exists($sub_original) || !is_readable($sub_original)) {
                                        continue;
                                }

                                $sub_webp_name = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $sub_file_name);
                                $sub_webp = trailingslashit($original_dir) . $sub_webp_name;
                                if ($sub_webp === $sub_original) {
                                        continue;
                                }

                                $sub_result = $this->convert_file_to_webp_copy($sub_original, $sub_webp);
                                if (empty($sub_result['success']) || !$this->is_valid_generated_file($sub_webp)) {
                                        continue;
                                }

                                $metadata['sizes'][$size_key]['file'] = $sub_webp_name;
                                $metadata['sizes'][$size_key]['mime-type'] = 'image/webp';
                                $metadata['sizes'][$size_key]['filesize'] = (int) @filesize($sub_webp);
                                $originals_to_delete[] = $sub_original;
                        }
                }

                if (!empty($this->settings['delete_original'])) {
                        foreach (array_unique($originals_to_delete) as $old_file) {
                                if (is_string($old_file) && $old_file !== '' && $old_file !== $webp_file && file_exists($old_file)) {
                                        @unlink($old_file);
                                }
                        }
                }

                return $metadata;
        }

        private function get_shell_resize_binary(): string {
                if (!$this->is_exec_available()) {
                        return '';
                }

                if ($this->binary_exists('magick')) {
                        return 'magick';
                }

                if ($this->binary_exists('convert')) {
                        return 'convert';
                }

                return '';
        }

        private function has_shell_engine(): bool {
                return $this->get_shell_resize_binary() !== '' && $this->binary_exists('cwebp');
        }

        private function has_imagick_engine(): bool {
                return extension_loaded('imagick') && class_exists('Imagick');
        }

        private function has_imagick_webp_engine(): bool {
                static $supported = null;

                if ($supported !== null) {
                        return $supported;
                }

                if (!$this->has_imagick_engine()) {
                        $supported = false;
                        return false;
                }

                try {
                        $probe = new Imagick();
                        $formats = method_exists($probe, 'queryFormats') ? $probe->queryFormats('WEBP') : [];
                        $supported = is_array($formats) && !empty($formats);
                        $probe->clear();
                        $probe->destroy();
                        return $supported;
                } catch (Throwable $e) {
                        $supported = false;
                        return false;
                }
        }

        private function has_gd_webp_engine(): bool {
                return function_exists('imagewebp')
                        && function_exists('imagecreatetruecolor')
                        && function_exists('imagecopyresampled')
                        && function_exists('imagecreatefromjpeg')
                        && function_exists('imagecreatefrompng');
        }

        private function has_php_fallback_engine(): bool {
                return $this->has_imagick_webp_engine() || $this->has_gd_webp_engine();
        }

        private function has_any_conversion_engine(): bool {
                return $this->has_shell_engine() || $this->has_php_fallback_engine();
        }

        private function get_conversion_engine_label(): string {
                if ($this->has_shell_engine()) {
                        return 'shell';
                }

                if ($this->has_imagick_webp_engine()) {
                        return 'imagick';
                }

                if ($this->has_gd_webp_engine()) {
                        return 'gd';
                }

                return 'none';
        }

        private function gd_create_image_from_file(string $input, string $mime) {
                switch ($mime) {
                        case 'image/jpeg':
                                return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($input) : false;

                        case 'image/png':
                                return function_exists('imagecreatefrompng') ? @imagecreatefrompng($input) : false;
                }

                return false;
        }

        private function resize_with_gd(string $input, string $output, int $max_long_edge): bool {
                if (!$this->has_gd_webp_engine()) {
                        return false;
                }

                $mime = $this->guess_mime_from_extension($input);
                if ($mime === '') {
                        $info = @getimagesize($input);
                        if (is_array($info) && isset($info['mime'])) {
                                $mime = (string) $info['mime'];
                        }
                }

                if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                        return false;
                }

                $source = $this->gd_create_image_from_file($input, $mime);
                if (!$source) {
                        return false;
                }

                $width  = (int) imagesx($source);
                $height = (int) imagesy($source);
                if ($width < 1 || $height < 1) {
                        imagedestroy($source);
                        return false;
                }

                $scale = 1.0;
                $long_edge = max($width, $height);
                if ($long_edge > $max_long_edge) {
                        $scale = $max_long_edge / $long_edge;
                }

                $new_width  = max(1, (int) round($width * $scale));
                $new_height = max(1, (int) round($height * $scale));
                $target = imagecreatetruecolor($new_width, $new_height);
                if (!$target) {
                        imagedestroy($source);
                        return false;
                }

                if ($mime === 'image/png') {
                        imagealphablending($target, false);
                        imagesavealpha($target, true);
                        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                        imagefilledrectangle($target, 0, 0, $new_width, $new_height, $transparent);
                }

                $ok = imagecopyresampled($target, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                if (!$ok) {
                        imagedestroy($target);
                        imagedestroy($source);
                        return false;
                }

                $saved = false;
                if ($mime === 'image/jpeg' && function_exists('imagejpeg')) {
                        $saved = @imagejpeg($target, $output, 90);
                } elseif ($mime === 'image/png' && function_exists('imagepng')) {
                        $saved = @imagepng($target, $output, 6);
                }

                imagedestroy($target);
                imagedestroy($source);

                return $saved && file_exists($output) && filesize($output) > 0;
        }

        private function resize_with_imagick(string $input, string $output, int $max_long_edge): bool {
                if (!$this->has_imagick_engine()) {
                        return false;
                }

                try {
                        $image = new Imagick($input);
                        if (method_exists($image, 'autoOrient')) {
                                $image->autoOrient();
                        }
                        $image->setIteratorIndex(0);
                        $width     = (int) $image->getImageWidth();
                        $height    = (int) $image->getImageHeight();
                        $long_edge = max($width, $height);
                        if ($long_edge > $max_long_edge) {
                                // Calculate proportional target dimensions — NEVER use a square
                                // canvas. thumbnailImage(w, h, true, true) fills a w×h canvas
                                // with white padding, distorting non-square images.
                                $scale      = $max_long_edge / $long_edge;
                                $new_width  = max(1, (int) round($width  * $scale));
                                $new_height = max(1, (int) round($height * $scale));
                                // resizeImage preserves exact aspect ratio, no padding
                                $image->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1);
                        }
                        $format = strtolower((string) $image->getImageFormat());
                        if ($format === 'jpeg' || $format === 'jpg') {
                                $image->setImageFormat('jpeg');
                                $image->setImageCompressionQuality(90);
                        } else {
                                $image->setImageFormat('png');
                        }
                        $saved = $image->writeImage($output);
                        $image->clear();
                        $image->destroy();
                        return $saved && file_exists($output) && filesize($output) > 0;
                } catch (Throwable $e) {
                        return false;
                }
        }

        private function convert_to_webp_via_php(string $input, string $output, int $quality): bool {
                if ($this->has_imagick_webp_engine()) {
                        try {
                                $image = new Imagick($input);
                                $image->setIteratorIndex(0);
                                $image->setImageFormat('WEBP');
                                $image->setImageCompressionQuality($quality);
                                if (method_exists($image, 'setOption')) {
                                        $image->setOption('webp:method', '6');
                                }
                                $saved = $image->writeImage($output);
                                $image->clear();
                                $image->destroy();
                                if ($saved && file_exists($output) && filesize($output) > 0) {
                                        return true;
                                }
                        } catch (Throwable $e) {
                                @unlink($output);
                        }
                }

                if (!$this->has_gd_webp_engine()) {
                        return false;
                }

                $mime = $this->guess_mime_from_extension($input);
                if ($mime === '') {
                        $info = @getimagesize($input);
                        if (is_array($info) && isset($info['mime'])) {
                                $mime = (string) $info['mime'];
                        }
                }

                if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                        return false;
                }

                $image = $this->gd_create_image_from_file($input, $mime);
                if (!$image) {
                        return false;
                }

                if ($mime === 'image/png') {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                }

                $saved = @imagewebp($image, $output, max(0, min(100, $quality)));
                imagedestroy($image);

                return $saved && file_exists($output) && filesize($output) > 0;
        }

        private function normalize_and_resize(string $input, string $output): bool {
                $max_long_edge = (int) $this->settings['max_long_edge'];
                if ($max_long_edge < 200) {
                        $max_long_edge = 1280;
                }

                $resize_binary = $this->get_shell_resize_binary();
                if ($resize_binary !== '') {
                        $cmd = sprintf(
                                '%s %s -auto-orient -resize %s %s',
                                escapeshellcmd($resize_binary),
                                escapeshellarg($input),
                                escapeshellarg($max_long_edge . 'x' . $max_long_edge . '>'),
                                escapeshellarg($output)
                        );

                        $dummy = [];
                        $code  = 0;
                        @exec($cmd, $dummy, $code);

                        if ($code === 0 && file_exists($output) && filesize($output) > 0) {
                                return true;
                        }
                }

                if ($this->resize_with_imagick($input, $output, $max_long_edge)) {
                        return true;
                }

                return $this->resize_with_gd($input, $output, $max_long_edge);
        }

        private function optimize_intermediate(string $input, string $output, string $mime): bool {
                if ($mime === 'image/png') {
                        if (!@copy($input, $output)) {
                                return false;
                        }

                        if ($this->binary_exists('optipng')) {
                                $cmd = sprintf(
                                        'optipng -quiet -o2 %s 2>&1',
                                        escapeshellarg($output)
                                );
                                $dummy = [];
                                $code  = 0;
                                @exec($cmd, $dummy, $code);
                        }

                        if ($this->binary_exists('pngquant')) {
                                $cmd = sprintf(
                                        'pngquant --force --skip-if-larger --output %s -- %s 2>&1',
                                        escapeshellarg($output),
                                        escapeshellarg($output)
                                );
                                $dummy = [];
                                $code  = 0;
                                @exec($cmd, $dummy, $code);
                        }

                        return file_exists($output) && filesize($output) > 0;
                }

                if (!@copy($input, $output)) {
                        return false;
                }

                if ($this->binary_exists('jpegtran')) {
                        $temp_jpegtran = $output . '.jt.jpg';

                        $cmd = sprintf(
                                'jpegtran -copy none -optimize %s > %s 2>&1',
                                escapeshellarg($output),
                                escapeshellarg($temp_jpegtran)
                        );

                        $dummy = [];
                        $code  = 0;
                        @exec($cmd, $dummy, $code);

                        if ($code === 0 && file_exists($temp_jpegtran) && filesize($temp_jpegtran) > 0) {
                                @rename($temp_jpegtran, $output);
                        } else {
                                @unlink($temp_jpegtran);
                        }
                }

                if ($this->binary_exists('jpegoptim')) {
                        $cmd = sprintf(
                                'jpegoptim --strip-all --all-progressive --max=85 %s 2>&1',
                                escapeshellarg($output)
                        );

                        $dummy = [];
                        $code  = 0;
                        @exec($cmd, $dummy, $code);
                }

                return file_exists($output) && filesize($output) > 0;
        }

        /**
         * @return array<string, mixed>
         */
        private function convert_existing_file_to_webp_targeted(string $input, string $final_output, bool $allow_delete_original = false): array {
                $result = [
                        'success' => false,
                        'status' => 'failed_pre_validation',
                        'message' => '',
                        'source_path' => $input,
                        'webp_path' => $final_output,
                        'source_size' => file_exists($input) ? (int) @filesize($input) : 0,
                        'webp_size' => 0,
                        'deleted_original' => false,
                        'already_existed' => false,
                        'skipped' => false,
                ];

                $mime = $this->guess_mime_from_extension($input);
                if ($mime === '') {
                        $result['status'] = 'skipped_invalid_type';
                        $result['skipped'] = true;
                        $result['message'] = 'Tipo inválido.';
                        return $result;
                }

                if (!file_exists($input) || !is_readable($input) || (int) @filesize($input) < 1) {
                        $result['status'] = 'skipped_missing_file';
                        $result['skipped'] = true;
                        $result['message'] = 'Arquivo ausente ou sem leitura.';
                        return $result;
                }

                if ($this->is_valid_generated_file($final_output)) {
                        $result['success'] = true;
                        $result['status'] = 'already_webp_exists';
                        $result['already_existed'] = true;
                        $result['webp_size'] = (int) @filesize($final_output);

                        if ($allow_delete_original && !empty($this->settings['delete_original']) && $input !== $final_output && file_exists($input)) {
                                if (@unlink($input)) {
                                        $result['deleted_original'] = true;
                                        $result['status'] = 'already_webp_exists_original_deleted';
                                } else {
                                        $result['message'] = 'WebP já existia, mas o original não pôde ser apagado.';
                                        $result['status'] = 'already_webp_exists_original_kept';
                                }
                        }

                        return $result;
                }

                if (!$this->has_any_conversion_engine()) {
                        $result['message'] = 'Dependências indisponíveis: nem shell nem fallback PHP disponível.';
                        return $result;
                }

                $temp_source = wp_tempnam($input);
                if (!$temp_source) {
                        $result['message'] = 'Falha ao criar arquivo temporário.';
                        return $result;
                }

                $temp_source_with_ext = $temp_source . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
                if (!@rename($temp_source, $temp_source_with_ext)) {
                        @unlink($temp_source);
                        $result['message'] = 'Falha ao preparar temporário de origem.';
                        return $result;
                }

                $temp_optimized = wp_tempnam($input);
                if (!$temp_optimized) {
                        @unlink($temp_source_with_ext);
                        $result['message'] = 'Falha ao criar temporário otimizado.';
                        return $result;
                }

                $temp_optimized_with_ext = $temp_optimized . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
                if (!@rename($temp_optimized, $temp_optimized_with_ext)) {
                        @unlink($temp_source_with_ext);
                        @unlink($temp_optimized);
                        $result['message'] = 'Falha ao preparar temporário otimizado.';
                        return $result;
                }

                $resize_ok = $this->normalize_and_resize($input, $temp_source_with_ext);
                if (!$resize_ok) {
                        @unlink($temp_source_with_ext);
                        @unlink($temp_optimized_with_ext);
                        $result['status'] = 'failed_resize';
                        $result['message'] = 'Falha no resize.';
                        return $result;
                }

                $optimize_ok = $this->optimize_intermediate($temp_source_with_ext, $temp_optimized_with_ext, $mime);
                if (!$optimize_ok) {
                        @unlink($temp_source_with_ext);
                        @unlink($temp_optimized_with_ext);
                        $result['status'] = 'failed_intermediate_optimization';
                        $result['message'] = 'Falha na otimização intermediária.';
                        return $result;
                }

                $webp_ok = $this->convert_to_webp_targeted($temp_optimized_with_ext, $final_output);

                @unlink($temp_source_with_ext);
                @unlink($temp_optimized_with_ext);

                if (!$webp_ok || !$this->is_valid_generated_file($final_output)) {
                        @unlink($final_output);
                        $result['status'] = 'failed_webp_generation';
                        $result['message'] = 'Falha ao gerar WebP válido.';
                        return $result;
                }

                $result['success'] = true;
                $result['status'] = 'converted';
                $result['webp_size'] = (int) @filesize($final_output);

                if ($allow_delete_original && !empty($this->settings['delete_original']) && $input !== $final_output && $this->is_valid_generated_file($final_output)) {
                        if (@unlink($input)) {
                                $result['deleted_original'] = true;
                                $result['status'] = 'converted_original_deleted';
                        } else {
                                $result['status'] = 'converted_original_kept';
                                $result['message'] = 'WebP criado, mas o original não pôde ser apagado.';
                        }
                }

                return $result;
        }

        private function is_valid_generated_file(string $path): bool {
                return $path !== '' && file_exists($path) && is_readable($path) && (int) @filesize($path) > 0;
        }

        private function guess_mime_from_extension(string $file): string {
                $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

                if (in_array($ext, ['jpg', 'jpeg'], true)) {
                        return 'image/jpeg';
                }

                if ($ext === 'png') {
                        return 'image/png';
                }

                return '';
        }

        private function ensure_webp_exists_from_original(string $original_path, string $webp_path): bool {
                if ($webp_path !== '' && file_exists($webp_path) && is_readable($webp_path) && filesize($webp_path) > 0) {
                        return true;
                }

                if ($original_path === '' || !file_exists($original_path) || !is_readable($original_path)) {
                        return false;
                }

                if ($this->is_gif_path($original_path)) {
                        return false;
                }

                if (!preg_match('/\.(jpe?g|png)$/i', $original_path)) {
                        return false;
                }

                if (!$this->has_any_conversion_engine()) {
                        return false;
                }

                $dir = dirname($webp_path);
                if (!is_dir($dir) || !is_writable($dir)) {
                        return false;
                }

                $conversion = $this->convert_existing_file_to_webp_targeted($original_path, $webp_path, false);

                return !empty($conversion['success']) && $this->is_valid_generated_file($webp_path);
        }

        private function convert_to_webp_targeted(string $input, string $final_output): bool {
                $min_quality = (int) $this->settings['webp_min_quality'];
                $max_quality = (int) $this->settings['webp_max_quality'];

                if ($min_quality < 1) {
                        $min_quality = 45;
                }

                if ($max_quality > 100) {
                        $max_quality = 90;
                }

                if ($min_quality > $max_quality) {
                        $temp = $min_quality;
                        $min_quality = $max_quality;
                        $max_quality = $temp;
                }

                $qualities_to_try = [82, 78, 75, 72, 68, 65, 62, 58, 55, 52, 50, 48, 45, 85, 88, 90];
                $qualities_to_try = array_values(array_unique(array_filter($qualities_to_try, function ($q) use ($min_quality, $max_quality) {
                        return $q >= $min_quality && $q <= $max_quality;
                })));

                if (empty($qualities_to_try)) {
                        $qualities_to_try = [$max_quality];
                }

                $target_min_bytes = max(1024, ((int) $this->settings['target_min_kb']) * 1024);
                $target_max_bytes = max(1024, ((int) $this->settings['target_max_kb']) * 1024);

                if ($target_min_bytes > $target_max_bytes) {
                        $temp = $target_min_bytes;
                        $target_min_bytes = $target_max_bytes;
                        $target_max_bytes = $temp;
                }

                $best_file = '';
                $best_diff = PHP_INT_MAX;
                $best_size = 0;
                $target_center = (int) (($target_min_bytes + $target_max_bytes) / 2);

                foreach ($qualities_to_try as $quality) {
                        $temp_output = $final_output . '.q' . $quality . '.tmp.webp';

                        $ok = $this->run_cwebp($input, $temp_output, (int) $quality);
                        if (!$ok) {
                                @unlink($temp_output);
                                continue;
                        }

                        $size = (int) @filesize($temp_output);
                        if ($size <= 0) {
                                @unlink($temp_output);
                                continue;
                        }

                        if ($size >= $target_min_bytes && $size <= $target_max_bytes) {
                                @unlink($final_output);

                                if (@rename($temp_output, $final_output)) {
                                        $this->cleanup_temp_webp_candidates($final_output);
                                        return true;
                                }

                                @unlink($temp_output);
                                continue;
                        }

                        $diff = abs($size - $target_center);

                        if ($diff < $best_diff) {
                                if ($best_file && file_exists($best_file)) {
                                        @unlink($best_file);
                                }

                                $best_file = $temp_output;
                                $best_diff = $diff;
                                $best_size = $size;
                        } else {
                                @unlink($temp_output);
                        }
                }

                if ($best_file && file_exists($best_file) && $best_size > 0) {
                        @unlink($final_output);

                        if (@rename($best_file, $final_output)) {
                                $this->cleanup_temp_webp_candidates($final_output);
                                return true;
                        }

                        @unlink($best_file);
                }

                $this->cleanup_temp_webp_candidates($final_output);
                return false;
        }

        private function run_cwebp(string $input, string $output, int $quality): bool {
                if ($this->binary_exists('cwebp') && $this->is_exec_available()) {
                        $cmd = sprintf(
                                'cwebp -quiet -mt -q %d %s -o %s 2>&1',
                                $quality,
                                escapeshellarg($input),
                                escapeshellarg($output)
                        );

                        $dummy = [];
                        $code  = 0;
                        @exec($cmd, $dummy, $code);

                        if ($code === 0 && file_exists($output) && filesize($output) > 0) {
                                return true;
                        }
                }

                return $this->convert_to_webp_via_php($input, $output, $quality);
        }

        private function cleanup_temp_webp_candidates(string $final_output): void {
                $pattern = $final_output . '.q*.tmp.webp';
                $files = glob($pattern);

                if (is_array($files)) {
                        foreach ($files as $file) {
                                @unlink($file);
                        }
                }
        }

        private function is_exec_available(): bool {
                if (!function_exists('exec')) {
                        return false;
                }

                $disabled = (string) ini_get('disable_functions');
                if ($disabled === '') {
                        return true;
                }

                $list = array_map('trim', explode(',', $disabled));
                return !in_array('exec', $list, true);
        }

        private function binary_exists(string $binary): bool {
                if (isset($this->binary_cache[$binary])) {
                        return $this->binary_cache[$binary];
                }

                if (!$this->is_exec_available()) {
                        $this->binary_cache[$binary] = false;
                        return false;
                }

                $test_cmds = $this->get_binary_test_commands($binary);

                foreach ($test_cmds as $cmd) {
                        $dummy = [];
                        $code  = 999;

                        @exec($cmd, $dummy, $code);

                        if ($code === 0 || $code === 1) {
                                $this->binary_cache[$binary] = true;
                                return true;
                        }
                }

                $this->binary_cache[$binary] = false;
                return false;
        }

        /**
         * @return string[]
         */
        private function get_binary_test_commands(string $binary): array {
                switch ($binary) {
                        case 'magick':
                                return ['magick -version'];

                        case 'convert':
                                return ['convert -version'];

                        case 'identify':
                                return ['identify -version'];

                        case 'cwebp':
                                return ['cwebp -version'];

                        case 'jpegoptim':
                                return ['jpegoptim --version'];

                        case 'jpegtran':
                                return ['jpegtran -version'];

                        case 'pngquant':
                                return ['pngquant --version'];

                        case 'optipng':
                                return ['optipng -v'];

                        default:
                                return [escapeshellcmd($binary) . ' --version'];
                }
        }

        public function get_conversion_quality(): int {
                return max(45, min(95, (int) ($this->settings['webp_default_quality'] ?? 82)));
        }

        public function convert_file_to_webp_copy(string $input, string $output, int $quality = 82): array {
                $input = (string) $input;
                $output = (string) $output;
                $quality = max(40, min(95, $quality));

                if ($input === '' || $output === '') {
                        return ['success' => false, 'status' => 'invalid_paths'];
                }

                if (!file_exists($input) || !is_readable($input)) {
                        return ['success' => false, 'status' => 'missing_source'];
                }

                if ($this->is_valid_generated_file($output)) {
                        return ['success' => true, 'already_existed' => true, 'output' => $output];
                }

                $editor = wp_get_image_editor($input);
                if (is_wp_error($editor)) {
                        return ['success' => false, 'status' => $editor->get_error_code() ?: 'editor_error'];
                }

                if (method_exists($editor, 'set_quality')) {
                        $editor->set_quality($quality);
                }

                $saved = $editor->save($output, 'image/webp');
                if (is_wp_error($saved)) {
                        return ['success' => false, 'status' => $saved->get_error_code() ?: 'save_error'];
                }

                if (!$this->is_valid_generated_file($output)) {
                        return ['success' => false, 'status' => 'invalid_output'];
                }

                return ['success' => true, 'output' => $output];
        }
        public function render_mlgp_tab(): void {
                if (!$this->is_mlgp_available()) {
                        echo '<div class="mlmm-card"><div class="mlmm-card-header"><div><h2>ML Gallery Pro</h2><p class="mlmm-muted">ML Gallery Pro não detectado neste site. As funções dedicadas permanecem desabilitadas até a instalação ser identificada.</p></div></div></div>';
                        return;
                }

                $summary = $this->gallery_pro_compat->get_dashboard_summary();

                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>ML Gallery Pro</h2><p class="mlmm-muted">Conversão automática integrada: novos uploads do ML Gallery Pro são convertidos para WebP imediatamente após o armazenamento, com relink seguro do banco.</p></div><div class="mlmm-chip">' . esc_html($this->t('Detectado', 'Detected', 'Detectado')) . '</div></div>';
                echo '<div class="mlmm-summary-grid">';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">' . esc_html($this->t('Itens locais', 'Local items', 'Ítems locales')) . '</span><strong>' . esc_html((string) ($summary['local_items'] ?? 0)) . '</strong><small>' . esc_html((string) ($summary['message'] ?? '')) . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">' . esc_html($this->t('Conversão no upload', 'Upload conversion', 'Conversión en la carga')) . '</span><strong>' . esc_html(!empty($this->settings['enabled']) ? $this->t('Ativa', 'Active', 'Activa') : $this->t('Desativada', 'Disabled', 'Desactivada')) . '</strong><small>' . esc_html($this->t('Hook mlgp_after_items_stored ativo', 'mlgp_after_items_stored hook active', 'Hook mlgp_after_items_stored activo')) . '</small></div>';
                echo '</div>';
                echo '<div class="mlmm-card mlmm-card-inline" style="margin-top:14px;"><strong>' . esc_html($this->t('Regras fixas', 'Fixed rules', 'Reglas fijas')) . '</strong><div>' . esc_html($this->t('GIF nunca converte. Thumbs nunca mudam de tamanho. JPG/JPEG/PNG são convertidos para WebP e os paths/URLs do banco são religados com segurança.', 'GIF is never converted. Thumbs never change size. JPG/JPEG/PNG are converted to WebP and database paths/URLs are safely relinked.', 'GIF nunca se convierte. Las miniaturas nunca cambian de tamaño. JPG/JPEG/PNG se convierten a WebP y los paths/URLs de la base se reenlazan con seguridad.')) . '</div></div>';
                echo '</div>';
        }

        // ── MLGP persistent job helpers ──────────────────────────────────────────

}

new ML_Media_Master();
