/**
 * ML Media Master — admin UI (tabs + toasts only).
 * No AJAX. All conversion is automatic at upload time.
 */
(function ($) {
  'use strict';

  function showToast(message, type, timeout) {
    var $area = $('#mlmm-toast-area');
    if (!$area.length || !message) {
      return;
    }

    var cls = 'mlmm-toast mlmm-toast-' + (type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'success'));
    var $toast = $('<div/>', { 'class': cls, text: message, role: 'status' });

    $area.append($toast);
    window.requestAnimationFrame(function () {
      $toast.addClass('is-visible');
    });

    window.setTimeout(function () {
      $toast.fadeOut(250, function () {
        $toast.remove();
      });
    }, Math.max(2000, parseInt(timeout || 5000, 10)));
  }

  $(function () {
    // Tab navigation.
    $('.mlmm-tab-button').on('click', function () {
      var target = $(this).data('tab');
      if (!target || $(this).is(':disabled')) {
        return;
      }
      $('.mlmm-tab-button').removeClass('is-active').attr('aria-selected', 'false');
      $('.mlmm-tab-panel').removeClass('is-active');
      $('.mlmm-tab-button[data-tab="' + target + '"]').addClass('is-active').attr('aria-selected', 'true');
      $('.mlmm-tab-panel[data-tab-panel="' + target + '"]').addClass('is-active');
    });

    // Deferred toast (settings saved, license actions).
    var $deferred = $('#mlmm-deferred-toast');
    if ($deferred.length) {
      showToast(
        $deferred.data('message') || (window.MLMM_ADMIN && MLMM_ADMIN.strings && MLMM_ADMIN.strings.saved) || 'Configurações salvas.',
        $deferred.data('type') || 'success',
        parseInt($deferred.data('timeout') || 5000, 10)
      );
      $deferred.remove();
    }

    // One-time version news toast after update.
    var $version = $('#mlmm-version-toast');
    if ($version.length) {
      showToast(
        $version.data('message') || (window.MLMM_ADMIN && MLMM_ADMIN.strings && MLMM_ADMIN.strings.whatsNewTitle) || 'Novidades da versão',
        $version.data('type') || 'success',
        parseInt($version.data('timeout') || 10000, 10)
      );
      $version.remove();
    }
  });
})(jQuery);
