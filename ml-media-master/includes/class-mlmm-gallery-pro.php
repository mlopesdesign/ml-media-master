<?php
/**
 * ML Media Master — ML Gallery Pro compatibility layer.
 *
 * Detection and lightweight status only. The actual upload-time conversion
 * runs in ML_Media_Master::handle_mlgp_items_stored() via the
 * mlgp_after_items_stored hook (accepted_args = 2), without depending on
 * WordPress attachment IDs.
 *
 * @package ML_Media_Master
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MLMM_Gallery_Pro_Compat {

    /** @var ML_Media_Master */
    private $plugin;

    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $items_table = '';

    public function __construct($plugin) {
        global $wpdb;
        $this->plugin = $plugin;
        $this->wpdb = $wpdb;
        $this->items_table = $wpdb->prefix . 'mlgp_gallery_items';
    }

    public function is_available(): bool {
        if ($this->items_table === '') {
            return false;
        }

        $table = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $this->items_table));
        return is_string($table) && $table === $this->items_table;
    }

    /**
     * @return array{local_items:int,message:string}
     */
    public function get_dashboard_summary(): array {
        if (!$this->is_available()) {
            return ['local_items' => 0, 'message' => 'ML Gallery Pro não detectado'];
        }

        $count = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->items_table} WHERE storage = 'local' OR file_path != ''");

        return [
            'local_items' => $count,
            'message' => $count > 0 ? 'Itens locais com conversão automática no upload' : 'Nenhum item local encontrado',
        ];
    }
}
