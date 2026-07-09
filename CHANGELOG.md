# Changelog

All notable changes to **ML Media Master** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/).

The WordPress.org changelog (used by the WP admin updater) lives in
[`ml-media-master/readme.txt`](./ml-media-master/readme.txt) and stays in lockstep
with this file.

---

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