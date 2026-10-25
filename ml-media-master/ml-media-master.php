<?php
/**
 * Plugin Name: ML Media Master
 * Plugin URI: https://mlopesdesign.com.br
 * Description: ML Media Master para WordPress: converte JPG, JPEG e PNG para WebP automaticamente no upload (WordPress e ML Gallery Pro). GIF nunca convertido. Licença pelo site tools.mlopesdesign.com.br (teste grátis de 30 dias).
 * Version: 2.10.2
 * Update URI: https://github.com/mlopesdesign/ml-media-master-releases
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
require_once __DIR__ . '/includes/class-mlmm-license.php';

final class ML_Media_Master {
        private const OPTION_KEY = 'ml_media_master_settings';
        private const VERSION = '2.10.2';
        // 2.10.2: fila de conversão WebP em segundo plano (antes a conversão rodava
        // dentro da renderização da página e derrubava o front-end com 500).
        private const WEBP_QUEUE_OPTION = 'mlmm_webp_queue';
        private const WEBP_QUEUE_FAILED_OPTION = 'mlmm_webp_queue_failed';
        private const WEBP_QUEUE_HOOK = 'mlmm_process_webp_queue';
        private const WEBP_QUEUE_LOCK = 'mlmm_webp_queue_lock';
        private const WEBP_QUEUE_MAX = 2000;
        private const WEBP_QUEUE_FAILED_MAX = 5000;
        private const WEBP_QUEUE_BATCH = 10;
        private const WEBP_QUEUE_TIME_BUDGET = 20;
        private const WEBP_QUEUE_MAX_ATTEMPTS = 3;
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

        /**
         * Originais encontrados sem WebP durante esta requisição (gravados uma vez no shutdown).
         *
         * @var array<string, string>
         */
        private $webp_queue_pending = [];

        /**
         * @var array<string, int>|null
         */
        private $webp_queue_failed_cache = null;

        /**
         * @var string|null
         */
        private $uploads_realpath_cache = null;

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

                // Licença pelo ML License Hub (tools.mlopesdesign.com.br). Sem licença só a
                // gravação das configurações trava; a otimização continua.
                MLMM_License::boot(self::VERSION);

                add_action('admin_menu', [$this, 'register_admin_menu']);
                add_action('admin_init', [$this, 'register_settings']);
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

                // 2.10.2: WebP faltando no front-end vira fila processada pelo WP-Cron,
                // em lotes pequenos, fora da renderização da página.
                add_action(self::WEBP_QUEUE_HOOK, [$this, 'process_webp_queue']);
                add_action('shutdown', [$this, 'flush_webp_queue'], 0);

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
         * Aba Licença (ML License Hub). Mesmo visual das outras abas do plugin.
         */
        private function render_license_panel(): void {
                $lic      = MLMM_License::summary();
                $post_url = esc_url(admin_url('admin-post.php'));
                $days     = $lic['days_left'];

                if ($lic['active'] && $lic['expires_label'] !== '' && !$lic['is_lifetime']) {
                        $valid_until = $lic['expires_label'];
                } else {
                        $valid_until = $lic['active'] ? 'Sem vencimento' : '—';
                }
                $days_label = ($lic['active'] && null !== $days && $lic['expires_label'] !== '' && !$lic['is_lifetime']) ? (string) (int) $days : '—';

                echo '<section class="mlmm-tab-panel" data-tab-panel="licenca">';
                echo '<div class="mlmm-grid mlmm-grid-4" style="margin-bottom:16px;">';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Situação</span><strong>' . esc_html($lic['label']) . '</strong><small>' . esc_html($lic['active'] ? 'Painel liberado para alterar as configurações' : 'Configurações só para leitura') . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Plano</span><strong>' . esc_html($lic['plan_label']) . '</strong><small>' . esc_html('Licença pelo site tools.mlopesdesign.com.br') . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Válida até</span><strong>' . esc_html($valid_until) . '</strong><small>' . esc_html('Conferida uma vez por dia') . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Dias restantes</span><strong>' . esc_html($days_label) . '</strong><small>' . esc_html('A otimização nunca para por falta de licença') . '</small></div>';
                echo '</div>';

                echo '<div class="mlmm-grid mlmm-grid-2">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Licença</h2><p class="mlmm-muted">' . esc_html($lic['message']) . '</p></div><div class="mlmm-chip">' . esc_html($lic['domain']) . '</div></div>';

                if ('license' === $lic['mode'] && '' !== $lic['key_masked']) {
                        echo '<div class="mlmm-card mlmm-card-inline"><strong>Chave neste site</strong><div>' . esc_html($lic['key_masked']) . '</div></div>';
                        echo '<div class="mlmm-license-actions">';
                        echo '<form method="post" action="' . $post_url . '">';
                        wp_nonce_field('mlmm_sync_license');
                        echo '<input type="hidden" name="action" value="mlmm_sync_license"><input type="hidden" name="mlmm_op" value="check">';
                        echo '<button type="submit" class="button button-primary">Conferir agora</button>';
                        echo '</form>';
                        echo '<form method="post" action="' . $post_url . '">';
                        wp_nonce_field('mlmm_remove_license');
                        echo '<input type="hidden" name="action" value="mlmm_remove_license">';
                        echo '<button type="submit" class="button">Remover deste site</button>';
                        echo '</form>';
                        echo '</div>';
                } else {
                        echo '<form method="post" action="' . $post_url . '">';
                        wp_nonce_field('mlmm_activate_license');
                        echo '<input type="hidden" name="action" value="mlmm_activate_license">';
                        echo '<p><label for="mlmm-license-key"><strong>Chave da licença</strong></label><br><input id="mlmm-license-key" type="text" name="license_key" value="" class="regular-text" style="width:100%;max-width:420px" placeholder="Cole aqui a sua chave" autocomplete="off" spellcheck="false"></p>';
                        echo '<p><button type="submit" class="button button-primary">Ativar licença</button></p>';
                        echo '</form>';
                        echo '<div class="mlmm-license-actions">';
                        if ($lic['can_start_trial']) {
                                echo '<form method="post" action="' . $post_url . '">';
                                wp_nonce_field('mlmm_sync_license');
                                echo '<input type="hidden" name="action" value="mlmm_sync_license"><input type="hidden" name="mlmm_op" value="trial">';
                                echo '<button type="submit" class="button">Começar teste grátis de 30 dias</button>';
                                echo '</form>';
                        } elseif ($lic['is_trial']) {
                                echo '<form method="post" action="' . $post_url . '">';
                                wp_nonce_field('mlmm_sync_license');
                                echo '<input type="hidden" name="action" value="mlmm_sync_license"><input type="hidden" name="mlmm_op" value="check">';
                                echo '<button type="submit" class="button">Conferir agora</button>';
                                echo '</form>';
                        }
                        echo '</div>';
                }

                echo '<div class="mlmm-license-actions">';
                echo '<a class="button" href="' . esc_url(MLMM_License::BUY_URL) . '" target="_blank" rel="noopener noreferrer">Comprar licença</a>';
                echo '<a class="button" href="' . esc_url(MLMM_License::ACCOUNT_URL) . '" target="_blank" rel="noopener noreferrer">Minha conta</a>';
                echo '</div>';
                echo '</div>';

                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Como funciona</h2><p class="mlmm-muted">Licença emitida e conferida pelo site tools.mlopesdesign.com.br.</p></div><div class="mlmm-chip">tools.mlopesdesign.com.br</div></div>';
                echo '<table class="widefat striped"><tbody>';
                echo '<tr><td><strong>Teste grátis</strong></td><td>30 dias com tudo liberado, uma vez por site. Reinstalar não reinicia.</td></tr>';
                echo '<tr><td><strong>Licença anual</strong></td><td>Vale em 1 site. Para usar em outro, remova deste site.</td></tr>';
                echo '<tr><td><strong>Pacote Agência</strong></td><td>Uma chave para todos os plugins ML em 5, 10 ou 50 sites.</td></tr>';
                echo '<tr><td><strong>Sem licença</strong></td><td>A otimização continua: novas imagens seguem virando WebP e o site continua mostrando as imagens otimizadas. Só as configurações ficam bloqueadas para alterar. Nada é apagado.</td></tr>';
                echo '</tbody></table>';
                echo '<p class="mlmm-muted" style="margin-top:12px;">A licença é conferida uma vez por dia. Sem internet, o site continua liberado por até 7 dias.</p>';
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
                                'ML Media Master ' . self::VERSION . ' instalado. A licença agora é feita pelo site tools.mlopesdesign.com.br, com teste grátis de 30 dias. A otimização das imagens continua igual.',
                                'ML Media Master ' . self::VERSION . ' installed. Licensing now runs through tools.mlopesdesign.com.br, with a 30-day free trial. Image optimization works as before.',
                                'ML Media Master ' . self::VERSION . ' instalado. La licencia ahora se gestiona en tools.mlopesdesign.com.br, con prueba gratuita de 30 días. La optimización de imágenes sigue igual.'
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
                echo '<button type="button" class="mlmm-tab-button" data-tab="licenca" role="tab" aria-selected="false">Licença</button>';
                echo '</div>';

                if (MLMM_License::is_locked()) {
                        MLMM_License::render_locked_notice();
                }

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
                if (MLMM_License::is_locked()) {
                        echo '<p class="mlmm-muted">' . esc_html(MLMM_License::locked_message()) . '</p>';
                        submit_button($this->t('Salvar configurações', 'Save settings', 'Guardar configuración'), 'primary', 'submit', true, ['disabled' => 'disabled']);
                } else {
                        submit_button($this->t('Salvar configurações', 'Save settings', 'Guardar configuración'));
                }
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

                // 2.10.2: caminho com "../" para fora de uploads não é tratado.
                if (file_exists($absolute_path) && $this->resolve_upload_file($absolute_path) === '') {
                        return;
                }

                $redirect_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
                if ($this->ensure_webp_exists_from_original($absolute_path, $webp_path)) {
                        // ok: WebP do mesmo nome.
                } else {
                        // 2.10.2: o JPG/PNG enviado que ficava ao lado do "-scaled"/"-rotated" agora é
                        // apagado; o pedido ao nome original vai para o WebP desse arquivo.
                        $redirect_path = '';
                        foreach (['-scaled', '-rotated'] as $suffix) {
                                $candidate = (string) preg_replace('/\.(jpe?g|png)$/i', $suffix . '.webp', $absolute_path);
                                if ($this->is_real_webp($candidate)) {
                                        $redirect_path = (string) preg_replace('/\.(jpe?g|png)$/i', $suffix . '.webp', $path);
                                        break;
                                }
                        }
                }

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

                // 2.10.2: aqui só troca a URL se o WebP já existe. Antes este filtro convertia a
                // imagem na hora, dentro da renderização (wp_get_attachment_image_src, the_content,
                // srcset...): uma página com dezenas de imagens disparava dezenas de conversões por
                // visita, repetia as que falhavam em toda visita e, fora do wp-admin, caía em erro
                // fatal "Call to undefined function wp_tempnam()" (HTTP 500). Agora o original vai
                // para a fila e o WebP é gerado em segundo plano; na visita seguinte a URL troca.
                if ($this->is_valid_generated_file($webp_path)) {
                        // WebP já existe (gerado por versões antigas). Com a Remoção segura ligada e
                        // o original ainda no disco, a fila religa o anexo ao WebP e apaga o JPG/PNG.
                        if (!empty($this->settings['delete_original']) && file_exists($absolute_path)) {
                                $this->queue_webp_conversion($absolute_path, $webp_path);
                        }
                        return $webp_url;
                }

                $this->queue_webp_conversion($absolute_path, $webp_path);

                return $url;
        }

        /**
         * Caminho real (realpath) de um arquivo DENTRO da pasta de uploads, ou '' se estiver fora
         * (ex.: "uploads/../themes/x.png" vindo do conteúdo de um post) ou não existir.
         */
        private function resolve_upload_file(string $path): string {
                if ($this->uploads_realpath_cache === null) {
                        $uploads = wp_get_upload_dir();
                        $base = !empty($uploads['basedir']) ? realpath((string) $uploads['basedir']) : false;
                        $this->uploads_realpath_cache = is_string($base) ? rtrim(wp_normalize_path($base), '/') : '';
                }

                if ($this->uploads_realpath_cache === '' || $path === '') {
                        return '';
                }

                $real = realpath($path);
                if (!is_string($real) || !is_file($real)) {
                        return '';
                }

                $real = wp_normalize_path($real);

                return strpos($real, $this->uploads_realpath_cache . '/') === 0 ? $real : '';
        }

        /**
         * WebP de verdade: assinatura WebP, largura/altura > 0 e, com GD, decodifica inteiro
         * (pega arquivo truncado por processo interrompido). Exigido antes de apagar qualquer original.
         */
        private function is_real_webp(string $path): bool {
                if (!$this->is_valid_generated_file($path)) {
                        return false;
                }

                $info = @getimagesize($path);
                if (!is_array($info) || empty($info[0]) || empty($info[1]) || (int) ($info[2] ?? 0) !== IMAGETYPE_WEBP) {
                        return false;
                }

                if (function_exists('imagecreatefromwebp') && ((int) $info[0] * (int) $info[1]) <= 40000000) {
                        $image = @imagecreatefromwebp($path);
                        if (!$image) {
                                return false;
                        }
                        imagedestroy($image);
                }

                return true;
        }

        private function queue_webp_conversion(string $original_path, string $webp_path): void {
                unset($webp_path);

                if ($original_path === '' || isset($this->webp_queue_pending[$original_path])) {
                        return;
                }

                if (!preg_match('/\.(jpe?g|png)$/i', $original_path)) {
                        return;
                }

                $real = $this->resolve_upload_file($original_path);
                if ($real === '') {
                        return;
                }

                if ($this->webp_queue_failed_cache === null) {
                        $failed = get_option(self::WEBP_QUEUE_FAILED_OPTION, []);
                        $this->webp_queue_failed_cache = is_array($failed) ? $failed : [];
                }

                if ((int) ($this->webp_queue_failed_cache[$real] ?? 0) >= self::WEBP_QUEUE_MAX_ATTEMPTS) {
                        return;
                }

                $this->webp_queue_pending[$original_path] = $real;
        }

        public function flush_webp_queue(): void {
                if (empty($this->webp_queue_pending)) {
                        return;
                }

                $queue = get_option(self::WEBP_QUEUE_OPTION, []);
                if (!is_array($queue)) {
                        $queue = [];
                }

                $before = count($queue);
                foreach ($this->webp_queue_pending as $real) {
                        if (count($queue) >= self::WEBP_QUEUE_MAX) {
                                break;
                        }
                        $queue[$real] = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $real);
                }
                $this->webp_queue_pending = [];

                if (count($queue) !== $before) {
                        update_option(self::WEBP_QUEUE_OPTION, $queue, false);
                }

                if (!empty($queue) && !wp_next_scheduled(self::WEBP_QUEUE_HOOK)) {
                        wp_schedule_single_event(time() + 30, self::WEBP_QUEUE_HOOK);
                }
        }

        /**
         * Trava atômica (INSERT IGNORE): só um processo converte por vez. Trava com mais de
         * 10 minutos é de processo morto e é tomada com UPDATE condicional (também atômico).
         */
        private function acquire_webp_queue_lock(): bool {
                global $wpdb;

                $now = (string) time();
                $inserted = $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                        self::WEBP_QUEUE_LOCK,
                        $now
                ));
                if ((int) $inserted === 1) {
                        return true;
                }

                $since = (string) $wpdb->get_var($wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                        self::WEBP_QUEUE_LOCK
                ));
                if ($since !== '' && (time() - (int) $since) < 10 * MINUTE_IN_SECONDS) {
                        return false;
                }

                $taken = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                        $now,
                        self::WEBP_QUEUE_LOCK,
                        $since
                ));

                return (int) $taken === 1;
        }

        private function release_webp_queue_lock(): void {
                global $wpdb;

                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::WEBP_QUEUE_LOCK));
                wp_cache_delete(self::WEBP_QUEUE_LOCK, 'options');
        }

        /**
         * @return array<string, int>
         */
        private function get_webp_queue_failed(): array {
                wp_cache_delete(self::WEBP_QUEUE_FAILED_OPTION, 'options');
                $failed = get_option(self::WEBP_QUEUE_FAILED_OPTION, []);

                return is_array($failed) ? $failed : [];
        }

        /**
         * @param array<string, int> $failed
         */
        private function save_webp_queue_failed(array $failed): void {
                if (count($failed) > self::WEBP_QUEUE_FAILED_MAX) {
                        $failed = array_slice($failed, -self::WEBP_QUEUE_FAILED_MAX, null, true);
                }
                update_option(self::WEBP_QUEUE_FAILED_OPTION, $failed, false);
        }

        /**
         * Anexo cujo JPG/PNG já foi apagado mas o WebP existe: aponta banco e metadata para o WebP.
         *
         * @param array<string, mixed> $metadata
         * @return array<string, mixed>
         */
        private function relink_attachment_to_existing_webp(array $metadata, int $attachment_id): array {
                $attached = get_attached_file($attachment_id, true);
                if (!is_string($attached) || $attached === '' || file_exists($attached)) {
                        return $metadata;
                }

                $webp_file = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $attached);
                if ($webp_file === $attached || !$this->is_real_webp($webp_file)) {
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

                $metadata['file'] = _wp_relative_upload_path($webp_file);
                $metadata['filesize'] = (int) @filesize($webp_file);
                $size = @getimagesize($webp_file);
                if ($size) {
                        $metadata['width']  = (int) $size[0];
                        $metadata['height'] = (int) $size[1];
                }
                $dir = dirname($webp_file);
                foreach ((array) ($metadata['sizes'] ?? []) as $size_key => $size_data) {
                        if (!is_array($size_data) || empty($size_data['file']) || !is_string($size_data['file'])) {
                                continue;
                        }
                        $sub_webp_name = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $size_data['file']);
                        if ($sub_webp_name !== $size_data['file'] && $this->is_real_webp(trailingslashit($dir) . $sub_webp_name)) {
                                $metadata['sizes'][$size_key]['file'] = $sub_webp_name;
                                $metadata['sizes'][$size_key]['mime-type'] = 'image/webp';
                        }
                }
                if (!empty($metadata['original_image']) && is_string($metadata['original_image']) && !file_exists(trailingslashit($dir) . $metadata['original_image'])) {
                        unset($metadata['original_image']);
                }

                return $metadata;
        }

        /**
         * Acha os anexos donos de um arquivo de uploads (principal, "-scaled" ou tamanho "-LxA").
         *
         * @return array<int, int>
         */
        private function find_attachment_ids_for_upload_path(string $path): array {
                global $wpdb;

                $uploads = wp_get_upload_dir();
                $basedir_real = !empty($uploads['basedir']) ? realpath((string) $uploads['basedir']) : false;
                $basedir = is_string($basedir_real) ? rtrim(wp_normalize_path($basedir_real), '/') . '/' : '';
                $path = wp_normalize_path($path);
                if ($basedir === '/' || strpos($path, $basedir) !== 0) {
                        return [];
                }

                $relative = substr($path, strlen($basedir));
                if (!is_string($relative) || $relative === '') {
                        return [];
                }

                $candidates = [$relative];
                $base = (string) preg_replace('/-\d+x\d+(\.(?:jpe?g|png))$/i', '$1', $relative);
                $candidates[] = $base;
                $candidates[] = (string) preg_replace('/(\.(?:jpe?g|png))$/i', '-scaled$1', $base);
                $candidates[] = (string) preg_replace('/-scaled(\.(?:jpe?g|png))$/i', '$1', $relative);
                // Anexos já religados ao WebP também contam (tamanho JPG/PNG que sobrou).
                foreach ($candidates as $candidate) {
                        $candidates[] = (string) preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $candidate);
                }
                $candidates = array_values(array_unique(array_filter($candidates)));

                $placeholders = implode(',', array_fill(0, count($candidates), '%s'));
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($placeholders)",
                        $candidates
                ));

                $filename = wp_basename($path);
                $filename_webp = (string) preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $filename);
                $path_webp = (string) preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $path);
                $found = [];
                foreach ((array) $ids as $id) {
                        $id = (int) $id;
                        $attached = get_attached_file($id, true);
                        if (is_string($attached) && in_array(wp_normalize_path($attached), [$path, $path_webp], true)) {
                                $found[] = $id;
                                continue;
                        }

                        $metadata = wp_get_attachment_metadata($id);
                        if (!is_array($metadata)) {
                                continue;
                        }
                        if (!empty($metadata['original_image']) && $metadata['original_image'] === $filename) {
                                $found[] = $id;
                                continue;
                        }
                        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
                                if (is_array($size) && isset($size['file']) && in_array($size['file'], [$filename, $filename_webp], true)) {
                                        $found[] = $id;
                                        break;
                                }
                        }
                }

                return array_values(array_unique($found));
        }

        public function process_webp_queue(): void {
                if (!$this->acquire_webp_queue_lock()) {
                        return;
                }

                $this->settings = $this->get_settings();

                $budget = self::WEBP_QUEUE_TIME_BUDGET;
                $max_exec = (int) ini_get('max_execution_time');
                if ($max_exec > 0) {
                        $budget = max(5, min($budget, (int) floor($max_exec / 2)));
                }

                $started = time();
                $processed = 0;
                while ($processed < self::WEBP_QUEUE_BATCH && (time() - $started) < $budget) {
                        wp_cache_delete(self::WEBP_QUEUE_OPTION, 'options');
                        $queue = get_option(self::WEBP_QUEUE_OPTION, []);
                        if (!is_array($queue) || empty($queue)) {
                                break;
                        }

                        reset($queue);
                        $original = (string) key($queue);
                        unset($queue[$original]);
                        // Progresso gravado item a item: se o processo morrer, o item já saiu da fila.
                        update_option(self::WEBP_QUEUE_OPTION, $queue, false);

                        $real = $this->resolve_upload_file($original);
                        if ($real === '' || !preg_match('/\.(jpe?g|png)$/i', $real)) {
                                continue; // sumiu ou está fora de uploads: não conta no lote.
                        }
                        $webp = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $real);

                        $failed = $this->get_webp_queue_failed();
                        $attempts = (int) ($failed[$real] ?? 0);
                        if ($attempts >= self::WEBP_QUEUE_MAX_ATTEMPTS) {
                                continue;
                        }

                        // A tentativa conta ANTES de converter: um erro fatal (memória/tempo) também
                        // conta, e depois de 3 o arquivo não volta para a fila.
                        $failed[$real] = $attempts + 1;
                        $this->save_webp_queue_failed($failed);
                        $processed++;

                        $ok = $this->convert_queued_original($real, $webp);

                        $failed = $this->get_webp_queue_failed();
                        if ($ok) {
                                if (file_exists($real)) {
                                        // Convertido, mas o original fica (fora da biblioteca ou
                                        // Remoção segura desligada): não volta para a fila.
                                        $failed[$real] = self::WEBP_QUEUE_MAX_ATTEMPTS;
                                } else {
                                        unset($failed[$real]);
                                }
                                $this->save_webp_queue_failed($failed);
                        }
                }

                wp_cache_delete(self::WEBP_QUEUE_OPTION, 'options');
                $queue = get_option(self::WEBP_QUEUE_OPTION, []);
                if (!is_array($queue) || empty($queue)) {
                        delete_option(self::WEBP_QUEUE_OPTION);
                } elseif (!wp_next_scheduled(self::WEBP_QUEUE_HOOK)) {
                        wp_schedule_single_event(time() + 60, self::WEBP_QUEUE_HOOK);
                }

                $this->release_webp_queue_lock();
        }

        /**
         * Converte um original da fila.
         *
         * Anexo da Biblioteca de Mídia: mesmo caminho do upload (fix_metadata_after_webp) —
         * principal e tamanhos em WebP, banco no WebP, JPG/PNG apagados (Remoção segura). Só conta
         * como sucesso se nenhum anexo continuar apontando para o arquivo da fila.
         *
         * Fora da biblioteca (ML Gallery Pro antigo, tema, formulários, backups do editor de imagem):
         * só gera o WebP. Não apaga, porque quem aponta para esse arquivo não é religado aqui.
         */
        private function convert_queued_original(string $original, string $webp): bool {
                $attachment_ids = $this->find_attachment_ids_for_upload_path($original);

                if (empty($attachment_ids)) {
                        return $this->ensure_webp_exists_from_original($original, $webp) && $this->is_real_webp($webp);
                }

                foreach ($attachment_ids as $attachment_id) {
                        $metadata = wp_get_attachment_metadata($attachment_id);
                        if (!is_array($metadata)) {
                                continue;
                        }

                        $mime = strtolower((string) get_post_mime_type($attachment_id));
                        $attached = get_attached_file($attachment_id, true);

                        if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
                                if (is_string($attached) && file_exists($attached)) {
                                        $new_metadata = $this->fix_metadata_after_webp($metadata, $attachment_id);
                                } else {
                                        // Outro anexo com o mesmo arquivo já converteu e apagou o JPG/PNG.
                                        $new_metadata = $this->relink_attachment_to_existing_webp($metadata, $attachment_id);
                                }
                        } elseif ($mime === 'image/webp') {
                                // Anexo já em WebP com tamanho/original JPG/PNG que sobrou.
                                $new_metadata = $this->finish_webp_attachment($metadata, $attachment_id);
                        } else {
                                continue;
                        }

                        if ($new_metadata !== $metadata) {
                                wp_update_attachment_metadata($attachment_id, $new_metadata);
                        }
                }

                $filename = wp_basename($original);
                foreach ($attachment_ids as $attachment_id) {
                        if ($this->attachment_references_file($attachment_id, $filename)) {
                                return false;
                        }
                }

                return true;
        }

        private function attachment_references_file(int $attachment_id, string $filename): bool {
                $attached = get_attached_file($attachment_id, true);
                if (is_string($attached) && wp_basename($attached) === $filename) {
                        return true;
                }

                $metadata = wp_get_attachment_metadata($attachment_id);
                if (!is_array($metadata)) {
                        return false;
                }

                if (!empty($metadata['original_image']) && $metadata['original_image'] === $filename) {
                        return true;
                }

                foreach ((array) ($metadata['sizes'] ?? []) as $size) {
                        if (is_array($size) && isset($size['file']) && $size['file'] === $filename) {
                                return true;
                        }
                }

                return false;
        }

        /**
         * Anexo já em WebP: converte os tamanhos que ainda são JPG/PNG, religa no metadata e
         * apaga os JPG/PNG (Remoção segura), incluindo o original_image ao lado do "-scaled".
         *
         * @param array<string, mixed> $metadata
         * @return array<string, mixed>
         */
        private function finish_webp_attachment(array $metadata, int $attachment_id): array {
                if (empty($this->settings['enabled'])) {
                        return $metadata;
                }

                $main = get_attached_file($attachment_id, true);
                if (!is_string($main) || !$this->is_real_webp($main)) {
                        return $metadata;
                }

                $dir = dirname($main);
                $to_delete = [];

                foreach ((array) ($metadata['sizes'] ?? []) as $size_key => $size_data) {
                        if (!is_array($size_data) || empty($size_data['file']) || !is_string($size_data['file'])) {
                                continue;
                        }
                        if (!preg_match('/\.(jpe?g|png)$/i', $size_data['file'])) {
                                continue;
                        }

                        $sub_original = trailingslashit($dir) . $size_data['file'];
                        $sub_webp_name = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $size_data['file']);
                        $sub_webp = trailingslashit($dir) . $sub_webp_name;

                        if (file_exists($sub_original)) {
                                $sub_result = $this->convert_file_to_webp_copy($sub_original, $sub_webp);
                                if (empty($sub_result['success'])) {
                                        continue;
                                }
                        }

                        if (!$this->is_real_webp($sub_webp)) {
                                continue;
                        }

                        $metadata['sizes'][$size_key]['file'] = $sub_webp_name;
                        $metadata['sizes'][$size_key]['mime-type'] = 'image/webp';
                        $metadata['sizes'][$size_key]['filesize'] = (int) @filesize($sub_webp);
                        if (file_exists($sub_original)) {
                                $to_delete[] = $sub_original;
                        }
                }

                if (!empty($this->settings['delete_original']) && !empty($metadata['original_image']) && is_string($metadata['original_image'])) {
                        $kept = trailingslashit($dir) . wp_basename($metadata['original_image']);
                        if (preg_match('/\.(jpe?g|png)$/i', $kept)) {
                                if (file_exists($kept)) {
                                        $to_delete[] = $kept;
                                }
                                unset($metadata['original_image']);
                        }
                }

                if (!empty($this->settings['delete_original'])) {
                        foreach (array_unique($to_delete) as $old_file) {
                                if ($old_file !== $main && file_exists($old_file)) {
                                        @unlink($old_file);
                                }
                        }
                }

                return $metadata;
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
                        if ($this->is_real_webp($new_path) && file_exists($old_path) && is_writable($old_path)) {
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
                // 2.10.2: só religa (e depois apaga o original) com WebP decodificável de verdade.
                if (empty($converted_main['success']) || !$this->is_real_webp($webp_file)) {
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
                                if (empty($sub_result['success']) || !$this->is_real_webp($sub_webp)) {
                                        continue;
                                }

                                $metadata['sizes'][$size_key]['file'] = $sub_webp_name;
                                $metadata['sizes'][$size_key]['mime-type'] = 'image/webp';
                                $metadata['sizes'][$size_key]['filesize'] = (int) @filesize($sub_webp);
                                $originals_to_delete[] = $sub_original;
                        }
                }

                // 2.10.2: com o redimensionamento do WordPress ligado (disable_wp_big_image_scale
                // desligado), o arquivo anexado é o "-scaled" e o JPG/PNG enviado fica guardado em
                // metadata['original_image']. Ele também é original e sai junto, depois que o WebP
                // principal foi validado acima; a referência no metadata é removida.
                if (!empty($this->settings['delete_original']) && !empty($metadata['original_image']) && is_string($metadata['original_image'])) {
                        $kept_original = trailingslashit($original_dir) . wp_basename($metadata['original_image']);
                        if (preg_match('/\.(jpe?g|png)$/i', $kept_original) && $kept_original !== $webp_file) {
                                $originals_to_delete[] = $kept_original;
                                unset($metadata['original_image']);
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

                // 2.10.2: WebP existente truncado (processo interrompido) é refeito, não aceito.
                if ($this->is_valid_generated_file($final_output) && !$this->is_real_webp($final_output)) {
                        @unlink($final_output);
                }

                if ($this->is_valid_generated_file($final_output)) {
                        $result['success'] = true;
                        $result['status'] = 'already_webp_exists';
                        $result['already_existed'] = true;
                        $result['webp_size'] = (int) @filesize($final_output);

                        if ($allow_delete_original && !empty($this->settings['delete_original']) && $input !== $final_output && file_exists($input) && $this->is_real_webp($final_output)) {
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

                // 2.10.2: wp_tempnam() mora em wp-admin/includes/file.php, que o WordPress só
                // carrega no painel. Fora dele (front-end, WP-Cron, REST, ML Gallery Pro) a chamada
                // dava erro fatal "Call to undefined function wp_tempnam()".
                if (!function_exists('wp_tempnam')) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
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

                if ($allow_delete_original && !empty($this->settings['delete_original']) && $input !== $final_output && $this->is_real_webp($final_output)) {
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

                if ($this->is_real_webp($output)) {
                        return ['success' => true, 'already_existed' => true, 'output' => $output];
                }

                $editor = wp_get_image_editor($input);
                if (is_wp_error($editor)) {
                        return ['success' => false, 'status' => $editor->get_error_code() ?: 'editor_error'];
                }

                if (method_exists($editor, 'set_quality')) {
                        $editor->set_quality($quality);
                }

                // 2.10.2: grava em temporário e renomeia; um processo interrompido não deixa um
                // WebP truncado com o nome final.
                $temp_output = $output . '.mlmm-tmp.webp';
                $saved = $editor->save($temp_output, 'image/webp');
                if (is_wp_error($saved)) {
                        @unlink($temp_output);
                        return ['success' => false, 'status' => $saved->get_error_code() ?: 'save_error'];
                }

                $saved_path = is_array($saved) && !empty($saved['path']) ? (string) $saved['path'] : $temp_output;
                if (!$this->is_real_webp($saved_path) || !@rename($saved_path, $output)) {
                        @unlink($saved_path);
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

// 2.10.2: ao desativar, desagenda a fila de conversão WebP em segundo plano.
register_deactivation_hook(__FILE__, static function (): void {
        wp_clear_scheduled_hook('mlmm_process_webp_queue');
});
