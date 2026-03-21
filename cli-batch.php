<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$wp_load = dirname(__FILE__, 4) . '/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "wp-load.php não encontrado.\n");
    exit(1);
}

require_once $wp_load;
require_once __DIR__ . '/ml-media-master.php';

$scope = isset($argv[1]) ? (string) $argv[1] : 'gallery_full';
$limit = isset($argv[2]) ? (int) $argv[2] : 1000;

global $ml_media_master_instance;
if (!$ml_media_master_instance instanceof ML_Media_Master) {
    fwrite(STDERR, "Instância do plugin não carregada.\n");
    exit(1);
}

exit($ml_media_master_instance->run_cli_batch($scope, $limit));
