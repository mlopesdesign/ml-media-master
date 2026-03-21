<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('ml_media_master_settings');
delete_option('ml_media_master_last_batch_report');
delete_option('ml_media_master_batch_stats');
delete_option('ml_media_master_batch_stop_flags');
delete_option('ml_media_master_batch_job');
delete_option('ml_media_master_quarantine_status');
delete_option('ml_media_master_quarantine_audit_last');
delete_option('ml_media_master_quarantine_last_report');

delete_option('ml_media_master_license_state');
