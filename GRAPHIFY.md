# GRAPHIFY — ML Media Master

> Mapa técnico gerado automaticamente por `graphify.js` a partir do código. **Não editar à mão** — regenerar.

## 1. Identidade

| Campo | Valor |
|---|---|
| Slug (🔒 IMUTÁVEL) | `ml-media-master` |
| Arquivo principal (🔒) | `ml-media-master.php` |
| Versão (header) | 2.10.2 |
| Versão (constante) | 2.10.2 (`VERSION`) |
| Stable tag (readme) | 2.10.2 |
| Versões sincronizadas | ✅ |
| Text Domain (🔒) | ml-media-master |
| Requires WP / PHP | 6.0 / 8.1 |
| Autor | Marcio Lopes |
| Licença | GPL2+ |
| Update URI | https://github.com/mlopesdesign/ml-media-master-releases |

## 2. Estrutura de arquivos

Total: 12 arquivos · 7 de código · 4214 linhas

| Arquivo | Linhas | Classes | Métodos | Hooks |
|---|---|---|---|---|
| assets/admin.js | 78 | 0 | 1 | 0 |
| includes/class-ml-license-client.php | 318 | 1 | 17 | 0 |
| includes/class-mlmm-gallery-pro.php | 60 | 1 | 3 | 0 |
| includes/class-mlmm-license.php | 271 | 1 | 16 | 5 |
| includes/class-mlmm-updater.php | 506 | 1 | 18 | 7 |
| ml-media-master.php | 2897 | 1 | 77 | 18 |
| uninstall.php | 84 | 0 | 0 | 0 |

## 3. Grafo de carregamento

```
ml-media-master.php:20 → includes/class-mlmm-gallery-pro.php
ml-media-master.php:21 → includes/class-mlmm-updater.php
  includes/class-mlmm-updater.php:165 → s = self::REQUIRES_WORDPRESS
  includes/class-mlmm-updater.php:166 → s_php = self::REQUIRES_PHP
  includes/class-mlmm-updater.php:376 → s_php' => self::REQUIRES_PHP, 'icons' => $this->icons(), 'banners' => [], 'banners_rtl' =>
ml-media-master.php:22 → includes/class-mlmm-license.php
  includes/class-mlmm-license.php:30 → includes/class-ml-license-client.php
ml-media-master.php:2483 → _once ABSPATH . 'wp-admin/includes/file.php'
```

## 4. Constantes

| Nome | Valor | Onde |
|---|---|---|
| API_BASE | 'https://tools.mlopesdesign.com.br/wp-json/mllh/v1' | includes/class-mlmm-license.php (class const) |
| PUBLIC_KEY | 'VGZQp2X3RJMuDmQBlIEXS4fqsZD1CnFlIJgfVtY/pHo=' | includes/class-mlmm-license.php (class const) |
| PRODUCT_ID | 'ml-media-master' | includes/class-mlmm-license.php (class const) |
| BUY_URL | 'https://tools.mlopesdesign.com.br/precos/' | includes/class-mlmm-license.php (class const) |
| ACCOUNT_URL | 'https://tools.mlopesdesign.com.br/minha-conta/' | includes/class-mlmm-license.php (class const) |
| AUTO_TRIAL | 'mlmm_auto_trial_tried' | includes/class-mlmm-license.php (class const) |
| PAGE_SLUG | 'ml-media-master' | includes/class-mlmm-license.php (class const) |
| SETTINGS_OPT | 'ml_media_master_settings' | includes/class-mlmm-license.php (class const) |
| SETTINGS_GRP | 'ml_media_master_group' | includes/class-mlmm-license.php (class const) |
| TOAST | 'ml_media_master_admin_toast' | includes/class-mlmm-license.php (class const) |
| GITHUB_OWNER | 'mlopesdesign' | includes/class-mlmm-updater.php (class const) |
| GITHUB_REPO | 'ml-media-master-releases' | includes/class-mlmm-updater.php (class const) |
| PLUGIN_SLUG | 'ml-media-master' | includes/class-mlmm-updater.php (class const) |
| UPDATE_URI | 'https://github.com/mlopesdesign/ml-media-master-releases' | includes/class-mlmm-updater.php (class const) |
| API_URL | 'https://api.github.com/repos/mlopesdesign/ml-media-master-r | includes/class-mlmm-updater.php (class const) |
| TRANSIENT_KEY | 'mlmm_github_release_cache' | includes/class-mlmm-updater.php (class const) |
| CACHE_TTL | 1800 | includes/class-mlmm-updater.php (class const) |
| ERROR_RETRY_TTL | 300 | includes/class-mlmm-updater.php (class const) |
| CACHE_STORAGE_TTL | 604800 | includes/class-mlmm-updater.php (class const) |
| REQUIRES_WORDPRESS | '6.0' | includes/class-mlmm-updater.php (class const) |
| REQUIRES_PHP | '8.1' | includes/class-mlmm-updater.php (class const) |
| OPTION_KEY | 'ml_media_master_settings' | ml-media-master.php (class const) |
| VERSION | '2.10.2' | ml-media-master.php (class const) |
| WEBP_QUEUE_OPTION | 'mlmm_webp_queue' | ml-media-master.php (class const) |
| WEBP_QUEUE_FAILED_OPTION | 'mlmm_webp_queue_failed' | ml-media-master.php (class const) |
| WEBP_QUEUE_HOOK | 'mlmm_process_webp_queue' | ml-media-master.php (class const) |
| WEBP_QUEUE_LOCK | 'mlmm_webp_queue_lock' | ml-media-master.php (class const) |
| WEBP_QUEUE_MAX | 2000 | ml-media-master.php (class const) |
| WEBP_QUEUE_FAILED_MAX | 5000 | ml-media-master.php (class const) |
| WEBP_QUEUE_KEPT_OPTION | 'mlmm_webp_queue_kept' | ml-media-master.php (class const) |
| WEBP_QUEUE_KEPT_MAX | 20000 | ml-media-master.php (class const) |
| WEBP_QUEUE_DELETED_OPTION | 'mlmm_webp_queue_deleted' | ml-media-master.php (class const) |
| WEBP_QUEUE_BATCH | 10 | ml-media-master.php (class const) |
| WEBP_QUEUE_TIME_BUDGET | 20 | ml-media-master.php (class const) |
| WEBP_QUEUE_MAX_ATTEMPTS | 3 | ml-media-master.php (class const) |
| ADMIN_TOAST_TRANSIENT | 'ml_media_master_admin_toast' | ml-media-master.php (class const) |
| VERSION_SEEN_OPTION | 'ml_media_master_version_seen' | ml-media-master.php (class const) |

## 5. Hooks

### Registrados (30)

| Tipo | Hook | Callback | Prio | Onde |
|---|---|---|---|---|
| action | `admin_init` | [__CLASS__, 'maybe_auto_trial'] | 5 | includes/class-mlmm-license.php:93 |
| action | `admin_init` | [__CLASS__, 'guard_settings_post'] | 1 | includes/class-mlmm-license.php:94 |
| action | `admin_post_mlmm_activate_license` | [__CLASS__, 'handle_activate'] | 10 | includes/class-mlmm-license.php:97 |
| action | `admin_post_mlmm_sync_license` | [__CLASS__, 'handle_sync'] | 10 | includes/class-mlmm-license.php:98 |
| action | `admin_post_mlmm_remove_license` | [__CLASS__, 'handle_remove'] | 10 | includes/class-mlmm-license.php:99 |
| filter | `update_plugins_github.com` | [$this, 'filter_update_uri'] | 10 | includes/class-mlmm-updater.php:48 |
| filter | `pre_set_site_transient_update_plugins` | [$this, 'inject_update'] | 20 | includes/class-mlmm-updater.php:51 |
| filter | `site_transient_update_plugins` | [$this, 'inject_update'] | 20 | includes/class-mlmm-updater.php:52 |
| filter | `plugins_api` | [$this, 'plugin_info'] | 20 | includes/class-mlmm-updater.php:54 |
| action | `delete_site_transient_update_plugins` | [$this, 'invalidate_release_cache'] | 10 | includes/class-mlmm-updater.php:59 |
| action | `admin_init` | [$this, 'maybe_invalidate_on_manual_check'] | 1 | includes/class-mlmm-updater.php:60 |
| action | `upgrader_process_complete` | [$this, 'flush_cache_after_update'] | 10 | includes/class-mlmm-updater.php:61 |
| action | `admin_menu` | [$this, 'register_admin_menu'] | 10 | ml-media-master.php:140 |
| action | `admin_init` | [$this, 'register_settings'] | 10 | ml-media-master.php:141 |
| action | `admin_enqueue_scripts` | [$this, 'enqueue_admin_assets'] | 10 | ml-media-master.php:142 |
| action | `template_redirect` | [$this, 'maybe_redirect_missing_upload_to_webp'] | 1 | ml-media-master.php:143 |
| filter | `wp_handle_upload` | [$this, 'process_upload'] | 20 | ml-media-master.php:145 |
| filter | `wp_generate_attachment_metadata` | [$this, 'fix_metadata_after_webp'] | 20 | ml-media-master.php:146 |
| filter | `intermediate_image_sizes_advanced` | [$this, 'filter_intermediate_sizes'] | 999 | ml-media-master.php:148 |
| filter | `big_image_size_threshold` | [$this, 'filter_big_image_threshold'] | 999 | ml-media-master.php:149 |
| filter | `wp_get_attachment_url` | [$this, 'filter_attachment_url_to_webp'] | 20 | ml-media-master.php:151 |
| filter | `wp_get_attachment_image_src` | [$this, 'filter_attachment_image_src_to_webp'] | 20 | ml-media-master.php:152 |
| filter | `wp_calculate_image_srcset` | [$this, 'filter_attachment_srcset_to_webp'] | 20 | ml-media-master.php:153 |
| filter | `the_content` | [$this, 'filter_html_content_urls_to_webp'] | 20 | ml-media-master.php:155 |
| filter | `post_thumbnail_html` | [$this, 'filter_html_content_urls_to_webp'] | 20 | ml-media-master.php:156 |
| filter | `widget_text` | [$this, 'filter_html_content_urls_to_webp'] | 20 | ml-media-master.php:157 |
| filter | `widget_block_content` | [$this, 'filter_html_content_urls_to_webp'] | 20 | ml-media-master.php:158 |
| filter | `render_block` | [$this, 'filter_html_content_urls_to_webp'] | 20 | ml-media-master.php:159 |
| action | `mlgp_after_items_stored` | [$this, 'handle_mlgp_items_stored'] | 20 | ml-media-master.php:164 |
| action | `shutdown` | [$this, 'flush_webp_queue'] | 0 | ml-media-master.php:169 |

### Dinâmicos (3)

| Tipo | Expressão | Onde |
|---|---|---|
| action | `$hook` | includes/class-ml-license-client.php:54 |
| action | `$hook` | includes/class-mlmm-license.php:103 |
| action | `self::WEBP_QUEUE_HOOK` | ml-media-master.php:168 |

### Pontos de extensão expostos — do_action / apply_filters (0) 🔒 não renomear

_nenhum_

### Shortcodes 🔒

_nenhum_

### Post types / Cron

- CPTs: _nenhum_
- Cron: `daily`

## 6. Fluxo principal

_A preencher na auditoria do plugin (diagrama ASCII do fluxo de execução)._

## 7. Regras de negócio

_A preencher na auditoria (tabela entrada → saída dos algoritmos críticos)._

## 8. Dados persistidos 🔒

### Options

| Option | Operações |
|---|---|
| `date_format` | get_option |

### Meta

| Tipo:chave | Operações |
|---|---|
| `post:_wp_attached_file` | get |

### Transients

| Transient | Operações |
|---|---|
| `ml_media_master_batch_notice` | delete |

### Tabelas próprias

- `{prefix}mlgp_gallery_items`

## 9. Superfície de segurança

| Indicador | Valor |
|---|---|
| Endpoints AJAX | 0 |
| Rotas REST | 0 |
| Verificações de nonce | 1 |
| $wpdb->prepare() | 9 |
| SQL com variável interpolada (revisar) | 1 |
| uninstall.php | ✅ |

### AJAX

_nenhum_

### REST

_nenhum_

### Capabilities usadas

- `manage_options` ×3
- `update_plugins` ×1

### SQL a revisar

- ml-media-master.php:1803

### Assets enfileirados

_nenhum_

## 10. Ciclo do updater (GitHub → WordPress)

| Checagem | Estado |
|---|---|
| Endpoint GitHub | `api.github.com/repos/mlopesdesign/ml-media-master-releases/releases/latest` |
| Usa /releases/latest | ✅ |
| Plugin Update Checker (PUC) | não |
| Injeta no transient update_plugins | ✅ |
| Responde plugins_api | ✅ |
| Preenche no_update | ✅ |
| Ícones no transient | ✅ |
| Token via constante | — |
| ⚠️ Fallback para zipball (proibido) | não |
| Arquivos do updater | includes/class-mlmm-updater.php |

## 11. Pipeline de release

| Item | Estado |
|---|---|
| .github/workflows/release.yml | ❌ |
| ZIP esperado | `ml-media-master-v2.10.2.zip` com raiz única `ml-media-master/` |
| Tag esperada | `v2.10.2` |

## 12. Checklist pré-release

- [x] Header == constante == Stable tag
- [x] Arquivo principal `ml-media-master/ml-media-master.php`
- [x] readme.txt presente
- [x] uninstall.php presente
- [x] Updater GitHub presente
- [x] Sem fallback para zipball
- [ ] Workflow de release
- [ ] Sem SQL interpolado sem prepare

## 13. Índice de métodos

- **assets/admin.js** — showToast:7
- **includes/class-ml-license-client.php** — __construct:45, cron_hook:61, option_name:66, instance_id:71, activate:77, start_trial:102, refresh:114, deactivate:138, is_premium:154, state:164, label:210, saved:214, save_error:219, store:237, verify:256, b64url:281, post:288
- **includes/class-mlmm-gallery-pro.php** — __construct:27, is_available:34, get_dashboard_summary:47
- **includes/class-mlmm-license.php** — client:57, is_active:71, is_locked:76, forget:82, boot:88, maybe_auto_trial:112, guard_settings_post:127, lock_settings_update:147, locked_message:150, render_locked_notice:156, summary:166, handle_activate:226, handle_sync:238, handle_remove:247, guard_request:253, redirect_with_toast:260
- **includes/class-mlmm-updater.php** — __construct:38, init:46, filter_update_uri:74, inject_update:97, plugin_info:142, get_latest_release:187, parse_release:275, pick_asset_zip_url:312, is_trusted_asset_url:346, build_update_uri_payload:362, build_transient_payload:386, icons:408, store_cache:418, store_api_failure:423, invalidate_release_cache:435, maybe_invalidate_on_manual_check:450, is_force_refresh_request:461, flush_cache_after_update:485
- **ml-media-master.php** — get_current_language:89, t:104, get_logo_url:118, get_admin_js_strings:126, __construct:132, get_default_settings:180, get_settings:199, register_admin_menu:207, enqueue_admin_assets:219, register_settings:251, sanitize_settings:407, render_main_section:465, render_checkbox_field:473, render_number_field:495, render_license_panel:514, consume_admin_toast:600, consume_version_toast:618, render_settings_page:636, render_environment_table:729, redirect_to_plugin_page:746, set_admin_toast:755, is_mlgp_available:768, filter_big_image_threshold:777, filter_intermediate_sizes:791, filter_attachment_url_to_webp:806, filter_attachment_image_src_to_webp:825, filter_attachment_srcset_to_webp:851, filter_html_content_urls_to_webp:880, maybe_redirect_missing_upload_to_webp:910, maybe_swap_upload_url_to_webp:994, resolve_upload_file:1056, is_real_webp:1081, webp_owned_by_other_attachment:1106, queue_webp_conversion:1136, flush_webp_queue:1173, acquire_webp_queue_lock:1206, release_webp_queue_lock:1236, get_webp_queue_failed:1247, save_webp_queue_failed:1257, relink_attachment_to_existing_webp:1270, find_attachment_ids_for_upload_path:1320, process_webp_queue:1386, convert_queued_original:1479, attachment_references_file:1561, finish_webp_attachment:1593, handle_mlgp_items_stored:1661, sync_mlgp_item_record_after_upload:1758, is_gif_path:1898, process_upload:1908, fix_metadata_after_webp:1923, get_shell_resize_binary:2057, has_shell_engine:2073, has_imagick_engine:2077, has_imagick_webp_engine:2081, has_gd_webp_engine:2106, has_php_fallback_engine:2114, has_any_conversion_engine:2118, get_conversion_engine_label:2122, gd_create_image_from_file:2138, resize_with_gd:2150, resize_with_imagick:2220, convert_to_webp_via_php:2260, normalize_and_resize:2314, optimize_intermediate:2346, convert_existing_file_to_webp_targeted:2418, is_valid_generated_file:2560, guess_mime_from_extension:2564, ensure_webp_exists_from_original:2578, convert_to_webp_targeted:2610, run_cwebp:2708, cleanup_temp_webp_candidates:2729, is_exec_available:2740, binary_exists:2754, get_binary_test_commands:2786, get_conversion_quality:2816, convert_file_to_webp_copy:2820, render_mlgp_tab:2869
