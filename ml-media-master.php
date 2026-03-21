<?php
/**
 * Plugin Name: ML Media Optimizer
 * Plugin URI: https://mlopesdesign.com.br
 * Description: ML Media Optimizer para WordPress: converte JPG, JPEG e PNG para WebP, executa lotes AJAX, oferece quarentena segura de originais e suporte a licença Free/Full por serial.
 * Version: 2.6.12
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Marcio Lopes
 * Author URI: https://mlopesdesign.com.br
 * License: GPL2+
 * Text Domain: ml-media-master
 */

if (!defined('ABSPATH')) {
        if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
                $wp_load = dirname(__FILE__, 4) . '/wp-load.php';
                if (file_exists($wp_load)) {
                        require_once $wp_load;
                }
        }

        if (!defined('ABSPATH')) {
                exit;
        }
}

final class ML_Media_Master {
        private const OPTION_KEY = 'ml_media_master_settings';
        private const BATCH_NOTICE_TRANSIENT = 'ml_media_master_batch_notice';
        private const BATCH_REPORT_OPTION = 'ml_media_master_last_batch_report';
        private const BATCH_STATS_OPTION = 'ml_media_master_batch_stats';
        private const BATCH_STOP_OPTION = 'ml_media_master_batch_stop_flags';
        private const BATCH_JOB_OPTION = 'ml_media_master_batch_job';
        private const QUARANTINE_STATUS_OPTION = 'ml_media_master_quarantine_status';
        private const QUARANTINE_AUDIT_OPTION = 'ml_media_master_quarantine_audit_last';
        private const QUARANTINE_REPORT_OPTION = 'ml_media_master_quarantine_last_report';
        private const QUARANTINE_DIR_NAME = 'mlmm-quarantine';
        private const BATCH_LOCK_TTL = 30;
        private const VERSION = '2.6.12';
        private const BATCH_CONTROL_OPTION = 'ml_media_master_batch_control';
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

        /**
         * @return array<string, string>
         */
        private function get_admin_js_strings(): array {
                return [
                        'starting' => $this->t('Preparando fila...', 'Preparing queue...', 'Preparando cola...'),
                        'running' => $this->t('Processando lote...', 'Processing batch...', 'Procesando lote...'),
                        'paused' => $this->t('Lote pausado.', 'Batch paused.', 'Lote pausado.'),
                        'pausing' => $this->t('Pausa solicitada...', 'Pause requested...', 'Pausa solicitada...'),
                        'stopping' => $this->t('Encerrando lote...', 'Stopping batch...', 'Deteniendo lote...'),
                        'finished' => $this->t('Lote concluído.', 'Batch finished.', 'Lote finalizado.'),
                        'aborted' => $this->t('Lote interrompido.', 'Batch aborted.', 'Lote interrumpido.'),
                        'error' => $this->t('Erro no lote.', 'Batch error.', 'Error en el lote.'),
                        'idle' => $this->t('Nenhum lote ativo.', 'No active batch.', 'Ningún lote activo.'),
                        'processing' => $this->t('Processando...', 'Processing...', 'Procesando...'),
                        'saved' => $this->t('Configurações salvas.', 'Settings saved.', 'Configuración guardada.'),
                        'freeLocked' => $this->t('Recurso disponível apenas na versão Full.', 'Feature available only in the Full version.', 'Función disponible solo en la versión Full.'),
                        'operationFailed' => $this->t('Falha na operação.', 'Operation failed.', 'La operación falló.'),
                        'batchFinishedToast' => $this->t('Lote concluído: %1$d processados, %2$d convertidos, %3$d erros, %4$d originais apagados.', 'Batch finished: %1$d processed, %2$d converted, %3$d errors, %4$d originals removed.', 'Lote finalizado: %1$d procesados, %2$d convertidos, %3$d errores, %4$d originales eliminados.'),
                        'batchAbortedToast' => $this->t('Lote interrompido. Processados: %d.', 'Batch aborted. Processed: %d.', 'Lote interrumpido. Procesados: %d.'),
                        'batchErrorToast' => $this->t('O lote terminou com erro. Verifique o relatório.', 'The batch ended with an error. Check the report.', 'El lote terminó con error. Revisa el informe.'),
                        'refreshSuccess' => $this->t('Pendências atualizadas com sucesso.', 'Pending items refreshed successfully.', 'Pendientes actualizados correctamente.'),
                        'refreshFail' => $this->t('Falha ao atualizar pendências.', 'Failed to refresh pending items.', 'Error al actualizar pendientes.'),
                        'processFail' => $this->t('Falha ao processar o lote.', 'Failed to process batch.', 'Error al procesar el lote.'),
                        'startBusy' => $this->t('Iniciando...', 'Starting...', 'Iniciando...'),
                        'startSuccess' => $this->t('Lote iniciado para o escopo %s.', 'Batch started for scope %s.', 'Lote iniciado para el alcance %s.'),
                        'startFail' => $this->t('Falha ao iniciar lote.', 'Failed to start batch.', 'Error al iniciar el lote.'),
                        'refreshBusy' => $this->t('Atualizando...', 'Refreshing...', 'Actualizando...'),
                        'testBusy' => $this->t('Testando...', 'Testing...', 'Probando...'),
                        'envTestSuccess' => $this->t('Ambiente testado com sucesso.', 'Environment tested successfully.', 'Entorno probado correctamente.'),
                        'envTestFail' => $this->t('Falha ao testar o ambiente.', 'Failed to test environment.', 'Error al probar el entorno.'),
                        'pauseBusy' => $this->t('Pausando...', 'Pausing...', 'Pausando...'),
                        'pauseSuccess' => $this->t('Lote pausado.', 'Batch paused.', 'Lote pausado.'),
                        'resumeBusy' => $this->t('Retomando...', 'Resuming...', 'Reanudando...'),
                        'resumeSuccess' => $this->t('Lote retomado.', 'Batch resumed.', 'Lote reanudado.'),
                        'stopBusy' => $this->t('Parando...', 'Stopping...', 'Deteniendo...'),
                        'stopSuccess' => $this->t('Parada solicitada. O lote vai parar ao finalizar a imagem atual.', 'Stop requested. The batch will stop after the current image.', 'Parada solicitada. El lote se detendrá al finalizar la imagen actual.'),
                        'pauseRequested' => $this->t('Pausa solicitada. O lote será pausado ao finalizar a imagem atual.', 'Pause requested. The batch will pause after the current image.', 'Pausa solicitada. El lote se pausará al finalizar la imagen actual.'),
                        'resetBusy' => $this->t('Resetando...', 'Resetting...', 'Reiniciando...'),
                        'resetSuccess' => $this->t('Estado do lote resetado com sucesso.', 'Batch state reset successfully.', 'Estado del lote restablecido correctamente.'),
                        'reportBusy' => $this->t('Limpando...', 'Clearing...', 'Limpiando...'),
                        'reportSuccess' => $this->t('Relatório limpo com sucesso.', 'Report cleared successfully.', 'Informe limpiado correctamente.'),
                        'auditSuccess' => $this->t('Auditoria concluída.', 'Audit completed.', 'Auditoría completada.'),
                        'moveSuccess' => $this->t('Candidatos movidos para quarentena.', 'Candidates moved to quarantine.', 'Candidatos movidos a cuarentena.'),
                        'restoreSuccess' => $this->t('Quarentena restaurada com sucesso.', 'Quarantine restored successfully.', 'Cuarentena restaurada correctamente.'),
                        'quarantineClearSuccess' => $this->t('Quarentena limpa com sucesso.', 'Quarantine cleared successfully.', 'Cuarentena limpiada correctamente.'),
                        'quarantineStatusSuccess' => $this->t('Status da quarentena atualizado.', 'Quarantine status updated.', 'Estado de la cuarentena actualizado.'),
                        'ghostStateWarning' => $this->t('Nenhum lote ativo foi encontrado. Os números exibidos são apenas estatísticas salvas. Você pode iniciar novamente o lote ou resetar o estado.', 'No active batch was found. The numbers shown are only saved statistics. You can start the batch again or reset the state.', 'No se encontró ningún lote activo. Los números mostrados son solo estadísticas guardadas. Puedes iniciar el lote nuevamente o restablecer el estado.'),
                ];
        }

        public function __construct() {
                $this->settings = $this->get_settings();

                add_action('admin_menu', [$this, 'register_admin_menu']);
                add_action('admin_init', [$this, 'register_settings']);
                add_action('admin_init', [$this, 'maybe_handle_license_actions']);
                add_action('admin_notices', [$this, 'admin_notice_dependencies']);
                add_action('admin_notices', [$this, 'render_batch_notice']);
                add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
                add_action('template_redirect', [$this, 'maybe_redirect_missing_upload_to_webp'], 1);

                add_action('wp_ajax_mlmm_refresh_stats', [$this, 'ajax_refresh_stats']);
                add_action('wp_ajax_mlmm_start_batch', [$this, 'ajax_start_batch']);
                add_action('wp_ajax_mlmm_process_batch', [$this, 'ajax_process_batch']);
                add_action('wp_ajax_mlmm_get_batch_status', [$this, 'ajax_get_batch_status']);
                add_action('wp_ajax_mlmm_pause_batch', [$this, 'ajax_pause_batch']);
                add_action('wp_ajax_mlmm_resume_batch', [$this, 'ajax_resume_batch']);
                add_action('wp_ajax_mlmm_stop_batch', [$this, 'ajax_stop_batch']);
                add_action('wp_ajax_mlmm_reset_batch_state', [$this, 'ajax_reset_batch_state']);
                add_action('wp_ajax_mlmm_clear_report', [$this, 'ajax_clear_report']);
                add_action('wp_ajax_mlmm_test_environment', [$this, 'ajax_test_environment']);
                add_action('wp_ajax_mlmm_quarantine_audit', [$this, 'ajax_quarantine_audit']);
                add_action('wp_ajax_mlmm_quarantine_move', [$this, 'ajax_quarantine_move']);
                add_action('wp_ajax_mlmm_quarantine_restore', [$this, 'ajax_quarantine_restore']);
                add_action('wp_ajax_mlmm_quarantine_clear', [$this, 'ajax_quarantine_clear']);
                add_action('wp_ajax_mlmm_quarantine_status', [$this, 'ajax_quarantine_status']);

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

                add_action('ngg_added_new_image', [$this, 'handle_nextgen_added_image'], 20, 1);
                add_filter('ngg_get_image_url', [$this, 'filter_nextgen_image_url'], 20, 3);
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
                        'enable_nextgen_hook'         => 1,
                        'batch_size'                  => 20,
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
                        $this->t('ML Media Optimizer', 'ML Media Optimizer', 'ML Media Optimizer'),
                        $this->t('ML Media Optimizer', 'ML Media Optimizer', 'ML Media Optimizer'),
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
                $handle_js = 'ml-media-master-admin-batch';

                wp_enqueue_style(
                        $handle_css,
                        plugin_dir_url(__FILE__) . 'assets/admin.css',
                        [],
                        self::VERSION
                );

                wp_enqueue_script(
                        $handle_js,
                        plugin_dir_url(__FILE__) . 'assets/admin-batch.js',
                        ['jquery'],
                        self::VERSION,
                        true
                );

                wp_localize_script(
                        $handle_js,
                        'MLMM_BATCH',
                        [
                                'ajaxUrl' => admin_url('admin-ajax.php'),
                                'nonce' => wp_create_nonce('mlmm_batch_nonce'),
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

                add_settings_field(
                        'enable_nextgen_hook',
                        $this->t('Ativar NextGEN / Imagely', 'Enable NextGEN / Imagely', 'Activar NextGEN / Imagely'),
                        [$this, 'render_checkbox_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'   => 'enable_nextgen_hook',
                                'label' => $this->t('Gerar .webp no fluxo do NextGEN/Imagely e servir .webp no frontend quando existir.', 'Generate .webp in the NextGEN/Imagely flow and serve .webp on the frontend when available.', 'Generar .webp en el flujo de NextGEN/Imagely y servir .webp en el frontend cuando exista.'),
                        ]
                );

                add_settings_field(
                        'batch_size',
                        $this->t('Itens por execução', 'Items per run', 'Elementos por ejecución'),
                        [$this, 'render_number_field'],
                        'ml-media-master',
                        'ml_media_master_main',
                        [
                                'key'         => 'batch_size',
                                'min'         => 1,
                                'max'         => 500,
                                'step'        => 1,
                                'description' => $this->t('Quantidade máxima de imagens por execução iniciada no painel. O lote roda em ciclos AJAX curtos até concluir esse limite.', 'Maximum number of images per run started from the panel. The batch runs in short AJAX cycles until this limit is reached.', 'Cantidad máxima de imágenes por ejecución iniciada desde el panel. El lote corre en ciclos AJAX cortos hasta alcanzar este límite.'),
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
                $output['enable_nextgen_hook']         = !empty($input['enable_nextgen_hook']) ? 1 : 0;

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

                if (!$this->is_full_license_active()) {
                        $output['batch_size'] = 5;
                } else {
                        $output['batch_size'] = isset($input['batch_size'])
                                ? max(1, min(500, (int) $input['batch_size']))
                                : (int) $current['batch_size'];
                }

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
                echo '<p>' . esc_html($this->t('Defina o comportamento do processamento automático de imagens no upload padrão do WordPress, nos uploads antigos, no HTML renderizado e no NextGEN/Imagely.', 'Set the behavior for automatic image processing on standard WordPress uploads, legacy uploads, rendered HTML and NextGEN/Imagely.', 'Define el comportamiento del procesamiento automático de imágenes en la carga estándar de WordPress, las cargas antiguas, el HTML renderizado y NextGEN/Imagely.')) . '</p>';
        }

        /**
         * @param array<string, mixed> $args
         */
        public function render_checkbox_field(array $args): void {
                $key   = (string) $args['key'];
                $label = isset($args['label']) ? (string) $args['label'] : '';
                $value = !empty($this->settings[$key]) ? 1 : 0;

                echo '<label>';
                echo '<input type="checkbox" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="1" ' . checked(1, $value, false) . ' />';
                echo ' ' . esc_html($label);
                echo '</label>';
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
                $is_locked   = ($key === 'batch_size' && !$this->is_full_license_active());

                if ($is_locked) {
                        $value = 5;
                }

                echo '<input type="number" class="regular-text' . ($is_locked ? ' mlmm-input-locked' : '') . '" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" step="' . esc_attr((string) $step) . '"' . ($is_locked ? ' readonly="readonly" disabled="disabled" aria-disabled="true"' : '') . ' />';
                if ($is_locked) {
                        echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY . '[' . $key . ']') . '" value="5" />';
                }
                if ($description !== '') {
                        $desc = $description;
                        if ($is_locked) {
                                $desc .= ' Na versão Free este campo fica travado em 5. Valide um serial Full para liberar a edição.';
                        }
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

        private function is_full_license_active(): bool {
                $state = $this->get_license_state();
                return !empty($state['valid']) && (string) ($state['status'] ?? '') === 'active';
        }

        private function get_effective_batch_size(): int {
                if (!$this->is_full_license_active()) {
                        return 5;
                }
                return max(1, (int) ($this->settings['batch_size'] ?? 20));
        }

        private function get_effective_ajax_chunk_size(int $run_limit): int {
                $chunk = 5;
                return max(1, min($chunk, max(1, $run_limit)));
        }

        private function merge_external_batch_controls(array &$job): void {
                if (empty($job['job_id'])) {
                        return;
                }

                $control = $this->get_batch_control_state((string) $job['job_id']);
                if (!is_array($control) || $control === []) {
                        return;
                }

                if (!empty($control['stop_requested'])) {
                        $job['stop_requested'] = true;
                        $job['status'] = 'stopping';
                        return;
                }

                if (!empty($control['pause_requested']) && (string) ($job['status'] ?? '') !== 'aborted') {
                        $job['status'] = 'paused';
                }
        }

        /**
         * @return array<string, mixed>
         */
        private function read_option_uncached(string $option_name, $default = []) {
                global $wpdb;

                wp_cache_delete($option_name, 'options');
                $autoloaded = wp_cache_get('alloptions', 'options');
                if (is_array($autoloaded) && array_key_exists($option_name, $autoloaded)) {
                        unset($autoloaded[$option_name]);
                        wp_cache_set('alloptions', $autoloaded, 'options');
                }

                $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name));
                if ($value === null) {
                        return $default;
                }

                return maybe_unserialize($value);
        }

        /**
         * @return array<string, mixed>
         */
        private function get_all_batch_control_states(): array {
                $all = $this->read_option_uncached(self::BATCH_CONTROL_OPTION, []);
                return is_array($all) ? $all : [];
        }

        /**
         * @return array<string, mixed>
         */
        private function get_batch_control_state(string $job_id): array {
                $all = $this->get_all_batch_control_states();
                $control = $all[$job_id] ?? [];
                return is_array($control) ? $control : [];
        }

        /**
         * @param array<string, mixed> $data
         */
        private function save_batch_control_state(string $job_id, array $data): void {
                if ($job_id === '') {
                        return;
                }
                $all = $this->get_all_batch_control_states();
                $current = isset($all[$job_id]) && is_array($all[$job_id]) ? $all[$job_id] : [];
                $all[$job_id] = array_merge($current, $data, ['updated_at' => current_time('mysql')]);
                update_option(self::BATCH_CONTROL_OPTION, $all, false);
        }

        private function clear_batch_control_state(string $job_id): void {
                if ($job_id === '') {
                        return;
                }
                $all = $this->get_all_batch_control_states();
                if (isset($all[$job_id])) {
                        unset($all[$job_id]);
                        update_option(self::BATCH_CONTROL_OPTION, $all, false);
                }
        }

        private function require_full_license(string $message = ''): void {
                if ($message === '') {
                        $message = $this->t('Recurso disponível apenas na versão Full.', 'Feature available only in the Full version.', 'Función disponible solo en la versión Full.');
                }

                if (!$this->is_full_license_active()) {
                        wp_send_json_error(['message' => $message], 403);
                }
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
                                'body' => [
                                        'action' => 'validate_license',
                                        'product_id' => self::PRODUCT_ID,
                                        'license_key' => $license_key,
                                        'domain' => $this->get_current_domain_for_license(),
                                        'site_url' => site_url(),
                                        'version' => self::VERSION,
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
                $body = wp_remote_retrieve_body($response);
                $data = json_decode((string) $body, true);

                if (!is_array($data)) {
                        return [
                                'valid' => false,
                                'status' => 'invalid_response',
                                'message' => 'Resposta inválida do servidor de licenças.',
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
                        $this->set_batch_notice(true, $this->t('Licença removida deste site. A versão Free continua ativa.', 'License removed from this site. The Free version remains active.', 'Licencia eliminada de este sitio. La versión Free sigue activa.'));
                        $this->redirect_to_plugin_page('licenca');
                }

                if ($action === 'validate_license') {
                        $license_key = isset($_POST['mlmm_license_key']) ? sanitize_text_field((string) $_POST['mlmm_license_key']) : '';
                        $result = $this->validate_license_remote($license_key);
                        $state = [
                                'mode' => !empty($result['valid']) ? 'full' : 'free',
                                'valid' => !empty($result['valid']),
                                'status' => isset($result['status']) ? (string) $result['status'] : 'invalid',
                                'message' => isset($result['message']) ? (string) $result['message'] : '',
                                'license_key' => $license_key,
                                'plan' => isset($result['plan']) ? (string) $result['plan'] : (!empty($result['valid']) ? 'full' : 'free'),
                                'domain' => isset($result['domain']) ? (string) $result['domain'] : $this->get_current_domain_for_license(),
                                'site_url' => site_url(),
                                'checked_at' => current_time('mysql'),
                                'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : '',
                                'grace_until' => isset($result['grace_until']) ? (string) $result['grace_until'] : '',
                        ];
                        $this->save_license_state($state);
                        $this->set_batch_notice(!empty($result['valid']), $state['message'] !== '' ? $state['message'] : 'Validação da licença concluída.');
                        $this->redirect_to_plugin_page('licenca');
                }
        }

        private function get_feature_matrix(): array {
                return [
                        ['label' => 'Conversão para WebP', 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => 'Lote pelo painel', 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => $this->t('Lote por ciclo', 'Batch per cycle', 'Lote por ciclo'), 'free' => '5 arquivos', 'full' => 'Configuração completa'],
                        ['label' => 'Auditoria e Quarentena', 'free' => $this->t('Não', 'No', 'No'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => $this->t('Resetar estado do lote', 'Reset batch state', 'Restablecer estado del lote'), 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => $this->t('Limpar relatório', 'Clear report', 'Limpiar informe'), 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                        ['label' => 'Fallback de URL antiga para WebP', 'free' => $this->t('Sim', 'Yes', 'Sí'), 'full' => $this->t('Sim', 'Yes', 'Sí')],
                ];
        }

        private function render_license_panel(): void {
                $state = $this->get_license_state();
                $full = $this->is_full_license_active();
                echo '<section class="mlmm-tab-panel" data-tab-panel="licenca">';
                echo '<div class="mlmm-grid mlmm-grid-2">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Licença</h2><p class="mlmm-muted">A versão Free funciona por padrão. Valide um serial para liberar todos os recursos da versão Full.</p></div></div>';
                echo '<p><strong>Produto:</strong> ML Media Optimizer</p>';
                echo '<p><strong>Product ID:</strong> ' . esc_html(self::PRODUCT_ID) . '</p>';
                echo '<p><strong>Domínio atual:</strong> ' . esc_html($this->get_current_domain_for_license()) . '</p>';
                echo '<p><strong>Plano atual:</strong> <span class="mlmm-chip">' . esc_html($full ? 'Full' : 'Free') . '</span></p>';
                echo '<p><strong>Status:</strong> ' . esc_html((string) ($state['status'] ?? 'free')) . '</p>';
                echo '<p><strong>Mensagem:</strong> ' . esc_html((string) ($state['message'] ?? '')) . '</p>';
                echo '<p><strong>Última validação:</strong> ' . esc_html((string) ($state['checked_at'] ?: 'ainda não validada')) . '</p>';
                if (!empty($state['expires_at'])) { echo '<p><strong>Expira em:</strong> ' . esc_html((string) $state['expires_at']) . '</p>'; }
                echo '<form method="post">';
                wp_nonce_field('mlmm_license_action_nonce');
                echo '<input type="hidden" name="mlmm_license_action" value="validate_license">';
                echo '<p><label for="mlmm-license-key"><strong>Serial / License Key</strong></label><br><input id="mlmm-license-key" type="text" name="mlmm_license_key" value="' . esc_attr((string) ($state['license_key'] ?? '')) . '" class="regular-text" style="width:100%;max-width:420px"></p>';
                echo '<p><button type="submit" class="button button-primary">Validar licença</button></p>';
                echo '</form>';
                if (!empty($state['license_key'])) {
                        echo '<form method="post" style="margin-top:12px;">';
                        wp_nonce_field('mlmm_license_action_nonce');
                        echo '<input type="hidden" name="mlmm_license_action" value="deactivate_license">';
                        echo '<p><button type="submit" class="button">Remover licença deste site</button></p>';
                        echo '</form>';
                }
                echo '</div>';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Planos</h2><p class="mlmm-muted">Resumo comercial do ML Media Optimizer.</p></div></div>';
                echo '<table class="widefat striped"><thead><tr><th>Recurso</th><th>Free</th><th>Full</th></tr></thead><tbody>';
                foreach ($this->get_feature_matrix() as $row) {
                        echo '<tr><td>' . esc_html((string) $row['label']) . '</td><td>' . esc_html((string) $row['free']) . '</td><td>' . esc_html((string) $row['full']) . '</td></tr>';
                }
                echo '</tbody></table>';
                echo '<p style="margin-top:12px;"><strong>Observação:</strong> a versão Free mantém o lote limitado a 5 arquivos por ciclo. Auditoria e Quarentena permanecem disponíveis apenas na versão Full.</p>';
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
                                'ML Media Optimizer ' . self::VERSION . ' instalado. Correções: toast de configurações salvo com sucesso. Melhorias: novidades da versão exibidas uma vez após atualizar.',
                                'ML Media Optimizer ' . self::VERSION . ' installed. Fixes: settings saved toast restored. Improvements: version news shown once after update.',
                                'ML Media Optimizer ' . self::VERSION . ' instalado. Correcciones: toast de configuración guardada restaurado. Mejoras: novedades de la versión mostradas una vez tras actualizar.'
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

                echo '<div class="wrap mlmm-admin-wrap">';
                echo '<div id="mlmm-toast-area" aria-live="polite" aria-atomic="true"></div>';
                $deferred_toast = $this->consume_admin_toast();
                if ($deferred_toast) {
                        echo '<div id="mlmm-deferred-toast" data-message="' . esc_attr($deferred_toast['message']) . '" data-type="' . esc_attr($deferred_toast['type']) . '" data-timeout="' . esc_attr((string) $deferred_toast['timeout']) . '"></div>';
                }
                $version_toast = $this->consume_version_toast();
                if ($version_toast) {
                        echo '<div id="mlmm-version-toast" data-message="' . esc_attr($version_toast['message']) . '" data-type="' . esc_attr($version_toast['type']) . '" data-timeout="' . esc_attr((string) $version_toast['timeout']) . '"></div>';
                }
                echo '<div class="mlmm-hero">';
                echo '<div class="mlmm-hero-copy">';
                echo '<h1>ML Media Optimizer <span class="mlmm-version-badge">v' . esc_html(self::VERSION) . '</span></h1>';
                echo '<p class="mlmm-intro">Console profissional para conversão WebP, lotes AJAX, quarentena segura e compatibilidade com URLs antigas em WordPress e NextGEN/Imagely.</p>';
                echo '</div>';
                echo '<div class="mlmm-hero-meta">';
                echo '<div class="mlmm-chip"><strong>Plano:</strong> ' . esc_html($this->is_full_license_active() ? 'Full' : 'Free') . '</div>';
                echo '<div class="mlmm-chip"><strong>Execução:</strong> Painel + AJAX</div>';
                echo '<div class="mlmm-chip"><strong>Compatibilidade:</strong> Uploads, Gallery e URLs legadas</div>';
                echo '</div>';
                echo '</div>';

                echo '<div class="mlmm-tab-nav" role="tablist" aria-label="Seções do ML Media Optimizer">';
                echo '<button type="button" class="mlmm-tab-button is-active" data-tab="dashboard" role="tab" aria-selected="true">Dashboard</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="lote" role="tab" aria-selected="false">Lote</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="auditoria" role="tab" aria-selected="false">Auditoria</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="relatorios" role="tab" aria-selected="false">Relatórios</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="configuracoes" role="tab" aria-selected="false">Configurações</button>';
                echo '<button type="button" class="mlmm-tab-button" data-tab="licenca" role="tab" aria-selected="false">Licença e Planos</button>';
                echo '</div>';

                echo '<div class="mlmm-tab-panels">';

                echo '<section class="mlmm-tab-panel is-active" data-tab-panel="dashboard">';
                echo '<div class="mlmm-summary-grid">';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Ambiente pronto</span><strong>' . esc_html($this->is_exec_available() && $this->binary_exists('convert') && $this->binary_exists('cwebp') ? $this->t('Sim', 'Yes', 'Sí') : 'Revisar') . '</strong><small>Validação rápida do motor principal</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Remoção segura</span><strong>' . (!empty($this->settings['delete_original']) ? 'Ativa' : 'Desativada') . '</strong><small>Originais só saem após validação do WebP</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Itens por execução</span><strong>' . esc_html((string) $this->get_effective_batch_size()) . '</strong><small>' . esc_html($this->is_full_license_active() ? $this->t('Limite total por execução iniciada no painel', 'Total limit per run started from the panel', 'Límite total por ejecución iniciada desde el panel') : $this->t('Limite da versão Free', 'Free version limit', 'Límite de la versión Free')) . '</small></div>';
                echo '<div class="mlmm-summary-card"><span class="mlmm-summary-label">Suporte legado</span><strong>' . (!empty($this->settings['serve_existing_webp_uploads']) ? 'Ativo' : 'Desativado') . '</strong><small>Uploads antigos e URLs diretas</small></div>';
                echo '</div>';
                echo '<div class="mlmm-grid mlmm-grid-2">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Progresso em tempo real</h2><p class="mlmm-muted">Acompanhe o job ativo, eventos recentes e contadores do lote.</p></div></div>';
                echo '<div id="mlmm-job-panel" data-job-status="idle">';
                echo '<div class="mlmm-status-row"><strong>Status:</strong> <span id="mlmm-job-status-label">Nenhum lote ativo.</span></div>';
                echo '<div class="mlmm-status-row"><strong>Escopo:</strong> <span id="mlmm-job-scope">-</span></div>';
                echo '<div class="mlmm-status-row"><strong>Item atual:</strong> <span id="mlmm-job-current-item">-</span></div>';
                echo '<div class="mlmm-progress"><div id="mlmm-progress-bar"></div></div>';
                echo '<div class="mlmm-counters">';
                echo '<div><span id="mlmm-count-total">0</span><small>Total</small></div>';
                echo '<div><span id="mlmm-count-processed">0</span><small>Processados</small></div>';
                echo '<div><span id="mlmm-count-converted">0</span><small>Convertidos</small></div>';
                echo '<div><span id="mlmm-count-existing">0</span><small>Já existentes</small></div>';
                echo '<div><span id="mlmm-count-skipped">0</span><small>Ignorados</small></div>';
                echo '<div><span id="mlmm-count-errors">0</span><small>Erros</small></div>';
                echo '<div><span id="mlmm-count-deleted">0</span><small>Originais apagados</small></div>';
                echo '</div>';
                echo '<div class="mlmm-maintenance-actions">';
                echo '<button type="button" class="button" id="mlmm-pause-job" disabled>Pausar</button> ';
                echo '<button type="button" class="button" id="mlmm-resume-job" disabled>Retomar</button> ';
                echo '<button type="button" class="button button-secondary" id="mlmm-stop-job" disabled>Parar</button>';
                echo '</div>';
                echo '<h3>Eventos recentes</h3>';
                echo '<textarea id="mlmm-recent-events" readonly="readonly"></textarea>';
                echo '</div>';
                echo '</div>';

                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Ambiente</h2><p class="mlmm-muted">Diagnóstico rápido das dependências do servidor e do diretório temporário.</p></div></div>';
                echo '<div id="mlmm-environment-table">';
                $this->render_environment_table();
                echo '</div>';
                echo '<p><button type="button" class="button button-secondary" id="mlmm-test-environment">Testar ambiente</button></p>';
                echo '</div>';
                echo '</div>';
                echo '</section>';

                echo '<section class="mlmm-tab-panel" data-tab-panel="lote">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Lote do acervo</h2><p class="mlmm-muted">Processamento em ciclos curtos no painel. O original só é apagado quando o .webp final existe, está legível e válido.</p></div></div>';
                echo '<div class="mlmm-inline-job-controls">';
                echo '<div class="mlmm-inline-job-meta">';
                echo '<strong>Status:</strong> <span id="mlmm-job-status-label-lote">Nenhum lote ativo.</span> ';
                echo '<span class="mlmm-inline-divider">•</span> ';
                echo '<strong>Escopo:</strong> <span id="mlmm-job-scope-lote">-</span> ';
                echo '<span class="mlmm-inline-divider">•</span> ';
                echo '<strong>Item atual:</strong> <span id="mlmm-job-current-item-lote">-</span>';
                echo '</div>';
                echo '<div class="mlmm-maintenance-actions">';
                echo '<button type="button" class="button" id="mlmm-pause-job-lote" disabled>Pausar</button> ';
                echo '<button type="button" class="button" id="mlmm-resume-job-lote" disabled>Retomar</button> ';
                echo '<button type="button" class="button button-secondary" id="mlmm-stop-job-lote" disabled>Parar</button>';
                echo '</div>';
                echo '</div>';
                echo '<div class="mlmm-batch-actions">';
                $this->render_batch_box($this->t('Uploads do WordPress', 'WordPress uploads', 'Cargas de WordPress'), 'uploads', $this->get_saved_batch_stats('uploads'));
                $this->render_batch_box($this->t('Gallery full', 'Gallery full', 'Galería full'), 'gallery_full', $this->get_saved_batch_stats('gallery_full'));
                $this->render_batch_box($this->t('Gallery thumbs', 'Gallery thumbs', 'Miniaturas de galería'), 'gallery_thumbs', $this->get_saved_batch_stats('gallery_thumbs'));
                echo '</div>';
                echo '<div class="mlmm-maintenance-actions">';
                echo '<button type="button" class="button" id="mlmm-refresh-stats">Atualizar pendências</button> ';
                echo '<button type="button" class="button" id="mlmm-reset-job">Resetar estado do lote</button> ';
                echo '<button type="button" class="button" id="mlmm-clear-report">Limpar relatório</button>'; 
                echo '</div>';
                echo '<div id="mlmm-lote-warning" class="mlmm-lote-warning" style="display:none"></div>';
                echo '</div>';
                echo '</section>';

                echo '<section class="mlmm-tab-panel" data-tab-panel="auditoria">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Auditoria e Quarentena de Originais</h2><p class="mlmm-muted">Localize originais JPG/JPEG/PNG com .webp válido correspondente, mova para quarentena com segurança, restaure se necessário e limpe definitivamente depois da validação do site.</p></div></div>';
                echo '<div class="mlmm-grid mlmm-grid-2">';
                echo '<div>';
                echo '<div class="mlmm-maintenance-actions">';
                echo '<button type="button" class="button button-secondary mlmm-quarantine-audit" data-scope="uploads"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Auditar uploads</button> ';
                echo '<button type="button" class="button button-secondary mlmm-quarantine-audit" data-scope="gallery_full"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Auditar gallery full</button> ';
                echo '<button type="button" class="button button-secondary mlmm-quarantine-audit" data-scope="gallery_thumbs"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Auditar gallery thumbs</button> ';
                echo '<button type="button" class="button button-primary mlmm-quarantine-audit" data-scope="all"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Auditar tudo</button>'; 
                echo '</div>';
                echo '<div class="mlmm-maintenance-actions">';
                echo '<button type="button" class="button" id="mlmm-quarantine-move"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Mover candidatos para quarentena</button> ';
                echo '<button type="button" class="button" id="mlmm-quarantine-restore"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Restaurar quarentena</button> ';
                echo '<button type="button" class="button" id="mlmm-quarantine-clear"' . (!$this->is_full_license_active() ? ' disabled="disabled"' : '') . '>Limpar quarentena</button> ';
                echo '<button type="button" class="button" id="mlmm-quarantine-refresh">Atualizar status da quarentena</button>';
                echo '</div>';
                echo '<div id="mlmm-quarantine-audit-panel">';
                $this->render_quarantine_audit_panel();
                echo '</div>';
                echo '</div>';
                echo '<div id="mlmm-quarantine-status-panel">';
                $this->render_quarantine_status_panel();
                echo '</div>';
                echo '</div>';
                echo '</div>';
                echo '</section>';

                echo '<section class="mlmm-tab-panel" data-tab-panel="relatorios">';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Último relatório</h2><p class="mlmm-muted">Resumo final do último job concluído, com eventos recentes e erros.</p></div></div>';
                echo '<div id="mlmm-report-panel">';
                $this->render_last_batch_report();
                echo '</div>';
                echo '</div>';
                echo '</section>';

                echo '<section class="mlmm-tab-panel" data-tab-panel="configuracoes">';
                echo '<div class="mlmm-grid">';
                echo '<div class="mlmm-card mlmm-card-form">';
                echo '<div class="mlmm-card-header"><div><h2>Configurações</h2><p class="mlmm-muted">Parâmetros do motor de conversão, comportamento do upload e integração com frontend.</p></div></div>';
                echo '<form method="post" action="options.php">';
                settings_fields('ml_media_master_group');
                do_settings_sections('ml-media-master');
                submit_button($this->t('Salvar configurações', 'Save settings', 'Guardar configuración'));
                echo '</form>';
                echo '</div>';
                echo '<div class="mlmm-card">';
                echo '<div class="mlmm-card-header"><div><h2>Boas práticas</h2><p class="mlmm-muted">Checklist rápido para operação segura em produção.</p></div></div>';
                echo '<ul class="mlmm-checklist">';
                echo '<li>Rode o lote por escopo e acompanhe os eventos recentes.</li>';
                echo '<li>Use auditoria + quarentena antes de limpeza definitiva de originais antigos.</li>';
                echo '<li>Valide URLs diretas antigas quando o acervo tiver muitos links legados.</li>';
                echo '<li>Depois de grandes mudanças, clique em Atualizar pendências para reidratar os cards.</li>';
                echo '<li>Mantenha convert, identify e cwebp disponíveis no ambiente web/FPM.</li>';
                echo '</ul>';
                echo '</div>';
                echo '</div>';
                echo '</section>';

                $this->render_license_panel();
                echo '</div>';
                echo '</div>';
        }

        /**
         * @param array<string, int|float|string> $stats
         */

        private function render_batch_box(string $title, string $scope, array $stats): void {
                $total      = isset($stats['total']) ? (int) $stats['total'] : 0;
                $converted  = isset($stats['converted']) ? (int) $stats['converted'] : 0;
                $pending    = isset($stats['pending']) ? (int) $stats['pending'] : 0;
                $progress   = isset($stats['progress']) ? (float) $stats['progress'] : 0.0;
                $updated_at = isset($stats['updated_at']) ? (string) $stats['updated_at'] : '';
                $base_label = isset($stats['base_label']) ? (string) $stats['base_label'] : '';

                echo '<div class="mlmm-scope-card" data-scope="' . esc_attr($scope) . '">';
                echo '<h3>' . esc_html($title) . '</h3>';
                if ($base_label !== '') {
                        echo '<p><strong>Origem:</strong> ' . esc_html($base_label) . '</p>';
                }
                echo '<p><strong>Total:</strong> <span class="mlmm-stat-total">' . esc_html((string) $total) . '</span> | <strong>Convertidos:</strong> <span class="mlmm-stat-converted">' . esc_html((string) $converted) . '</span> | <strong>Pendentes:</strong> <span class="mlmm-stat-pending">' . esc_html((string) $pending) . '</span></p>';
                echo '<p><strong>Última atualização:</strong> <span class="mlmm-stat-updated">' . esc_html($updated_at !== '' ? $updated_at : $this->t('ainda não calculada', 'not calculated yet', 'aún no calculada')) . '</span></p>';
                echo '<div class="mlmm-mini-progress"><div style="width:' . esc_attr((string) $progress) . '%"></div></div>';
                echo '<p><strong>Progresso:</strong> <span class="mlmm-stat-progress">' . esc_html(number_format_i18n($progress, 2)) . '</span>%</p>';
                echo '<p><button type="button" class="button button-primary mlmm-start-batch" data-scope="' . esc_attr($scope) . '">Iniciar lote</button></p>';
                echo '</div>';
        }

        private function render_environment_table(): void {
                echo '<table class="widefat striped"><tbody>';
                echo '<tr><td><strong>exec()</strong></td><td>' . esc_html($this->is_exec_available() ? $this->t('Disponível', 'Available', 'Disponible') : $this->t('Indisponível', 'Unavailable', 'No disponible')) . '</td></tr>';
                echo '<tr><td><strong>convert</strong></td><td>' . esc_html($this->binary_exists('convert') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>identify</strong></td><td>' . esc_html($this->binary_exists('identify') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>cwebp</strong></td><td>' . esc_html($this->binary_exists('cwebp') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>jpegoptim</strong></td><td>' . esc_html($this->binary_exists('jpegoptim') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>jpegtran</strong></td><td>' . esc_html($this->binary_exists('jpegtran') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>pngquant</strong></td><td>' . esc_html($this->binary_exists('pngquant') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>optipng</strong></td><td>' . esc_html($this->binary_exists('optipng') ? 'OK' : $this->t('Não encontrado', 'Not found', 'No encontrado')) . '</td></tr>';
                echo '<tr><td><strong>Diretório temporário</strong></td><td>' . esc_html(is_writable(get_temp_dir()) ? $this->t('Gravável', 'Writable', 'Grabable') : $this->t('Não gravável', 'Not writable', 'No grabable')) . '</td></tr>';
                echo '</tbody></table>';
        }

        /**
         * @return array<string, int|float|string|bool>
         */
        private function get_empty_batch_stats(string $scope): array {
                $base_label = '';

                switch ($scope) {
                        case 'uploads':
                                $base_label = WP_CONTENT_DIR . '/uploads';
                                break;
                        case 'gallery_full':
                                $base_label = WP_CONTENT_DIR . '/gallery (sem thumbs)';
                                break;
                        case 'gallery_thumbs':
                                $base_label = WP_CONTENT_DIR . '/gallery/thumbs';
                                break;
                }

                return [
                        'total'      => 0,
                        'converted'  => 0,
                        'pending'    => 0,
                        'progress'   => 0.0,
                        'base_label' => $base_label,
                        'updated_at' => '',
                        'is_stale'   => true,
                ];
        }

        /**
         * @return array<string, int|float|string|bool>
         */
        private function get_saved_batch_stats(string $scope): array {
                $all_stats = get_option(self::BATCH_STATS_OPTION, []);
                $default = $this->get_empty_batch_stats($scope);

                if (!is_array($all_stats) || !isset($all_stats[$scope]) || !is_array($all_stats[$scope])) {
                        return $default;
                }

                $saved = $all_stats[$scope];

                return [
                        'total'      => isset($saved['total']) ? (int) $saved['total'] : (int) $default['total'],
                        'converted'  => isset($saved['converted']) ? (int) $saved['converted'] : (int) $default['converted'],
                        'pending'    => isset($saved['pending']) ? (int) $saved['pending'] : (int) $default['pending'],
                        'progress'   => isset($saved['progress']) ? (float) $saved['progress'] : (float) $default['progress'],
                        'base_label' => isset($saved['base_label']) ? (string) $saved['base_label'] : (string) $default['base_label'],
                        'updated_at' => isset($saved['updated_at']) ? (string) $saved['updated_at'] : '',
                        'is_stale'   => isset($saved['is_stale']) ? (bool) $saved['is_stale'] : true,
                ];
        }

        /**
         * @param array<string, int|float|string|bool> $stats
         */
        private function save_batch_stats(string $scope, array $stats): void {
                $all_stats = get_option(self::BATCH_STATS_OPTION, []);
                if (!is_array($all_stats)) {
                        $all_stats = [];
                }

                $default = $this->get_empty_batch_stats($scope);

                $all_stats[$scope] = [
                        'total'      => isset($stats['total']) ? (int) $stats['total'] : (int) $default['total'],
                        'converted'  => isset($stats['converted']) ? (int) $stats['converted'] : (int) $default['converted'],
                        'pending'    => isset($stats['pending']) ? (int) $stats['pending'] : (int) $default['pending'],
                        'progress'   => isset($stats['progress']) ? (float) $stats['progress'] : (float) $default['progress'],
                        'base_label' => isset($stats['base_label']) ? (string) $stats['base_label'] : (string) $default['base_label'],
                        'updated_at' => isset($stats['updated_at']) ? (string) $stats['updated_at'] : current_time('mysql'),
                        'is_stale'   => isset($stats['is_stale']) ? (bool) $stats['is_stale'] : true,
                ];

                update_option(self::BATCH_STATS_OPTION, $all_stats, false);
        }

        private function mark_batch_stats_stale(string $scope): void {
                $stats = $this->get_saved_batch_stats($scope);
                $stats['is_stale'] = true;
                $this->save_batch_stats($scope, $stats);
        }

        private function request_batch_stop(string $scope): void {
                $flags = get_option(self::BATCH_STOP_OPTION, []);
                if (!is_array($flags)) {
                        $flags = [];
                }

                $flags[$scope] = current_time('mysql');
                update_option(self::BATCH_STOP_OPTION, $flags, false);
        }

        private function clear_batch_stop(string $scope): void {
                $flags = get_option(self::BATCH_STOP_OPTION, []);
                if (!is_array($flags) || !isset($flags[$scope])) {
                        return;
                }

                unset($flags[$scope]);
                update_option(self::BATCH_STOP_OPTION, $flags, false);
        }

        private function is_batch_stop_requested(string $scope): bool {
                $flags = get_option(self::BATCH_STOP_OPTION, []);

                return is_array($flags) && !empty($flags[$scope]);
        }

        private function render_last_batch_report(): void {
                $report = get_option(self::BATCH_REPORT_OPTION, []);
                if (!is_array($report) || empty($report['scope'])) {
                        echo '<p><em>Nenhum relatório disponível.</em></p>';
                        return;
                }

                $scope = isset($report['scope']) ? (string) $report['scope'] : '';
                $status = isset($report['status']) ? (string) $report['status'] : 'finished';
                $generated_at = isset($report['generated_at']) ? (string) $report['generated_at'] : '';
                $started_at = isset($report['started_at']) ? (string) $report['started_at'] : '';
                $duration = isset($report['duration_seconds']) ? (int) $report['duration_seconds'] : 0;
                $totals = [
                        'total' => isset($report['total']) ? (int) $report['total'] : 0,
                        'processed' => isset($report['processed']) ? (int) $report['processed'] : 0,
                        'converted' => isset($report['converted']) ? (int) $report['converted'] : 0,
                        'existing' => isset($report['already_exists']) ? (int) $report['already_exists'] : 0,
                        'skipped' => isset($report['skipped']) ? (int) $report['skipped'] : 0,
                        'errors' => isset($report['errors']) ? (int) $report['errors'] : 0,
                        'deleted' => isset($report['deleted_originals']) ? (int) $report['deleted_originals'] : 0,
                ];
                $recent = isset($report['recent_events']) && is_array($report['recent_events']) ? $report['recent_events'] : [];
                $error_files = isset($report['error_files']) && is_array($report['error_files']) ? $report['error_files'] : [];

                echo '<div class="mlmm-report-summary">';
                echo '<p><strong>Escopo:</strong> ' . esc_html($scope) . '</p>';
                echo '<p><strong>Status final:</strong> ' . esc_html($status) . '</p>';
                if ($started_at !== '') {
                        echo '<p><strong>Início:</strong> ' . esc_html($started_at) . '</p>';
                }
                if ($generated_at !== '') {
                        echo '<p><strong>Fim:</strong> ' . esc_html($generated_at) . '</p>';
                }
                echo '<p><strong>Duração:</strong> ' . esc_html((string) $duration) . ' s</p>';
                echo '<p><strong>Total:</strong> ' . esc_html((string) $totals['total']) . ' | <strong>Processados:</strong> ' . esc_html((string) $totals['processed']) . ' | <strong>Convertidos:</strong> ' . esc_html((string) $totals['converted']) . ' | <strong>Já existentes:</strong> ' . esc_html((string) $totals['existing']) . ' | <strong>Ignorados:</strong> ' . esc_html((string) $totals['skipped']) . ' | <strong>Erros:</strong> ' . esc_html((string) $totals['errors']) . ' | <strong>Originais apagados:</strong> ' . esc_html((string) $totals['deleted']) . '</p>';
                echo '</div>';

                $this->render_batch_report_list('Eventos recentes', $recent);
                $this->render_batch_report_list('Erros', $error_files);
        }

        /**
         * @param string[] $items
         */
        private function render_batch_report_list(string $title, array $items): void {
                echo '<h3 style="margin-bottom:8px;">' . esc_html($title) . ' (' . esc_html((string) count($items)) . ')</h3>';

                if (empty($items)) {
                        echo '<p style="margin-top:0;"><em>Nenhum item.</em></p>';
                        return;
                }

                echo '<textarea readonly="readonly" style="width:100%; min-height:120px; font-family:monospace;">';
                echo esc_textarea(implode("\n", array_map('strval', $items)));
                echo '</textarea>';
        }

        public function render_batch_notice(): void {
                if (!is_admin() || !current_user_can('manage_options')) {
                        return;
                }

                $notice = get_transient(self::BATCH_NOTICE_TRANSIENT);
                if (!is_array($notice) || empty($notice['message'])) {
                        return;
                }

                delete_transient(self::BATCH_NOTICE_TRANSIENT);

                $class = !empty($notice['success']) ? 'notice notice-success' : 'notice notice-error';
                echo '<div class="' . esc_attr($class) . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
        }


        public function maybe_run_batch_job(): void {
                if (!is_admin() || !current_user_can('manage_options')) {
                        return;
                }

                if (
                        empty($_POST['ml_media_master_batch_run']) &&
                        empty($_POST['ml_media_master_refresh_stats']) &&
                        empty($_POST['ml_media_master_stop_batch'])
                ) {
                        return;
                }

                check_admin_referer('ml_media_master_batch_action', 'ml_media_master_batch_nonce');

                $scope = isset($_POST['ml_media_master_batch_scope']) ? sanitize_text_field((string) $_POST['ml_media_master_batch_scope']) : '';
                if ($scope === '') {
                        $this->set_batch_notice(false, 'Escopo do lote não informado.');
                        $this->redirect_to_plugin_page();
                }

                $valid_scopes = ['uploads', 'gallery_full', 'gallery_thumbs'];
                if (!in_array($scope, $valid_scopes, true)) {
                        $this->set_batch_notice(false, 'Escopo do lote inválido.');
                        $this->redirect_to_plugin_page();
                }

                $this->settings = $this->get_settings();

                if (!empty($_POST['ml_media_master_refresh_stats'])) {
                        $stats = $this->get_batch_stats($scope);
                        $stats['updated_at'] = current_time('mysql');
                        $stats['is_stale'] = false;
                        $this->save_batch_stats($scope, $stats);
                        $this->set_batch_notice(true, 'Estatísticas de "' . $scope . '" atualizadas com sucesso.');
                        $this->redirect_to_plugin_page();
                }

                if (!empty($_POST['ml_media_master_stop_batch'])) {
                        $this->request_batch_stop($scope);
                        $this->set_batch_notice(true, 'Solicitação de parada registrada para o escopo "' . $scope . '".');
                        $this->redirect_to_plugin_page();
                }

                if (!$this->is_exec_available() || !$this->binary_exists('convert') || !$this->binary_exists('cwebp')) {
                        $this->set_batch_notice(false, 'Não foi possível executar o lote: exec(), convert ou cwebp indisponível.');
                        $this->redirect_to_plugin_page();
                }

                $batch_size = isset($this->settings['batch_size']) ? (int) $this->settings['batch_size'] : 20;
                if ($batch_size < 1) {
                        $batch_size = 20;
                }

                $this->clear_batch_stop($scope);
                $result = $this->run_batch_scope($scope, $batch_size);
                $this->mark_batch_stats_stale($scope);

                $message = sprintf(
                        'Lote "%s" concluído. Convertidos agora: %d | Já existentes: %d | Ignorados: %d | Erros: %d',
                        $scope,
                        (int) $result['converted_now'],
                        (int) $result['existing'],
                        (int) $result['skipped'],
                        (int) $result['errors']
                );

                update_option(
                        self::BATCH_REPORT_OPTION,
                        [
                                'scope'           => $scope,
                                'generated_at'    => current_time('mysql'),
                                'converted_files' => isset($result['converted_files']) && is_array($result['converted_files']) ? array_values($result['converted_files']) : [],
                                'existing_files'  => isset($result['existing_files']) && is_array($result['existing_files']) ? array_values($result['existing_files']) : [],
                                'skipped_files'   => isset($result['skipped_files']) && is_array($result['skipped_files']) ? array_values($result['skipped_files']) : [],
                                'error_files'     => isset($result['error_files']) && is_array($result['error_files']) ? array_values($result['error_files']) : [],
                        ],
                        false
                );

                $this->set_batch_notice(((int) $result['errors'] === 0), $message . ' Atualize as estatísticas manualmente quando quiser recalcular os números do acervo.');
                $this->redirect_to_plugin_page();
        }



        private function ajax_capability_check(): void {
                if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'Permissão negada.'], 403);
                }

                check_ajax_referer('mlmm_batch_nonce', 'nonce');
                $this->settings = $this->get_settings();
        }

        public function ajax_refresh_stats(): void {
                $this->ajax_capability_check();
                $data = [];
                foreach (['uploads', 'gallery_full', 'gallery_thumbs'] as $scope) {
                        $stats = $this->get_batch_stats($scope);
                        $stats['updated_at'] = current_time('mysql');
                        $stats['is_stale'] = false;
                        $this->save_batch_stats($scope, $stats);
                        $data[$scope] = $stats;
                }
                wp_send_json_success(['stats' => $data]);
        }

        /**
         * @return array<string, array<string, int|float|string|bool>>
         */
        private function rebuild_all_scope_stats(): array {
                $data = [];
                foreach (['uploads', 'gallery_full', 'gallery_thumbs'] as $scope) {
                        $stats = $this->get_batch_stats($scope);
                        $stats['updated_at'] = current_time('mysql');
                        $stats['is_stale'] = false;
                        $this->save_batch_stats($scope, $stats);
                        $data[$scope] = $stats;
                }
                return $data;
        }

        public function ajax_test_environment(): void {
                $this->ajax_capability_check();
                ob_start();
                $this->render_environment_table();
                $html = (string) ob_get_clean();
                wp_send_json_success(['html' => $html]);
        }

        public function ajax_start_batch(): void {
                $this->ajax_capability_check();

                $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : '';
                if (!in_array($scope, ['uploads', 'gallery_full', 'gallery_thumbs'], true)) {
                        wp_send_json_error(['message' => 'Escopo inválido.'], 400);
                }

                $job = $this->get_batch_job();
                if (!empty($job['status']) && in_array($job['status'], ['starting', 'running', 'paused', 'stopping'], true)) {
                        wp_send_json_error(['message' => 'Já existe um lote ativo.'], 409);
                }

                if (!$this->is_exec_available() || !$this->binary_exists('convert') || !$this->binary_exists('cwebp')) {
                        wp_send_json_error(['message' => 'Dependências indisponíveis: exec(), convert ou cwebp.'], 500);
                }

                $probe = [
                        'existing' => 0,
                        'skipped' => 0,
                        'errors' => 0,
                        'existing_files' => [],
                        'skipped_files' => [],
                        'error_files' => [],
                ];
                $run_limit = $this->get_effective_batch_size();
                $queue = $this->collect_batch_candidates($scope, $probe, $run_limit, true);
                $queue = array_values($queue);
                $total = count($queue);

                $job = [
                        'job_id' => wp_generate_uuid4(),
                        'scope' => $scope,
                        'status' => 'running',
                        'created_at' => current_time('mysql'),
                        'started_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                        'finished_at' => '',
                        'total' => $total,
                        'processed' => 0,
                        'converted' => 0,
                        'already_exists' => 0,
                        'skipped' => 0,
                        'errors' => 0,
                        'deleted_originals' => 0,
                        'pointer' => 0,
                        'queue' => array_values($queue),
                        'current_item' => '',
                        'recent_events' => [],
                        'error_files' => [],
                        'stop_requested' => false,
                        'lock' => [],
                        'site_url' => site_url(),
                        'plugin_version' => self::VERSION,
                        'batch_size' => $this->get_effective_ajax_chunk_size($run_limit),
                        'run_limit' => $run_limit,
                        'time_budget' => 8,
                ];

                $this->save_batch_job($job);
                $this->save_batch_control_state((string) $job['job_id'], ['pause_requested' => false, 'stop_requested' => false]);
                $this->mark_batch_stats_stale($scope);

                wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
        }

        public function ajax_process_batch(): void {
                $this->ajax_capability_check();

                $job = $this->get_batch_job();
                if (empty($job['job_id'])) {
                        wp_send_json_error(['message' => 'Nenhum lote ativo.'], 404);
                }

                if (!in_array((string) $job['status'], ['running', 'stopping'], true)) {
                        wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
                }

                if (!$this->acquire_batch_lock($job)) {
                        wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
                }

                $this->merge_external_batch_controls($job);
                if ((string) ($job['status'] ?? '') === 'paused') {
                        $this->release_batch_lock($job);
                        $this->save_batch_job($job);
                        wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
                }

                $start = microtime(true);
                $limit = max(1, (int) ($job['batch_size'] ?? 20));
                $time_budget = max(2, (int) ($job['time_budget'] ?? 8));
                $processed_now = 0;
                $queue = isset($job['queue']) && is_array($job['queue']) ? array_values($job['queue']) : [];
                $total_queue = count($queue);

                while (($job['pointer'] ?? 0) < $total_queue && $processed_now < $limit) {
                        if ((microtime(true) - $start) >= $time_budget) {
                                break;
                        }

                        $this->merge_external_batch_controls($job);
                        if (in_array((string) ($job['status'] ?? ''), ['paused', 'pausing'], true)) {
                                break;
                        }
                        if (!empty($job['stop_requested'])) {
                                $job['status'] = 'stopping';
                                break;
                        }

                        $index = (int) $job['pointer'];
                        $input = isset($queue[$index]) ? (string) $queue[$index] : '';
                        $job['current_item'] = $this->make_path_relative_for_report($input);
                        $job['updated_at'] = current_time('mysql');

                        if ($input === '') {
                                $job['pointer'] = $index + 1;
                                continue;
                        }

                        $output = preg_replace('/\.(jpe?g|png)$/i', '.webp', $input);
                        if (!$output) {
                                $job['errors']++;
                                $this->append_recent_event($job, 'Erro: saída inválida para ' . $this->make_path_relative_for_report($input));
                                $job['error_files'][] = $this->make_path_relative_for_report($input) . ' | saída inválida';
                                $job['pointer'] = $index + 1;
                                $job['processed']++;
                                $processed_now++;
                                continue;
                        }

                        $conversion = $this->convert_existing_file_to_webp_targeted($input, $output, true);

                        if (!empty($conversion['already_existed'])) {
                                $job['already_exists']++;
                                $this->append_recent_event($job, 'Já existia: ' . $this->make_path_relative_for_report($output));
                        } elseif (!empty($conversion['success'])) {
                                $job['converted']++;
                                if (!empty($conversion['deleted_original'])) {
                                        $job['deleted_originals']++;
                                        $this->append_recent_event($job, 'Convertido e apagado original: ' . $this->make_path_relative_for_report($input));
                                } else {
                                        $this->append_recent_event($job, 'Convertido: ' . $this->make_path_relative_for_report($input));
                                }
                        } elseif (!empty($conversion['skipped'])) {
                                $job['skipped']++;
                                $this->append_recent_event($job, 'Ignorado: ' . $this->make_path_relative_for_report($input));
                        } else {
                                $job['errors']++;
                                $job['error_files'][] = $this->make_path_relative_for_report($input) . ' | ' . (string) ($conversion['status'] ?? 'erro');
                                $this->append_recent_event($job, 'Erro: ' . $this->make_path_relative_for_report($input));
                                @unlink($output);
                        }

                        $job['pointer'] = $index + 1;
                        $job['processed']++;
                        $processed_now++;

                        if (!empty($job['stop_requested'])) {
                                $job['status'] = 'stopping';
                                break;
                        }
                }

                $this->merge_external_batch_controls($job);

                if (($job['pointer'] ?? 0) >= $total_queue) {
                        $job['status'] = !empty($job['stop_requested']) ? 'aborted' : 'finished';
                        $job['finished_at'] = current_time('mysql');
                        $job['current_item'] = '';
                        $this->finalize_batch_job($job);
                } elseif (in_array((string) ($job['status'] ?? ''), ['paused', 'pausing'], true)) {
                        $job['status'] = 'paused';
                        $job['current_item'] = '';
                        $this->save_batch_job($job);
                } elseif (!empty($job['stop_requested'])) {
                        $job['status'] = 'aborted';
                        $job['finished_at'] = current_time('mysql');
                        $job['current_item'] = '';
                        $this->finalize_batch_job($job);
                } else {
                        $this->save_batch_job($job);
                }

                $this->release_batch_lock($job);
                $this->save_batch_job($job);

                wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
        }

        public function ajax_get_batch_status(): void {
                $this->ajax_capability_check();
                $job = $this->get_batch_job();
                if (!empty($job['job_id'])) {
                        $this->merge_external_batch_controls($job);
                        if ((string) ($job['status'] ?? '') === 'pausing' && !$this->is_batch_lock_active($job)) {
                                $job['status'] = 'paused';
                                $job['current_item'] = '';
                                $this->save_batch_job($job);
                        }
                }
                wp_send_json_success([
                        'job' => $this->prepare_batch_status_payload($job),
                        'report_html' => $this->capture_report_html(),
                ]);
        }

        public function ajax_pause_batch(): void {
                $this->ajax_capability_check();
                $job = $this->get_batch_job();
                if (!empty($job['job_id']) && in_array((string) ($job['status'] ?? ''), ['running', 'starting'], true)) {
                        $this->save_batch_control_state((string) $job['job_id'], ['pause_requested' => true, 'stop_requested' => false]);
                        $job['status'] = 'pausing';
                        $job['updated_at'] = current_time('mysql');
                        $this->append_recent_event($job, 'Pausa solicitada.');
                        $this->save_batch_job($job);
                }
                wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
        }

        public function ajax_resume_batch(): void {
                $this->ajax_capability_check();
                $job = $this->get_batch_job();
                if (!empty($job['job_id']) && in_array((string) ($job['status'] ?? ''), ['paused', 'pausing', 'error', 'stopping'], true)) {
                        $this->save_batch_control_state((string) $job['job_id'], ['pause_requested' => false, 'stop_requested' => false]);
                        $job['status'] = 'running';
                        $job['updated_at'] = current_time('mysql');
                        $job['stop_requested'] = false;
                        $this->append_recent_event($job, 'Lote retomado.');
                        $this->save_batch_job($job);
                }
                wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
        }

        public function ajax_stop_batch(): void {
                $this->ajax_capability_check();
                $job = $this->get_batch_job();
                if (!empty($job['job_id']) && in_array((string) ($job['status'] ?? ''), ['running', 'paused', 'pausing', 'starting', 'stopping'], true)) {
                        $this->save_batch_control_state((string) $job['job_id'], ['stop_requested' => true, 'pause_requested' => false]);
                        $job['stop_requested'] = true;
                        $job['status'] = 'stopping';
                        $job['updated_at'] = current_time('mysql');
                        $this->append_recent_event($job, 'Parada solicitada.');
                        $this->save_batch_job($job);
                }
                wp_send_json_success(['job' => $this->prepare_batch_status_payload($job)]);
        }

        public function ajax_reset_batch_state(): void {
                $this->ajax_capability_check();
                $job = $this->get_batch_job();
                if (!empty($job['job_id'])) {
                        $this->clear_batch_control_state((string) $job['job_id']);
                }
                delete_option(self::BATCH_JOB_OPTION);
                wp_send_json_success([
                        'report_html' => $this->capture_report_html(),
                        'stats' => $this->rebuild_all_scope_stats(),
                ]);
        }

        public function ajax_clear_report(): void {
                $this->ajax_capability_check();
                delete_option(self::BATCH_REPORT_OPTION);
                wp_send_json_success([
                        'report_html' => $this->capture_report_html(),
                        'stats' => $this->rebuild_all_scope_stats(),
                ]);
        }

        /**
         * @return array<string, mixed>
         */
        private function get_batch_job(): array {
                $job = $this->read_option_uncached(self::BATCH_JOB_OPTION, []);
                return is_array($job) ? $job : [];
        }

        private function save_batch_job(array $job): void {
                update_option(self::BATCH_JOB_OPTION, $job, false);
        }

        private function append_recent_event(array &$job, string $message): void {
                if (!isset($job['recent_events']) || !is_array($job['recent_events'])) {
                        $job['recent_events'] = [];
                }
                array_unshift($job['recent_events'], current_time('H:i:s') . ' - ' . $message);
                $job['recent_events'] = array_slice(array_values($job['recent_events']), 0, 20);
        }


        private function is_batch_lock_active(array $job): bool {
                if (empty($job['lock']) || !is_array($job['lock'])) {
                        return false;
                }

                $expires = isset($job['lock']['expires_at']) ? strtotime((string) $job['lock']['expires_at']) : 0;
                return $expires > time();
        }

        private function acquire_batch_lock(array &$job): bool {
                $now = time();
                $lock = isset($job['lock']) && is_array($job['lock']) ? $job['lock'] : [];
                $expires = isset($lock['expires_at']) ? (int) $lock['expires_at'] : 0;
                if ($expires > $now) {
                        return false;
                }
                $job['lock'] = [
                        'token' => wp_generate_password(12, false, false),
                        'acquired_at' => $now,
                        'expires_at' => $now + self::BATCH_LOCK_TTL,
                ];
                $this->save_batch_job($job);
                return true;
        }

        private function release_batch_lock(array &$job): void {
                $job['lock'] = [];
        }

        private function finalize_batch_job(array &$job): void {
                $started = !empty($job['started_at']) ? strtotime((string) $job['started_at']) : time();
                $finished = !empty($job['finished_at']) ? strtotime((string) $job['finished_at']) : time();
                $report = [
                        'job_id' => (string) ($job['job_id'] ?? ''),
                        'scope' => (string) ($job['scope'] ?? ''),
                        'status' => (string) ($job['status'] ?? 'finished'),
                        'started_at' => (string) ($job['started_at'] ?? ''),
                        'generated_at' => (string) ($job['finished_at'] ?? current_time('mysql')),
                        'duration_seconds' => max(0, $finished - $started),
                        'total' => (int) ($job['total'] ?? 0),
                        'processed' => (int) ($job['processed'] ?? 0),
                        'converted' => (int) ($job['converted'] ?? 0),
                        'already_exists' => (int) ($job['already_exists'] ?? 0),
                        'skipped' => (int) ($job['skipped'] ?? 0),
                        'errors' => (int) ($job['errors'] ?? 0),
                        'deleted_originals' => (int) ($job['deleted_originals'] ?? 0),
                        'recent_events' => isset($job['recent_events']) && is_array($job['recent_events']) ? array_values($job['recent_events']) : [],
                        'error_files' => isset($job['error_files']) && is_array($job['error_files']) ? array_slice(array_values($job['error_files']), 0, 200) : [],
                ];
                update_option(self::BATCH_REPORT_OPTION, $report, false);

                $stats = $this->get_empty_batch_stats((string) ($job['scope'] ?? 'uploads'));
                $remaining = max(0, ((int) ($job['total'] ?? 0)) - ((int) ($job['processed'] ?? 0)));
                $converted = max(0, (int) ($job['converted'] ?? 0) + (int) ($job['already_exists'] ?? 0));
                $stats['total'] = (int) ($job['total'] ?? 0) + (int) ($job['already_exists'] ?? 0) + (int) ($job['skipped'] ?? 0);
                $stats['converted'] = $converted;
                $stats['pending'] = $remaining;
                $stats['progress'] = $stats['total'] > 0 ? round(($converted / max(1, (int) $stats['total'])) * 100, 2) : 100.0;
                $stats['updated_at'] = current_time('mysql');
                $stats['is_stale'] = false;
                $this->save_batch_stats((string) ($job['scope'] ?? 'uploads'), $stats);
                if (!empty($job['job_id'])) {
                        $this->clear_batch_control_state((string) $job['job_id']);
                }
        }

        /**
         * @return array<string, mixed>
         */
        private function prepare_batch_status_payload(array $job): array {
                $status = isset($job['status']) ? (string) $job['status'] : 'idle';
                $total = (int) ($job['total'] ?? 0);
                $processed = (int) ($job['processed'] ?? 0);
                $progress = $total > 0 ? round(($processed / max(1, $total)) * 100, 2) : 0.0;

                return [
                        'job_id' => (string) ($job['job_id'] ?? ''),
                        'scope' => (string) ($job['scope'] ?? ''),
                        'status' => $status,
                        'total' => $total,
                        'processed' => $processed,
                        'converted' => (int) ($job['converted'] ?? 0),
                        'already_exists' => (int) ($job['already_exists'] ?? 0),
                        'skipped' => (int) ($job['skipped'] ?? 0),
                        'errors' => (int) ($job['errors'] ?? 0),
                        'deleted_originals' => (int) ($job['deleted_originals'] ?? 0),
                        'current_item' => (string) ($job['current_item'] ?? ''),
                        'progress' => $progress,
                        'recent_events' => isset($job['recent_events']) && is_array($job['recent_events']) ? array_values($job['recent_events']) : [],
                ];
        }

        private function capture_report_html(): string {
                ob_start();
                $this->render_last_batch_report();
                return (string) ob_get_clean();
        }

        private function redirect_to_plugin_page(string $tab = ''): void {
                $url = admin_url('admin.php?page=ml-media-master');
                if ($tab !== '') {
                        $url .= '#mlmm-tab-' . rawurlencode($tab);
                }
                wp_safe_redirect($url);
                exit;
        }

        private function set_batch_notice(bool $success, string $message): void {
                set_transient(
                        self::BATCH_NOTICE_TRANSIENT,
                        [
                                'success' => $success,
                                'message' => $message,
                        ],
                        60
                );
        }


        /**
         * @return array<string, mixed>
         */
        /**
         * @return array<string, mixed>
         */
        private function run_batch_scope(string $scope, int $limit): array {
                $result = [
                        'converted_now'   => 0,
                        'existing'        => 0,
                        'skipped'         => 0,
                        'errors'          => 0,
                        'deleted_originals' => 0,
                        'processed'       => 0,
                        'converted_files' => [],
                        'existing_files'  => [],
                        'skipped_files'   => [],
                        'error_files'     => [],
                        'recent_events'   => [],
                ];

                $candidates = $this->collect_batch_candidates($scope, $result, $limit);

                foreach ($candidates as $input) {
                        $output = preg_replace('/\.(jpe?g|png)$/i', '.webp', $input);
                        if (!$output) {
                                $result['errors']++;
                                $this->push_batch_report_item($result['error_files'], 'Saída inválida: ' . $this->make_path_relative_for_report($input));
                                continue;
                        }

                        $conversion = $this->convert_existing_file_to_webp_targeted($input, $output, true);
                        $result['processed']++;

                        if (!empty($conversion['already_existed'])) {
                                $result['existing']++;
                                $this->push_batch_report_item($result['existing_files'], $this->make_path_relative_for_report($input));
                                $this->push_batch_report_item($result['recent_events'], 'Já existia: ' . $this->make_path_relative_for_report($output), 40);
                                continue;
                        }

                        if (!empty($conversion['success'])) {
                                $result['converted_now']++;
                                $this->push_batch_report_item($result['converted_files'], $this->make_path_relative_for_report($input));
                                if (!empty($conversion['deleted_original'])) {
                                        $result['deleted_originals']++;
                                        $this->push_batch_report_item($result['recent_events'], 'Convertido e apagado original: ' . $this->make_path_relative_for_report($input), 40);
                                } else {
                                        $this->push_batch_report_item($result['recent_events'], 'Convertido: ' . $this->make_path_relative_for_report($input), 40);
                                }
                                continue;
                        }

                        if (!empty($conversion['skipped'])) {
                                $result['skipped']++;
                                $this->push_batch_report_item($result['skipped_files'], $this->make_path_relative_for_report($input));
                                $this->push_batch_report_item($result['recent_events'], 'Ignorado: ' . $this->make_path_relative_for_report($input), 40);
                                continue;
                        }

                        $result['errors']++;
                        $this->push_batch_report_item($result['error_files'], $this->make_path_relative_for_report($input) . ' | ' . (string) ($conversion['status'] ?? 'erro'));
                        $this->push_batch_report_item($result['recent_events'], 'Erro: ' . $this->make_path_relative_for_report($input), 40);
                        @unlink($output);
                }

                return $result;
        }

        /**
         * @param array<string, mixed> $result
         * @return string[]
         */
        private function collect_batch_candidates(string $scope, array &$result, int $limit = 0, bool $stop_when_limit_reached = false): array {
                $base = '';
                $include_thumbs = false;
                $exclude_thumbs = false;
                $originals_only = false;

                switch ($scope) {
                        case 'uploads':
                                $base = WP_CONTENT_DIR . '/uploads';
                                $originals_only = true;
                                break;

                        case 'gallery_full':
                                $base = WP_CONTENT_DIR . '/gallery';
                                $exclude_thumbs = true;
                                break;

                        case 'gallery_thumbs':
                                $base = WP_CONTENT_DIR . '/gallery';
                                $include_thumbs = true;
                                break;

                        default:
                                $result['errors']++;
                                $this->push_batch_report_item($result['error_files'], 'Escopo inválido: ' . $scope);
                                return [];
                }

                if (!is_dir($base) || !is_readable($base)) {
                        $result['errors']++;
                        $this->push_batch_report_item($result['error_files'], 'Base indisponível: ' . $base);
                        return [];
                }

                try {
                        $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
                        );
                } catch (Throwable $e) {
                        $result['errors']++;
                        $this->push_batch_report_item($result['error_files'], 'Falha ao iterar base: ' . $base);
                        return [];
                }

                $selected = [];

                foreach ($iterator as $fileinfo) {
                        if ($stop_when_limit_reached && $limit > 0 && count($selected) >= $limit) {
                                break;
                        }
                        if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
                                continue;
                        }

                        $path = $fileinfo->getPathname();
                        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

                        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                continue;
                        }

                        $normalized_path = str_replace('\\', '/', $path);
                        $is_thumb = (strpos($normalized_path, '/thumbs/') !== false);

                        if ($include_thumbs && !$is_thumb) {
                                $result['skipped']++;
                                $this->push_batch_report_item($result['skipped_files'], 'Fora de thumbs: ' . $this->make_path_relative_for_report($path));
                                continue;
                        }

                        if ($exclude_thumbs && $is_thumb) {
                                $result['skipped']++;
                                $this->push_batch_report_item($result['skipped_files'], 'Thumb ignorada: ' . $this->make_path_relative_for_report($path));
                                continue;
                        }

                        if ($originals_only && $this->is_probable_resized_subsize($path)) {
                                $result['skipped']++;
                                $this->push_batch_report_item($result['skipped_files'], 'Subtamanho ignorado: ' . $this->make_path_relative_for_report($path));
                                continue;
                        }

                        $output = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
                        if (!$output) {
                                $result['errors']++;
                                $this->push_batch_report_item($result['error_files'], 'Saída inválida: ' . $this->make_path_relative_for_report($path));
                                continue;
                        }

                        if (file_exists($output) && is_readable($output) && filesize($output) > 0) {
                                $result['existing']++;
                                $this->push_batch_report_item($result['existing_files'], $this->make_path_relative_for_report($path));
                                continue;
                        }

                        if (!is_readable($path)) {
                                $result['errors']++;
                                $this->push_batch_report_item($result['error_files'], 'Sem leitura: ' . $this->make_path_relative_for_report($path));
                                continue;
                        }

                        if ($limit < 1 || count($selected) < $limit) {
                                $selected[] = $path;
                        }
                }

                return $selected;
        }

        private function is_probable_resized_subsize(string $path): bool {
                $filename = (string) pathinfo($path, PATHINFO_FILENAME);

                return (bool) preg_match('/-\d+x\d+$/', $filename);
        }

        private function make_path_relative_for_report(string $path): string {
                $normalized = str_replace('\\', '/', $path);
                $content_dir = str_replace('\\', '/', WP_CONTENT_DIR);

                if (strpos($normalized, $content_dir) === 0) {
                        return ltrim(substr($normalized, strlen($content_dir)), '/');
                }

                return $normalized;
        }

        /**
         * @param array<int, string> $items
         */
        private function push_batch_report_item(array &$items, string $value, int $max_items = 200): void {
                if ($value === '' || count($items) >= $max_items) {
                        return;
                }

                $items[] = $value;
        }

        /**
         * @return array<string, int|float|string>
         */
        private function get_batch_stats(string $scope): array {
                $result = [
                        'existing'       => 0,
                        'skipped'        => 0,
                        'errors'         => 0,
                        'existing_files' => [],
                        'skipped_files'  => [],
                        'error_files'    => [],
                ];

                $pending_files = $this->collect_batch_candidates($scope, $result, 0);
                $pending       = count($pending_files);
                $converted     = (int) $result['existing'];

                if ($pending === 0 && $converted === 0 && in_array($scope, ['gallery_full', 'gallery_thumbs'], true)) {
                        $converted = $this->count_scope_webp_files($scope);
                }

                $total         = $converted + $pending;
                $progress      = $total > 0 ? (($converted / $total) * 100) : 0.0;

                $base_label = '';
                switch ($scope) {
                        case 'uploads':
                                $base_label = WP_CONTENT_DIR . '/uploads';
                                break;
                        case 'gallery_full':
                                $base_label = WP_CONTENT_DIR . '/gallery (sem thumbs)';
                                break;
                        case 'gallery_thumbs':
                                $base_label = WP_CONTENT_DIR . '/gallery/thumbs';
                                break;
                }

                return [
                        'total'      => $total,
                        'converted'  => $converted,
                        'pending'    => $pending,
                        'progress'   => round($progress, 2),
                        'base_label' => $base_label,
                ];
        }

        private function count_scope_webp_files(string $scope): int {
                $base = '';
                $include_thumbs = false;
                $exclude_thumbs = false;

                switch ($scope) {
                        case 'gallery_full':
                                $base = WP_CONTENT_DIR . '/gallery';
                                $exclude_thumbs = true;
                                break;
                        case 'gallery_thumbs':
                                $base = WP_CONTENT_DIR . '/gallery';
                                $include_thumbs = true;
                                break;
                        default:
                                return 0;
                }

                if (!is_dir($base) || !is_readable($base)) {
                        return 0;
                }

                try {
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                } catch (Throwable $e) {
                        return 0;
                }

                $count = 0;
                foreach ($iterator as $fileinfo) {
                        if ($stop_when_limit_reached && $limit > 0 && count($selected) >= $limit) {
                                break;
                        }
                        if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
                                continue;
                        }
                        $path = str_replace('\\', '/', $fileinfo->getPathname());
                        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'webp') {
                                continue;
                        }
                        $is_thumb = (strpos($path, '/thumbs/') !== false);
                        if ($include_thumbs && !$is_thumb) {
                                continue;
                        }
                        if ($exclude_thumbs && $is_thumb) {
                                continue;
                        }
                        $count++;
                }

                return $count;
        }

        private function should_preserve_saved_stats(string $scope, array $fresh, array $saved): bool {
                if (($fresh['pending'] ?? 0) !== 0 || ($fresh['total'] ?? 0) !== 0) {
                        return false;
                }

                if (($saved['total'] ?? 0) < 1) {
                        return false;
                }

                if (($saved['pending'] ?? 0) !== 0) {
                        return false;
                }

                return in_array($scope, ['gallery_full', 'gallery_thumbs'], true) || (($scope === 'uploads') && (($saved['converted'] ?? 0) > 0));
        }

        private function get_scope_stats_with_memory(string $scope): array {
                $fresh = $this->get_batch_stats($scope);
                $saved = $this->get_saved_batch_stats($scope);

                if ($this->should_preserve_saved_stats($scope, $fresh, $saved)) {
                        $fresh['total'] = (int) ($saved['total'] ?? 0);
                        $fresh['converted'] = (int) ($saved['converted'] ?? 0);
                        $fresh['pending'] = 0;
                        $fresh['progress'] = (float) ($saved['progress'] ?? 100.0);
                }

                return $fresh;
        }

        private function get_quarantine_root_dir(): string {
                return WP_CONTENT_DIR . '/' . self::QUARANTINE_DIR_NAME;
        }

        private function get_quarantine_scope_dir(string $scope): string {
                return $this->get_quarantine_root_dir() . '/' . $scope;
        }

        private function get_scope_original_files(string $scope): array {
                $base = '';
                $include_thumbs = false;
                $exclude_thumbs = false;
                $originals_only = false;

                switch ($scope) {
                        case 'uploads':
                                $base = WP_CONTENT_DIR . '/uploads';
                                $originals_only = true;
                                break;
                        case 'gallery_full':
                                $base = WP_CONTENT_DIR . '/gallery';
                                $exclude_thumbs = true;
                                break;
                        case 'gallery_thumbs':
                                $base = WP_CONTENT_DIR . '/gallery';
                                $include_thumbs = true;
                                break;
                        default:
                                return [];
                }

                if (!is_dir($base) || !is_readable($base)) {
                                return [];
                }

                try {
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                } catch (Throwable $e) {
                        return [];
                }

                $selected = [];
                foreach ($iterator as $fileinfo) {
                        if ($stop_when_limit_reached && $limit > 0 && count($selected) >= $limit) {
                                break;
                        }
                        if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
                                continue;
                        }
                        $path = $fileinfo->getPathname();
                        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                continue;
                        }
                        $normalized_path = str_replace('\\', '/', $path);
                        $is_thumb = (strpos($normalized_path, '/thumbs/') !== false);
                        if ($include_thumbs && !$is_thumb) {
                                continue;
                        }
                        if ($exclude_thumbs && $is_thumb) {
                                continue;
                        }
                        if ($originals_only && $this->is_probable_resized_subsize($path)) {
                                continue;
                        }
                        $selected[] = $path;
                }

                sort($selected);
                return $selected;
        }

        private function is_valid_webp_file(string $path): bool {
                return file_exists($path) && is_readable($path) && (int) @filesize($path) > 0;
        }

        private function build_quarantine_relative_path(string $scope, string $original_path): string {
                $normalized = str_replace('\\', '/', $original_path);
                $content_dir = str_replace('\\', '/', WP_CONTENT_DIR);
                $relative = ltrim((strpos($normalized, $content_dir) === 0) ? substr($normalized, strlen($content_dir)) : basename($normalized), '/');

                if ($scope === 'gallery_full' && strpos($relative, 'gallery/') === 0) {
                        $relative = substr($relative, strlen('gallery/'));
                }
                if ($scope === 'gallery_thumbs' && strpos($relative, 'gallery/') === 0) {
                        $relative = substr($relative, strlen('gallery/'));
                }
                if ($scope === 'uploads' && strpos($relative, 'uploads/') === 0) {
                        $relative = substr($relative, strlen('uploads/'));
                }

                return ltrim($relative, '/');
        }

        private function get_quarantine_original_destination(string $scope, string $original_path): string {
                return $this->get_quarantine_scope_dir($scope) . '/' . $this->build_quarantine_relative_path($scope, $original_path);
        }

        private function map_quarantine_file_to_original(string $scope, string $quarantine_path): string {
                $root = str_replace('\\', '/', $this->get_quarantine_scope_dir($scope));
                $normalized = str_replace('\\', '/', $quarantine_path);
                $relative = ltrim((strpos($normalized, $root) === 0) ? substr($normalized, strlen($root)) : basename($normalized), '/');
                if ($scope === 'uploads') {
                        return WP_CONTENT_DIR . '/uploads/' . $relative;
                }
                return WP_CONTENT_DIR . '/gallery/' . $relative;
        }

        private function get_quarantine_status_data(): array {
                $root = $this->get_quarantine_root_dir();
                $status = [
                        'root' => $root,
                        'exists' => is_dir($root),
                        'files' => 0,
                        'bytes' => 0,
                        'updated_at' => current_time('mysql'),
                ];

                if (!$status['exists']) {
                        return $status;
                }

                try {
                        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                } catch (Throwable $e) {
                        return $status;
                }

                foreach ($iterator as $fileinfo) {
                        if ($stop_when_limit_reached && $limit > 0 && count($selected) >= $limit) {
                                break;
                        }
                        if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
                                continue;
                        }
                        $status['files']++;
                        $status['bytes'] += (int) $fileinfo->getSize();
                }

                return $status;
        }

        private function format_bytes_human(int $bytes): string {
                if ($bytes < 1024) {
                        return $bytes . ' B';
                }
                $units = ['KB', 'MB', 'GB', 'TB'];
                $value = $bytes / 1024;
                $unit = 0;
                while ($value >= 1024 && $unit < count($units) - 1) {
                        $value /= 1024;
                        $unit++;
                }
                return number_format_i18n($value, 2) . ' ' . $units[$unit];
        }

        private function audit_quarantine_candidates(string $scope): array {
                $scopes = ($scope === 'all') ? ['uploads', 'gallery_full', 'gallery_thumbs'] : [$scope];
                $audit = [
                        'scope' => $scope,
                        'generated_at' => current_time('mysql'),
                        'checked' => 0,
                        'candidates' => 0,
                        'missing_webp' => 0,
                        'invalid_webp' => 0,
                        'already_quarantined' => 0,
                        'ignored' => 0,
                        'errors' => 0,
                        'candidate_items' => [],
                        'sample_items' => [],
                ];

                foreach ($scopes as $single_scope) {
                        foreach ($this->get_scope_original_files($single_scope) as $path) {
                                $audit['checked']++;
                                $webp = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
                                $quarantine_dest = $this->get_quarantine_original_destination($single_scope, $path);
                                if ($quarantine_dest !== '' && file_exists($quarantine_dest)) {
                                        $audit['already_quarantined']++;
                                        $this->push_batch_report_item($audit['sample_items'], 'Já em quarentena: ' . $this->make_path_relative_for_report($path), 40);
                                        continue;
                                }
                                if ($webp === '' || !file_exists($webp)) {
                                        $audit['missing_webp']++;
                                        continue;
                                }
                                if (!$this->is_valid_webp_file($webp)) {
                                        $audit['invalid_webp']++;
                                        continue;
                                }
                                $audit['candidates']++;
                                $audit['candidate_items'][] = [
                                        'scope' => $single_scope,
                                        'original' => $path,
                                        'webp' => $webp,
                                        'quarantine' => $quarantine_dest,
                                ];
                                $this->push_batch_report_item($audit['sample_items'], 'Candidato: ' . $this->make_path_relative_for_report($path), 40);
                        }
                }

                update_option(self::QUARANTINE_AUDIT_OPTION, $audit, false);
                update_option(self::QUARANTINE_STATUS_OPTION, $this->get_quarantine_status_data(), false);
                return $audit;
        }

        private function get_last_quarantine_audit(): array {
                $audit = get_option(self::QUARANTINE_AUDIT_OPTION, []);
                return is_array($audit) ? $audit : [];
        }

        private function get_last_quarantine_report(): array {
                $report = get_option(self::QUARANTINE_REPORT_OPTION, []);
                return is_array($report) ? $report : [];
        }

        private function move_quarantine_candidates(string $scope): array {
                $audit = $this->audit_quarantine_candidates($scope);
                $report = [
                        'action' => 'move',
                        'scope' => $scope,
                        'generated_at' => current_time('mysql'),
                        'moved' => 0,
                        'errors' => 0,
                        'bytes' => 0,
                        'conflicts' => 0,
                        'items' => [],
                        'error_items' => [],
                ];

                foreach (($audit['candidate_items'] ?? []) as $item) {
                        if (!is_array($item)) {
                                continue;
                        }
                        $original = (string) ($item['original'] ?? '');
                        $dest = (string) ($item['quarantine'] ?? '');
                        if ($original === '' || $dest === '') {
                                $report['errors']++;
                                continue;
                        }
                        if (!file_exists($original)) {
                                continue;
                        }
                        if (file_exists($dest)) {
                                $report['conflicts']++;
                                $this->push_batch_report_item($report['error_items'], 'Conflito em quarentena: ' . $this->make_path_relative_for_report($original), 60);
                                continue;
                        }
                        wp_mkdir_p(dirname($dest));
                        $size = (int) @filesize($original);
                        if (@rename($original, $dest)) {
                                $report['moved']++;
                                $report['bytes'] += max(0, $size);
                                $this->push_batch_report_item($report['items'], 'Movido: ' . $this->make_path_relative_for_report($original), 60);
                        } else {
                                $report['errors']++;
                                $this->push_batch_report_item($report['error_items'], 'Falha ao mover: ' . $this->make_path_relative_for_report($original), 60);
                        }
                }

                update_option(self::QUARANTINE_REPORT_OPTION, $report, false);
                update_option(self::QUARANTINE_STATUS_OPTION, $this->get_quarantine_status_data(), false);
                return $report;
        }

        private function collect_quarantine_files(string $scope = 'all'): array {
                $scopes = ($scope === 'all') ? ['uploads', 'gallery_full', 'gallery_thumbs'] : [$scope];
                $files = [];
                foreach ($scopes as $single_scope) {
                        $dir = $this->get_quarantine_scope_dir($single_scope);
                        if (!is_dir($dir) || !is_readable($dir)) {
                                continue;
                        }
                        try {
                                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                        } catch (Throwable $e) {
                                continue;
                        }
                        foreach ($iterator as $fileinfo) {
                        if ($stop_when_limit_reached && $limit > 0 && count($selected) >= $limit) {
                                break;
                        }
                                if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
                                        continue;
                                }
                                $files[] = ['scope' => $single_scope, 'path' => $fileinfo->getPathname()];
                        }
                }
                return $files;
        }

        private function restore_quarantine_files(string $scope = 'all'): array {
                $report = [
                        'action' => 'restore',
                        'scope' => $scope,
                        'generated_at' => current_time('mysql'),
                        'restored' => 0,
                        'errors' => 0,
                        'conflicts' => 0,
                        'items' => [],
                        'error_items' => [],
                ];
                foreach ($this->collect_quarantine_files($scope) as $item) {
                        $single_scope = (string) ($item['scope'] ?? '');
                        $path = (string) ($item['path'] ?? '');
                        if ($single_scope === '' || $path === '') {
                                continue;
                        }
                        $dest = $this->map_quarantine_file_to_original($single_scope, $path);
                        if (file_exists($dest)) {
                                $report['conflicts']++;
                                $this->push_batch_report_item($report['error_items'], 'Conflito na restauração: ' . $this->make_path_relative_for_report($dest), 60);
                                continue;
                        }
                        wp_mkdir_p(dirname($dest));
                        if (@rename($path, $dest)) {
                                $report['restored']++;
                                $this->push_batch_report_item($report['items'], 'Restaurado: ' . $this->make_path_relative_for_report($dest), 60);
                        } else {
                                $report['errors']++;
                                $this->push_batch_report_item($report['error_items'], 'Falha ao restaurar: ' . $this->make_path_relative_for_report($dest), 60);
                        }
                }
                update_option(self::QUARANTINE_REPORT_OPTION, $report, false);
                update_option(self::QUARANTINE_STATUS_OPTION, $this->get_quarantine_status_data(), false);
                return $report;
        }

        private function clear_quarantine_files(string $scope = 'all'): array {
                $report = [
                        'action' => 'clear',
                        'scope' => $scope,
                        'generated_at' => current_time('mysql'),
                        'deleted' => 0,
                        'errors' => 0,
                        'bytes' => 0,
                        'items' => [],
                        'error_items' => [],
                ];
                foreach ($this->collect_quarantine_files($scope) as $item) {
                        $path = (string) ($item['path'] ?? '');
                        if ($path === '' || !file_exists($path)) {
                                continue;
                        }
                        $size = (int) @filesize($path);
                        if (@unlink($path)) {
                                $report['deleted']++;
                                $report['bytes'] += max(0, $size);
                                $this->push_batch_report_item($report['items'], 'Apagado da quarentena: ' . basename($path), 60);
                        } else {
                                $report['errors']++;
                                $this->push_batch_report_item($report['error_items'], 'Falha ao apagar: ' . basename($path), 60);
                        }
                }
                update_option(self::QUARANTINE_REPORT_OPTION, $report, false);
                update_option(self::QUARANTINE_STATUS_OPTION, $this->get_quarantine_status_data(), false);
                return $report;
        }

        private function render_quarantine_audit_panel(): void {
                $audit = $this->get_last_quarantine_audit();
                if (empty($audit)) {
                        echo '<p><em>Nenhuma auditoria realizada ainda.</em></p>';
                        return;
                }
                echo '<p><strong>Escopo auditado:</strong> ' . esc_html((string) ($audit['scope'] ?? '')) . '</p>';
                echo '<p><strong>Verificados:</strong> ' . esc_html((string) ((int) ($audit['checked'] ?? 0))) . ' | <strong>Candidatos:</strong> ' . esc_html((string) ((int) ($audit['candidates'] ?? 0))) . ' | <strong>Sem WebP:</strong> ' . esc_html((string) ((int) ($audit['missing_webp'] ?? 0))) . ' | <strong>WebP inválido:</strong> ' . esc_html((string) ((int) ($audit['invalid_webp'] ?? 0))) . ' | <strong>Já em quarentena:</strong> ' . esc_html((string) ((int) ($audit['already_quarantined'] ?? 0))) . '</p>';
                echo '<p><strong>Última auditoria:</strong> ' . esc_html((string) ($audit['generated_at'] ?? '')) . '</p>';
                $samples = isset($audit['sample_items']) && is_array($audit['sample_items']) ? array_values($audit['sample_items']) : [];
                echo '<textarea readonly="readonly" class="mlmm-audit-log">' . esc_textarea(implode("
", $samples)) . '</textarea>';
        }

        private function render_quarantine_status_panel(): void {
                $status = $this->get_quarantine_status_data();
                $report = $this->get_last_quarantine_report();
                echo '<p><strong>Pasta da quarentena:</strong> ' . esc_html((string) ($status['root'] ?? '')) . '</p>';
                echo '<p><strong>Arquivos em quarentena:</strong> ' . esc_html((string) ((int) ($status['files'] ?? 0))) . '</p>';
                echo '<p><strong>Tamanho ocupado:</strong> ' . esc_html($this->format_bytes_human((int) ($status['bytes'] ?? 0))) . '</p>';
                if (!empty($report)) {
                        echo '<hr />';
                        echo '<p><strong>Última ação:</strong> ' . esc_html((string) ($report['action'] ?? '')) . ' | <strong>Escopo:</strong> ' . esc_html((string) ($report['scope'] ?? '')) . ' | <strong>Quando:</strong> ' . esc_html((string) ($report['generated_at'] ?? '')) . '</p>';
                        $items = isset($report['items']) && is_array($report['items']) ? array_values($report['items']) : [];
                        $errors = isset($report['error_items']) && is_array($report['error_items']) ? array_values($report['error_items']) : [];
                        echo '<textarea readonly="readonly" class="mlmm-audit-log">' . esc_textarea(implode("
", array_merge($items, $errors))) . '</textarea>';
                }
        }

        public function ajax_quarantine_audit(): void {
                $this->ajax_capability_check();
                $this->require_full_license('Auditoria e Quarentena estão disponíveis apenas na versão Full.');
                $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : 'all';
                if (!in_array($scope, ['uploads', 'gallery_full', 'gallery_thumbs', 'all'], true)) {
                        wp_send_json_error(['message' => 'Escopo inválido para auditoria.'], 400);
                }
                $audit = $this->audit_quarantine_candidates($scope);
                ob_start();
                $this->render_quarantine_audit_panel();
                $audit_html = (string) ob_get_clean();
                ob_start();
                $this->render_quarantine_status_panel();
                $status_html = (string) ob_get_clean();
                wp_send_json_success(['audit' => $audit, 'audit_html' => $audit_html, 'status_html' => $status_html]);
        }

        public function ajax_quarantine_move(): void {
                $this->ajax_capability_check();
                $this->require_full_license('Mover para quarentena está disponível apenas na versão Full.');
                $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : 'all';
                $report = $this->move_quarantine_candidates($scope);
                ob_start(); $this->render_quarantine_audit_panel(); $audit_html = (string) ob_get_clean();
                ob_start(); $this->render_quarantine_status_panel(); $status_html = (string) ob_get_clean();
                wp_send_json_success(['report' => $report, 'audit_html' => $audit_html, 'status_html' => $status_html]);
        }

        public function ajax_quarantine_restore(): void {
                $this->ajax_capability_check();
                $this->require_full_license('Restaurar quarentena está disponível apenas na versão Full.');
                $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : 'all';
                $report = $this->restore_quarantine_files($scope);
                ob_start(); $this->render_quarantine_status_panel(); $status_html = (string) ob_get_clean();
                wp_send_json_success(['report' => $report, 'status_html' => $status_html]);
        }

        public function ajax_quarantine_clear(): void {
                $this->ajax_capability_check();
                $this->require_full_license('Limpar quarentena está disponível apenas na versão Full.');
                $scope = isset($_POST['scope']) ? sanitize_text_field((string) $_POST['scope']) : 'all';
                $report = $this->clear_quarantine_files($scope);
                ob_start(); $this->render_quarantine_status_panel(); $status_html = (string) ob_get_clean();
                wp_send_json_success(['report' => $report, 'status_html' => $status_html]);
        }

        public function ajax_quarantine_status(): void {
                $this->ajax_capability_check();
                ob_start(); $this->render_quarantine_audit_panel(); $audit_html = (string) ob_get_clean();
                ob_start(); $this->render_quarantine_status_panel(); $status_html = (string) ob_get_clean();
                wp_send_json_success(['audit_html' => $audit_html, 'status_html' => $status_html]);
        }

        public function admin_notice_dependencies(): void {
                if (!is_admin() || !current_user_can('manage_options')) {
                        return;
                }

                if (isset($_GET['page']) && $_GET['page'] === 'ml-media-master') {
                        return;
                }

                if (empty($this->settings['enabled'])) {
                        return;
                }

                $missing = [];

                if (!$this->is_exec_available()) {
                        $missing[] = 'exec() desabilitado no PHP';
                }

                $required_bins = ['convert', 'cwebp'];

                foreach ($required_bins as $bin) {
                        if (!$this->binary_exists($bin)) {
                                $missing[] = $bin;
                        }
                }

                if (empty($missing)) {
                        return;
                }

                echo '<div class="notice notice-error"><p><strong>ML Media Master:</strong> dependências ausentes ou indisponíveis: ' . esc_html(implode(', ', $missing)) . '.</p></div>';
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

                return preg_replace_callback(
                        '/' . $baseurl . '[^"\')\s>]+\.(?:jpe?g|png)/i',
                        function (array $matches): string {
                                $url = isset($matches[0]) ? (string) $matches[0] : '';

                                return $this->maybe_swap_upload_url_to_webp($url);
                        },
                        $content
                );
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

        /**
         * @param mixed $image
         */
        public function handle_nextgen_added_image($image): void {
                $this->settings = $this->get_settings();

                if (empty($this->settings['enable_nextgen_hook'])) {
                        return;
                }

                if (!$this->is_exec_available()) {
                        return;
                }

                if (!$this->binary_exists('convert') || !$this->binary_exists('cwebp')) {
                        return;
                }

                $candidates = $this->find_nextgen_candidate_files($image);
                if (empty($candidates)) {
                        return;
                }

                foreach ($candidates as $input) {
                        $output = preg_replace('/\.(jpe?g|png)$/i', '.webp', $input);
                        if (!$output) {
                                continue;
                        }

                        if (file_exists($output) && filesize($output) > 0) {
                                continue;
                        }

                        $this->convert_existing_file_to_webp_targeted($input, $output);
                }
        }

        /**
         * @param mixed $retval
         * @param mixed $image
         * @param mixed $size
         * @return mixed
         */
        public function filter_nextgen_image_url($retval, $image, $size) {
                unset($image, $size);

                $this->settings = $this->get_settings();

                if (empty($this->settings['enable_nextgen_hook'])) {
                        return $retval;
                }

                if (!is_string($retval) || $retval === '') {
                        return $retval;
                }

                if (!preg_match('/\.(jpe?g|png)$/i', $retval)) {
                        return $retval;
                }

                $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $retval);
                if (!$webp_url || $webp_url === $retval) {
                        return $retval;
                }

                $original_path = $this->url_to_abspath($retval);
                $webp_path     = $this->url_to_abspath($webp_url);

                if ($original_path === '' || $webp_path === '') {
                        return $retval;
                }

                if ($this->ensure_webp_exists_from_original($original_path, $webp_path)) {
                        return $webp_url;
                }

                return $retval;
        }

        private function url_to_abspath(string $url): string {
                $parsed = wp_parse_url($url);
                if (!is_array($parsed) || empty($parsed['path'])) {
                        return '';
                }

                $path = (string) $parsed['path'];
                $site_path = (string) wp_parse_url(site_url('/'), PHP_URL_PATH);

                if ($site_path !== '' && $site_path !== '/' && strpos($path, $site_path) === 0) {
                        $path = substr($path, strlen($site_path));
                }

                $path = '/' . ltrim($path, '/');

                return untrailingslashit(ABSPATH) . $path;
        }

        /**
         * @param mixed $image
         * @return string[]
         */
        private function find_nextgen_candidate_files($image): array {
                $filename = '';

                if (is_object($image) && isset($image->filename) && is_string($image->filename)) {
                        $filename = basename($image->filename);
                } elseif (is_array($image) && isset($image['filename']) && is_string($image['filename'])) {
                        $filename = basename($image['filename']);
                }

                if ($filename === '') {
                        return [];
                }

                $gallery_base = WP_CONTENT_DIR . '/gallery';
                if (!is_dir($gallery_base) || !is_readable($gallery_base)) {
                        return [];
                }

                $targets = [
                        strtolower($filename),
                        strtolower('thumbs_' . $filename),
                ];

                $found = [];

                try {
                        $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($gallery_base, FilesystemIterator::SKIP_DOTS)
                        );
                } catch (Throwable $e) {
                        return [];
                }

                foreach ($iterator as $fileinfo) {
                        if ($stop_when_limit_reached && $limit > 0 && count($selected) >= $limit) {
                                break;
                        }
                        if (!$fileinfo instanceof SplFileInfo || !$fileinfo->isFile()) {
                                continue;
                        }

                        $ext = strtolower((string) pathinfo($fileinfo->getFilename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                continue;
                        }

                        $basename = strtolower($fileinfo->getFilename());
                        if (!in_array($basename, $targets, true)) {
                                continue;
                        }

                        $path = $fileinfo->getPathname();
                        if (is_readable($path)) {
                                $found[$path] = $path;
                        }
                }

                return array_values($found);
        }

        /**
         * @param array<string, mixed> $upload
         * @param string               $context
         * @return array<string, mixed>
         */
        public function process_upload(array $upload, string $context): array {
                unset($context);

                $this->settings = $this->get_settings();

                if (empty($this->settings['enabled'])) {
                        return $upload;
                }

                if (empty($upload['file']) || empty($upload['type'])) {
                        return $upload;
                }

                if (!$this->is_exec_available()) {
                        return $upload;
                }

                $mime = strtolower((string) $upload['type']);
                $allowed_mimes = isset($this->settings['allowed_mimes']) && is_array($this->settings['allowed_mimes'])
                        ? $this->settings['allowed_mimes']
                        : $this->get_default_settings()['allowed_mimes'];

                if (!in_array($mime, $allowed_mimes, true)) {
                        return $upload;
                }

                $required_bins = ['convert', 'cwebp'];
                foreach ($required_bins as $bin) {
                        if (!$this->binary_exists($bin)) {
                                return $upload;
                        }
                }

                $original_file = (string) $upload['file'];

                if (!file_exists($original_file) || !is_readable($original_file)) {
                        return $upload;
                }

                $upload_dir  = dirname($original_file);
                $base_name   = pathinfo($original_file, PATHINFO_FILENAME);
                $target_name = wp_unique_filename($upload_dir, sanitize_file_name($base_name) . '.webp');
                $target_file = trailingslashit($upload_dir) . $target_name;

                $temp_source = wp_tempnam($original_file);
                if (!$temp_source) {
                        return $upload;
                }

                $temp_source_with_ext = $temp_source . '.' . ($mime === 'image/png' ? 'png' : 'jpg');

                if (!@rename($temp_source, $temp_source_with_ext)) {
                        @unlink($temp_source);
                        return $upload;
                }

                $temp_optimized = wp_tempnam($original_file);
                if (!$temp_optimized) {
                        @unlink($temp_source_with_ext);
                        return $upload;
                }

                $temp_optimized_with_ext = $temp_optimized . '.' . ($mime === 'image/png' ? 'png' : 'jpg');

                if (!@rename($temp_optimized, $temp_optimized_with_ext)) {
                        @unlink($temp_source_with_ext);
                        @unlink($temp_optimized);
                        return $upload;
                }

                $resize_ok = $this->normalize_and_resize($original_file, $temp_source_with_ext);
                if (!$resize_ok) {
                        @unlink($temp_source_with_ext);
                        @unlink($temp_optimized_with_ext);
                        return $upload;
                }

                $optimize_ok = $this->optimize_intermediate($temp_source_with_ext, $temp_optimized_with_ext, $mime);
                if (!$optimize_ok) {
                        @unlink($temp_source_with_ext);
                        @unlink($temp_optimized_with_ext);
                        return $upload;
                }

                $webp_ok = $this->convert_to_webp_targeted($temp_optimized_with_ext, $target_file);

                @unlink($temp_source_with_ext);
                @unlink($temp_optimized_with_ext);

                if (!$webp_ok) {
                        @unlink($target_file);
                        return $upload;
                }

                $new_url = trailingslashit(dirname((string) $upload['url'])) . basename($target_file);

                if (!empty($this->settings['delete_original']) && $this->is_valid_generated_file($target_file) && $target_file !== $original_file) {
                        @unlink($original_file);
                }

                $upload['file'] = $target_file;
                $upload['url']  = $new_url;
                $upload['type'] = 'image/webp';

                return $upload;
        }

        /**
         * @param array<string, mixed> $metadata
         * @return array<string, mixed>
         */
        public function fix_metadata_after_webp(array $metadata, int $attachment_id): array {
                $file = get_attached_file($attachment_id);

                if (!$file || !file_exists($file)) {
                        return $metadata;
                }

                $mime = get_post_mime_type($attachment_id);
                if ($mime !== 'image/webp') {
                        return $metadata;
                }

                $size = @getimagesize($file);
                if ($size) {
                        $metadata['width']  = (int) $size[0];
                        $metadata['height'] = (int) $size[1];
                        $metadata['file']   = _wp_relative_upload_path($file);
                }

                return $metadata;
        }

        private function normalize_and_resize(string $input, string $output): bool {
                $max_long_edge = (int) $this->settings['max_long_edge'];
                if ($max_long_edge < 200) {
                        $max_long_edge = 1280;
                }

                $cmd = sprintf(
                        'convert %s -auto-orient -resize %s %s 2>&1',
                        escapeshellarg($input),
                        escapeshellarg($max_long_edge . 'x' . $max_long_edge . '>'),
                        escapeshellarg($output)
                );

                $dummy = [];
                $code  = 0;
                @exec($cmd, $dummy, $code);

                return $code === 0 && file_exists($output) && filesize($output) > 0;
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
                                'jpegtran -copy none -optimize %s > %s 2>/dev/null',
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
                        return $result;
                }

                if (!$this->binary_exists('convert') || !$this->binary_exists('cwebp')) {
                        $result['message'] = 'Dependências indisponíveis.';
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

                if (!preg_match('/\.(jpe?g|png)$/i', $original_path)) {
                        return false;
                }

                if (!$this->is_exec_available()) {
                        return false;
                }

                if (!$this->binary_exists('convert') || !$this->binary_exists('cwebp')) {
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

                return false;
        }

        private function run_cwebp(string $input, string $output, int $quality): bool {
                $cmd = sprintf(
                        'cwebp -quiet -mt -q %d %s -o %s 2>&1',
                        $quality,
                        escapeshellarg($input),
                        escapeshellarg($output)
                );

                $dummy = [];
                $code  = 0;
                @exec($cmd, $dummy, $code);

                return $code === 0 && file_exists($output) && filesize($output) > 0;
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
                        case 'convert':
                                return ['convert -version >/dev/null 2>&1'];

                        case 'identify':
                                return ['identify -version >/dev/null 2>&1'];

                        case 'cwebp':
                                return ['cwebp -version >/dev/null 2>&1'];

                        case 'jpegoptim':
                                return ['jpegoptim --version >/dev/null 2>&1'];

                        case 'jpegtran':
                                return ['jpegtran -version >/dev/null 2>&1'];

                        case 'pngquant':
                                return ['pngquant --version >/dev/null 2>&1'];

                        case 'optipng':
                                return ['optipng -v >/dev/null 2>&1'];

                        default:
                                return [escapeshellcmd($binary) . ' --version >/dev/null 2>&1'];
                }
        }


        public function run_cli_batch(string $scope, int $limit = 1000): int {
                $this->settings = $this->get_settings();

                if (!$this->is_exec_available() || !$this->binary_exists('convert') || !$this->binary_exists('cwebp')) {
                        fwrite(STDERR, "Erro: exec(), convert ou cwebp indisponível.
");
                        return 1;
                }

                if ($limit < 1) {
                        $limit = 1000;
                }

                $totalConverted = 0;
                $loops = 0;

                $this->clear_batch_stop($scope);
                $this->mark_batch_stats_stale($scope);

                while (true) {
                        if ($this->is_batch_stop_requested($scope)) {
                                echo "Parada solicitada para {$scope}. Encerrando processamento.
";
                                $this->clear_batch_stop($scope);
                                break;
                        }

                        $loops++;
                        $result = $this->run_batch_scope($scope, $limit);

                        echo sprintf(
                                "Loop %d | Convertidos: %d | Existentes: %d | Ignorados: %d | Erros: %d
",
                                $loops,
                                (int) $result['converted_now'],
                                (int) $result['existing'],
                                (int) $result['skipped'],
                                (int) $result['errors']
                        );

                        update_option(
                                self::BATCH_REPORT_OPTION,
                                [
                                        'scope'           => $scope,
                                        'generated_at'    => current_time('mysql'),
                                        'converted_files' => isset($result['converted_files']) && is_array($result['converted_files']) ? array_values($result['converted_files']) : [],
                                        'existing_files'  => isset($result['existing_files']) && is_array($result['existing_files']) ? array_values($result['existing_files']) : [],
                                        'skipped_files'   => isset($result['skipped_files']) && is_array($result['skipped_files']) ? array_values($result['skipped_files']) : [],
                                        'error_files'     => isset($result['error_files']) && is_array($result['error_files']) ? array_values($result['error_files']) : [],
                                ],
                                false
                        );

                        $totalConverted += (int) $result['converted_now'];

                        if ((int) $result['errors'] > 0) {
                                $this->mark_batch_stats_stale($scope);
                                return 2;
                        }

                        if ((int) $result['converted_now'] === 0) {
                                break;
                        }
                }

                $this->mark_batch_stats_stale($scope);
                echo "Fim. Convertidos no total: {$totalConverted}
";
                return 0;
        }

}

$ml_media_master_instance = new ML_Media_Master();

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        $scope = isset($argv[1]) ? (string) $argv[1] : 'gallery_full';
        $limit = isset($argv[2]) ? (int) $argv[2] : 1000;
        exit($ml_media_master_instance->run_cli_batch($scope, $limit));
}
