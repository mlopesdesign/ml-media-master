# Changelog

All notable changes to **ML Media Master** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/).

The WordPress.org changelog (used by the WP admin updater) lives in
[`ml-media-master/readme.txt`](./ml-media-master/readme.txt) and stays in lockstep
with this file.

---

## [2.10.2] - 2026-10-25

### Corrigido
- Crítico: `maybe_swap_upload_url_to_webp()` (filtros `wp_get_attachment_url`, `wp_get_attachment_image_src`, `wp_calculate_image_srcset`, `the_content`, `render_block` etc.) convertia para WebP de forma síncrona durante a renderização; fora do wp-admin dava erro fatal `Call to undefined function wp_tempnam()` (HTTP 500 no front-end, visto em produção) e repetia conversões falhas a cada visita.
- Front só troca a URL se o WebP existe. Original sem WebP (ou com WebP irmão + JPG ainda no disco e `delete_original` ligado) vai para a fila `mlmm_webp_queue`, processada por `mlmm_process_webp_queue` (WP-Cron): 10 por lote, orçamento de tempo limitado por `max_execution_time`, trava atômica (`INSERT IGNORE` em `mlmm_webp_queue_lock`; trava de processo morto > 10 min é tomada), progresso e tentativa gravados por item antes de converter (fatal conta tentativa; máx. 3 em `mlmm_webp_queue_failed`).
- Fila → anexo: mesmo caminho do upload (`fix_metadata_after_webp`); anexos já em WebP com tamanho/original JPG que sobrou → `finish_webp_attachment()`; anexos duplicados → `relink_attachment_to_existing_webp()`. Sucesso só quando nenhum anexo aponta mais para o arquivo. Arquivo solto em uploads (sem anexo): gera o WebP e apaga o original (`delete_original`); o 404 do .jpg/.png redireciona ao .webp. ML Gallery Pro (`wp-content/ml-gallery`): conversão + religação da tabela + exclusão no upload (`mlgp_after_items_stored`), sem mudança. GIF nunca é convertido.
- WebP de mesmo nome que pertence a outro anexo (`webp_owned_by_other_attachment()`) ou que não passa em `is_real_webp()` (truncado/animado) nunca é reaproveitado, apagado ou sobrescrito: a conversão daquele arquivo falha e nada muda. Arquivo solto é apagado uma única vez (`mlmm_webp_queue_deleted`): se um gerador de miniatura sob demanda o recriar, ele fica. Registro separado `mlmm_webp_queue_kept` para convertidos que mantêm o original (Remoção segura desligada).
- Travessia de diretório: caminhos resolvidos com `realpath()` e aceitos só dentro de uploads (fila, cron e redirect 404).
- Validação real antes de apagar qualquer original (`is_real_webp()`: assinatura WebP, dimensões e decodificação GD). WebP truncado existente é refeito. Tamanhos gravados em temporário + `rename`.
- Upload com redimensionamento do WP: o JPG/PNG de `metadata['original_image']` é apagado junto e sai do metadata; o 404 do nome original redireciona para `-scaled.webp`/`-rotated.webp`.
- `wp_tempnam()` carregado fora do painel; desativação desagenda a fila; `uninstall.php` remove fila, falhas e trava.

## [2.10.1] - 2026-10-21

### Corrigido
- Crítico: com o plugin ativo sozinho (sem outro plugin ML que carregasse o cliente de licença antes), o site inteiro caía com "Class ML_License_Client_V2 not found". O cliente de licença agora é carregado sempre.

## [2.10.0] - 2026-10-17

### Changed
- Licença pelo site tools.mlopesdesign.com.br (ML License Hub, cliente `ML_License_Client_V2` em `includes/class-ml-license-client.php`; integração em `includes/class-mlmm-license.php`). O servidor antigo `license.mlopesdesign.com.br/api/license.php` não é mais chamado; a option antiga `ml_media_master_license_state` fica no banco.
- Aceita a chave do Pacote Agência. Teste grátis de 30 dias por site, iniciado sozinho na primeira visita ao painel (`mlmm_auto_trial_tried`).
- Sem teste/licença: só a gravação das configurações trava (options.php + `pre_update_option_ml_media_master_settings`). Conversão WebP no upload, ML Gallery Pro, troca de URLs no front-end e redirecionamento de uploads antigos continuam.
- Aba Licença refeita (sem servidor, Product ID e fingerprint); aviso discreto no painel quando sem licença; links `#mlmm-tab-<aba>` abrem a aba certa.
- `uninstall.php` desagenda a renovação diária (`mllc_ml_media_master_refresh`); a licença local fica.

## [2.9.60.0] - 2026-10-14

### Changed
- Updater consulta `ml-media-master-releases` (público, só ZIPs); `Update URI` e URL confiável do asset atualizados. Código-fonte passa a ser privado.
- Ícones no transient, no `update_plugins_github.com` e no `plugins_api`.

## [2.9.59.0] - 2026-06-27

### Fixed
- **Definitivo do auto-updater GitHub/WordPress:** adicionado `Update URI` oficial;
  integração nativa `update_plugins_github.com`; cache migrado para site transient
  com revalidação `ETag`/`Last-Modified`; o botão "Verificar novamente" agora
  invalida imediatamente o cache próprio; transient `update_plugins` já armazenado
  também é reparado; seleção do pacote exige o asset ZIP versionado exato da
  release e nunca aceita ZIP arbitrário ou source archive; respostas de
  update/no_update incluem metadados de compatibilidade; limpeza pós-update
  cobre atualização individual e em lote; removido o renomeador pós-instalação
  frágil.

### Preserved
- Slug, pasta raiz, arquivo principal, configurações, licença, conversão
  WordPress e integração ML Gallery Pro.

---

## [2.9.57.0] - 2026-06-26

### Changed
- **Limpeza estrutural pós-auditoria:** removidas todas as telas, abas e botões
  mortos (Lote, Auditoria, Quarentena & Resíduos, Relatórios, painéis de
  auditoria/lote do ML Gallery Pro) que dependiam de AJAX inexistente; removidos
  ~140 métodos órfãos de batch/recount/quarentena/relink/sync/NGG; removido
  `cli-batch.php` e bootstrap CLI; removido o sweep de banco
  `sync_upload_references` no upload; JS reescrito mínimo (abas + toasts, zero
  chamadas AJAX); settings fantasmas removidas; `uninstall.php` corrigido;
  display name unificado como **ML Media Master**; reincluído o auto-updater
  GitHub (`includes/class-mlmm-updater.php`) usando asset ZIP da Release, nunca
  o zipball.

### Preserved
- Conversão automática JPG/JPEG/PNG→WebP no upload WordPress.
- Conversão automática ML Gallery Pro via `mlgp_after_items_stored`.
- GIF nunca convertido.
- Licenciamento Free/Full e sistema de toasts.

---

## Earlier versions

Veja [`ml-media-master/readme.txt`](./ml-media-master/readme.txt) para o histórico
completo desde a v2.9.18.0.