<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options actually used by ML Media Master.
$mlmm_options = [
    'ml_media_master_settings',
    'ml_media_master_license_state',
    'ml_media_master_version_seen',
    'mlmm_webp_queue',
    'mlmm_webp_queue_failed',
    'mlmm_webp_queue_lock',
    'mlmm_webp_queue_kept',
    'mlmm_webp_queue_deleted',
];

foreach ($mlmm_options as $option_name) {
    delete_option($option_name);

    if (function_exists('delete_site_option')) {
        delete_site_option($option_name);
    }
}

// Transients actually used by ML Media Master.
$mlmm_transients = [
    'ml_media_master_admin_toast',
    'mlmm_github_release_cache',
];

foreach ($mlmm_transients as $transient_name) {
    delete_transient($transient_name);

    if (function_exists('delete_site_transient')) {
        delete_site_transient($transient_name);
    }
}

// Legacy options from removed engines (batch, audit, quarantine, NGG, MLGP jobs)
// left behind by older versions — cleaned up on uninstall.
$mlmm_legacy_options = [
    'ml_media_master_last_batch_report',
    'ml_media_master_batch_stats',
    'ml_media_master_batch_stop_flags',
    'ml_media_master_batch_job',
    'ml_media_master_batch_control',
    'ml_media_master_quarantine_status',
    'ml_media_master_quarantine_audit_last',
    'ml_media_master_quarantine_last_report',
    'ml_media_master_content_audit_last',
    'ml_media_master_content_fix_last',
    'ml_media_master_ngg_audit_last',
    'ml_media_master_ngg_fix_last',
    'ml_media_master_ngg_link_map',
    'ml_media_master_ngg_audit_state',
    'ml_media_master_physical_residue_audit_last',
    'ml_media_master_recount_state',
    'ml_media_master_recount_lock',
    'ml_media_master_audit_log',
    'ml_media_master_mlgp_job',
    'ml_media_master_mlgp_gallery_job',
    'ml_media_master_mlgp_gallery_report',
    'ml_media_master_mlgp_last_audit_snapshot',
    'ml_media_master_mlgp_audit_state',
];

foreach ($mlmm_legacy_options as $option_name) {
    delete_option($option_name);

    if (function_exists('delete_site_option')) {
        delete_site_option($option_name);
    }
}

delete_transient('ml_media_master_batch_notice');

// 2.10.0: licença pelo site tools — a licença local fica (reinstalar recupera); só a
// renovação diária é desagendada.
wp_clear_scheduled_hook('mllc_ml_media_master_refresh');

// 2.10.2: fila de conversão WebP em segundo plano.
wp_clear_scheduled_hook('mlmm_process_webp_queue');
