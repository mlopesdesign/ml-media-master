(function($){
  const state = {processing:false,polling:null,lastAuditScope:'all',lastTerminalToast:'',haltMode:'',haltTimer:null,currentJob:null,suppressInitialTerminalToast:true};

  function request(action, data){
    return $.post(MLMM_BATCH.ajaxUrl, Object.assign({action, nonce: MLMM_BATCH.nonce}, data || {}));
  }

  function parseError(xhr, fallback){
    if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
      return xhr.responseJSON.data.message;
    }
    return fallback || MLMM_BATCH.strings.operationFailed || 'Operation failed.';
  }

  function showToast(message, type, timeout){
    const text = (message || '').toString().trim();
    if(!text) return;
    const cls = type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'success');
    const $area = $('#mlmm-toast-area');
    const $toast = $('<div class="mlmm-toast mlmm-toast-'+cls+'" />').text(text);
    $area.append($toast);
    window.setTimeout(function(){ $toast.addClass('is-visible'); }, 10);
    window.setTimeout(function(){
      $toast.removeClass('is-visible');
      window.setTimeout(function(){ $toast.remove(); }, 220);
    }, timeout || (type === 'error' ? 7000 : 5000));
  }


  function maybeShowDeferredToasts(){
    const $deferred = $('#mlmm-deferred-toast');
    if($deferred.length){
      showToast($deferred.data('message') || MLMM_BATCH.strings.saved || 'Configurações salvas.', $deferred.data('type') || 'success', parseInt($deferred.data('timeout') || 5000, 10));
      $deferred.remove();
    }
    const $version = $('#mlmm-version-toast');
    if($version.length){
      showToast($version.data('message') || MLMM_BATCH.strings.whatsNewTitle || 'Novidades da versão', $version.data('type') || 'success', parseInt($version.data('timeout') || 10000, 10));
      $version.remove();
    }
  }

  function withButton($button, busyText, callback){
    const originalText = $button.data('mlmm-original-text') || $button.text();
    $button.data('mlmm-original-text', originalText);
    $button.prop('disabled', true).addClass('is-busy').text(busyText || MLMM_BATCH.strings.processing || 'Processando...');
    const restore = function(){
      $button.prop('disabled', false).removeClass('is-busy').text(originalText);
    };
    let result;
    try {
      result = callback();
    } catch (err) {
      restore();
      throw err;
    }
    if(result && typeof result.always === 'function'){
      result.always(restore);
    } else {
      restore();
    }
    return result;
  }

  function setButtons(status){
    const active = ['running','starting','stopping','pausing'].includes(status);
    $('#mlmm-pause-job, #mlmm-pause-job-lote').prop('disabled', status !== 'running');
    $('#mlmm-resume-job, #mlmm-resume-job-lote').prop('disabled', !['paused','pausing'].includes(status));
    $('#mlmm-stop-job, #mlmm-stop-job-lote').prop('disabled', !['running','paused','pausing','starting','stopping'].includes(status));
    updateStartButtons(status);
  }

  function normalizeScopeStat(raw){
    const stat = Object.assign({total:0, converted:0, pending:0, progress:0}, raw || {});
    stat.total = parseInt(stat.total || 0, 10) || 0;
    stat.converted = parseInt(stat.converted || 0, 10) || 0;
    stat.pending = parseInt(stat.pending || 0, 10) || 0;

    if(stat.total < 1){
      stat.progress = 0;
      return stat;
    }

    if(stat.pending <= 0){
      stat.pending = 0;
      stat.progress = 0;
      return stat;
    }

    const done = Math.max(0, stat.total - stat.pending);
    let progress = (done / stat.total) * 100;
    if(progress < 0){
      progress = 0;
    } else if(progress > 100){
      progress = 100;
    }
    stat.progress = Math.round(progress * 100) / 100;
    return stat;
  }


  function clearScopeBars(){
    $('.mlmm-scope-card').each(function(){
      $(this).find('.mlmm-stat-progress').text('0');
      $(this).find('.mlmm-mini-progress > div').css('width', '0%');
    });
    updateStartButtons($('#mlmm-job-panel').attr('data-job-status') || 'idle');
    updateGhostWarning($('#mlmm-job-panel').attr('data-job-status') || 'idle');
  }

  function getPendingFromCards(){
    const pending = {};
    $('.mlmm-scope-card').each(function(){
      const scope = $(this).data('scope');
      pending[scope] = parseInt($(this).find('.mlmm-stat-pending').text() || 0, 10) || 0;
    });
    return pending;
  }

  function updateStartButtons(status){
    const active = ['running','starting','stopping','pausing'].includes(status);
    const pending = getPendingFromCards();
    $('.mlmm-start-batch').each(function(){
      const scope = $(this).data('scope');
      const shouldDisable = active || status === 'paused' || ((pending[scope] || 0) <= 0);
      $(this).prop('disabled', shouldDisable);
    });
  }

  function updateGhostWarning(status){
    const pending = getPendingFromCards();
    let inconsistent = false;
    $('.mlmm-scope-card').each(function(){
      const converted = parseInt($(this).find('.mlmm-stat-converted').text() || 0, 10) || 0;
      const scope = $(this).data('scope');
      if((pending[scope] || 0) > 0 && converted > 0){
        inconsistent = true;
      }
    });
    const $warning = $('#mlmm-lote-warning');
    if((status === 'idle' || !status) && inconsistent){
      $warning.text(MLMM_BATCH.strings.ghostStateWarning || 'No active batch was found. The numbers shown are only saved statistics. You can start the batch again or reset the state.').show();
    } else {
      $warning.hide().text('');
    }
  }

  function renderScopeStats(stats){
    Object.keys(stats || {}).forEach(function(scope){
      const box = $('.mlmm-scope-card[data-scope="'+scope+'"]');
      if(!box.length) return;
      const stat = normalizeScopeStat(stats[scope]);
      box.find('.mlmm-stat-total').text(stat.total);
      box.find('.mlmm-stat-converted').text(stat.converted);
      box.find('.mlmm-stat-pending').text(stat.pending);
      box.find('.mlmm-stat-updated').text(stat.updated_at || 'ainda não calculada');
      box.find('.mlmm-stat-progress').text(stat.progress);
      box.find('.mlmm-mini-progress > div').css('width', stat.progress + '%');
    });
    const currentStatus = $('#mlmm-job-panel').attr('data-job-status') || 'idle';
    updateStartButtons(currentStatus);
    updateGhostWarning(currentStatus);
  }

  function renderJob(job){
    job = job || {};
    state.currentJob = job;
    const status = job.status || 'idle';
    $('#mlmm-job-panel').attr('data-job-status', status);
    $('#mlmm-job-status-label, #mlmm-job-status-label-lote').text((MLMM_BATCH.strings[status] || status));
    $('#mlmm-job-scope, #mlmm-job-scope-lote').text(job.scope || '-');
    $('#mlmm-job-current-item, #mlmm-job-current-item-lote').text(job.current_item || '-');
    $('#mlmm-progress-bar').css('width', (job.progress || 0) + '%');
    $('#mlmm-count-total').text(job.total || 0);
    $('#mlmm-count-processed').text(job.processed || 0);
    $('#mlmm-count-converted').text(job.converted || 0);
    $('#mlmm-count-existing').text(job.already_exists || 0);
    $('#mlmm-count-skipped').text(job.skipped || 0);
    $('#mlmm-count-errors').text(job.errors || 0);
    $('#mlmm-count-deleted').text(job.deleted_originals || 0);
    $('#mlmm-recent-events').val((job.recent_events || []).join('\n'));
    setButtons(status);
    updateGhostWarning(status);
    $('.mlmm-start-batch').each(function(){
      const $btn = $(this);
      const originalText = $btn.data('mlmm-original-text') || $btn.text();
      const scope = $btn.data('scope');
      if(scope === job.scope && ['running','pausing','paused','stopping'].includes(status)){
        const total = parseInt(job.total || 0, 10) || 0;
        const processed = parseInt(job.processed || 0, 10) || 0;
        let prefix = 'Lote';
        if(status === 'pausing'){ prefix = 'Pausando'; }
        else if(status === 'paused'){ prefix = 'Pausado'; }
        else if(status === 'stopping'){ prefix = 'Parando'; }
        $btn.text(prefix + ' ' + processed + '/' + total);
      } else if(!$btn.hasClass('is-busy')) {
        $btn.text(originalText);
      }
    });

    if(['finished','aborted','error'].includes(status)){
      const signature = [status, job.scope || '', job.processed || 0, job.converted || 0, job.errors || 0, job.deleted_originals || 0].join('|');
      if(state.suppressInitialTerminalToast){
        state.lastTerminalToast = signature;
        state.suppressInitialTerminalToast = false;
      } else if(state.lastTerminalToast !== signature){
        state.lastTerminalToast = signature;
        if(status === 'finished'){
          const tpl = MLMM_BATCH.strings.batchFinishedToast || 'Batch finished: %1$d processed, %2$d converted, %3$d errors, %4$d originals removed.';
          showToast(tpl
            .replace('%1$d', (job.processed || 0))
            .replace('%2$d', (job.converted || 0))
            .replace('%3$d', (job.errors || 0))
            .replace('%4$d', (job.deleted_originals || 0)), 'success', 5000);
        } else if(status === 'aborted'){
          showToast((MLMM_BATCH.strings.batchAbortedToast || 'Batch aborted. Processed: %d.').replace('%d', (job.processed || 0)), 'warning');
        } else {
          showToast(MLMM_BATCH.strings.batchErrorToast || 'The batch ended with an error. Check the report.', 'error');
        }
      }
    } else if(state.suppressInitialTerminalToast){
      state.suppressInitialTerminalToast = false;
    }
  }

  function renderReport(html){ $('#mlmm-report-panel').html(html || '<p><em>Nenhum relatório disponível.</em></p>'); }
  function renderQuarantinePanels(auditHtml, statusHtml){
    if(auditHtml !== undefined){ $('#mlmm-quarantine-audit-panel').html(auditHtml || '<p><em>Nenhuma auditoria realizada ainda.</em></p>'); }
    if(statusHtml !== undefined){ $('#mlmm-quarantine-status-panel').html(statusHtml || ''); }
  }

  function monitorHalt(targetStatus){
    if(state.haltTimer){ window.clearTimeout(state.haltTimer); }
    const allowed = targetStatus === 'paused' ? ['paused','aborted','finished','idle'] : ['aborted','finished','idle'];
    const poll = function(){
      request('mlmm_get_batch_status',{}).done(function(resp){
        if(resp.success){
          renderJob(resp.data.job);
          if(resp.data.report_html){ renderReport(resp.data.report_html); }
          const status = (resp.data.job && resp.data.job.status) || 'idle';
          if(allowed.includes(status)){
            state.haltMode = '';
            state.haltTimer = null;
            refreshStats(true);
            return;
          }
          state.haltTimer = window.setTimeout(poll, 700);
        }
      }).fail(function(){
        state.haltTimer = window.setTimeout(poll, 1000);
      });
    };
    state.haltTimer = window.setTimeout(poll, 500);
  }


  function bindBatchControl(selector, action, busyText, successText, successHandler, confirmMessage){
    $(document).on('click', selector, function(){
      if(confirmMessage && !confirm(confirmMessage)) return;
      const $btn = $(this);
      withButton($btn, busyText, function(){
        return request(action,{}).done(function(resp){
          if(resp.success){
            renderJob(resp.data.job);
            if(successText){ showToast(successText, 'success'); }
            if(typeof successHandler === 'function'){ successHandler(resp); }
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, MLMM_BATCH.strings.operationFailed || 'Operation failed.'), 'error');
        });
      });
    });
  }

  function activateTab(tab){
    const target = (tab || 'dashboard').toString();
    $('.mlmm-tab-button').removeClass('is-active').attr('aria-selected','false');
    $('.mlmm-tab-panel').removeClass('is-active');
    $('.mlmm-tab-button[data-tab="'+target+'"]').addClass('is-active').attr('aria-selected','true');
    $('.mlmm-tab-panel[data-tab-panel="'+target+'"]').addClass('is-active');
    try { window.localStorage.setItem('mlmm-active-tab', target); } catch(err) {}
  }

  function refreshStats(silent){
    return request('mlmm_refresh_stats',{}).done(function(resp){
      if(resp.success){
        renderScopeStats(resp.data.stats);
        if(!silent){ showToast(MLMM_BATCH.strings.refreshSuccess || 'Pending items refreshed successfully.', 'success'); }
      }
    }).fail(function(xhr){
      showToast(parseError(xhr, MLMM_BATCH.strings.refreshFail || 'Failed to refresh pending items.'), 'error');
    });
  }

  function processLoop(){
    if(state.processing) return;
    const status = $('#mlmm-job-panel').attr('data-job-status');
    if(!['running','stopping'].includes(status)) return;
    state.processing = true;
    request('mlmm_process_batch',{}).done(function(resp){
      if(resp.success){
        renderJob(resp.data.job);
        if(['running','stopping'].includes(resp.data.job.status) && !state.haltMode){ setTimeout(processLoop,300); }
        if(['finished','aborted','error'].includes(resp.data.job.status)){
          refreshStatus();
          refreshStats(true);
        }
      }
    }).fail(function(xhr){
      showToast(parseError(xhr, MLMM_BATCH.strings.processFail || 'Failed to process batch.'), 'error');
    }).always(function(){ state.processing = false; });
  }

  function refreshStatus(){
    request('mlmm_get_batch_status',{}).done(function(resp){
      if(resp.success){
        renderJob(resp.data.job);
        if(resp.data.report_html){ renderReport(resp.data.report_html); }
        if(['running','stopping'].includes(resp.data.job.status) && !state.haltMode){ processLoop(); }
      }
    });
  }

  $(function(){
    maybeShowDeferredToasts();
    const initialTab = window.location.hash ? window.location.hash.replace('#mlmm-tab-','') : (function(){ try { return window.localStorage.getItem('mlmm-active-tab') || 'dashboard'; } catch(err){ return 'dashboard'; } })();
    activateTab(initialTab);

    $('.mlmm-tab-button').on('click', function(){
      const tab = $(this).data('tab') || 'dashboard';
      activateTab(tab);
      if(window.history && window.history.replaceState){ window.history.replaceState(null, '', '#mlmm-tab-' + tab); }
    });

    refreshStatus();
    refreshStats(true);
    request('mlmm_quarantine_status',{}).done(function(resp){ if(resp.success){ renderQuarantinePanels(resp.data.audit_html, resp.data.status_html); } });

    $('.mlmm-start-batch').on('click', function(){
      const $btn = $(this);
      const scope = $btn.data('scope');
      withButton($btn, MLMM_BATCH.strings.startBusy || 'Starting...', function(){
        return request('mlmm_start_batch',{scope}).done(function(resp){
          if(resp.success){
            state.lastTerminalToast = '';
            state.haltMode = '';
            renderJob(resp.data.job);
            showToast((MLMM_BATCH.strings.startSuccess || 'Batch started for scope %s.').replace('%s', scope), 'success');
            processLoop();
          } else {
            showToast(resp.data && resp.data.message ? resp.data.message : MLMM_BATCH.strings.startFail || 'Failed to start batch.', 'error');
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, MLMM_BATCH.strings.startFail || 'Failed to start batch.'), 'error');
        });
      });
    });

    $('#mlmm-refresh-stats').on('click', function(){
      withButton($(this), MLMM_BATCH.strings.refreshBusy || 'Refreshing...', function(){ return refreshStats(false); });
    });

    $('#mlmm-test-environment').on('click', function(){
      const $btn = $(this);
      withButton($btn, MLMM_BATCH.strings.testBusy || 'Testing...', function(){
        return request('mlmm_test_environment',{}).done(function(resp){
          if(resp.success){
            $('#mlmm-environment-table').html(resp.data.html || '');
            showToast(MLMM_BATCH.strings.envTestSuccess || 'Environment tested successfully.', 'success');
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, MLMM_BATCH.strings.envTestFail || 'Failed to test environment.'), 'error');
        });
      });
    });

    bindBatchControl('#mlmm-pause-job, #mlmm-pause-job-lote', 'mlmm_pause_batch', MLMM_BATCH.strings.pauseBusy || 'Pausing...', MLMM_BATCH.strings.pauseRequested || 'Pause requested.', function(){
      state.haltMode = 'paused';
      monitorHalt('paused');
    });

    bindBatchControl('#mlmm-resume-job, #mlmm-resume-job-lote', 'mlmm_resume_batch', MLMM_BATCH.strings.resumeBusy || 'Resuming...', MLMM_BATCH.strings.resumeSuccess || 'Batch resumed.', function(){
      state.haltMode = '';
      if(state.haltTimer){ window.clearTimeout(state.haltTimer); state.haltTimer = null; }
      processLoop();
    });

    bindBatchControl('#mlmm-stop-job, #mlmm-stop-job-lote', 'mlmm_stop_batch', MLMM_BATCH.strings.stopBusy || 'Stopping...', MLMM_BATCH.strings.stopSuccess || 'Stop requested.', function(){
      state.haltMode = 'stopping';
      monitorHalt('stopping');
    });

    $('#mlmm-reset-job').on('click', function(){
      if(!confirm('Resetar o estado do lote atual?')) return;
      const $btn = $(this);
      withButton($btn, MLMM_BATCH.strings.resetBusy || 'Resetting...', function(){
        return request('mlmm_reset_batch_state',{}).done(function(resp){ renderJob({status:'idle'}); renderReport(resp.data.report_html || ''); if(resp.data && resp.data.stats){ renderScopeStats(resp.data.stats); } clearScopeBars(); showToast('Estado do lote resetado.', 'success'); });
      });
    });

    $('#mlmm-clear-report').on('click', function(){
      if(!confirm('Limpar o último relatório?')) return;
      const $btn = $(this);
      withButton($btn, MLMM_BATCH.strings.reportBusy || 'Clearing...', function(){
        return request('mlmm_clear_report',{}).done(function(resp){ renderReport(resp.data.report_html || ''); if(resp.data && resp.data.stats){ renderScopeStats(resp.data.stats); } clearScopeBars(); showToast(MLMM_BATCH.strings.reportSuccess || 'Report cleared successfully.', 'success'); });
      });
    });

    $('.mlmm-quarantine-audit').on('click', function(){
      const $btn = $(this);
      const scope = $btn.data('scope') || 'all';
      state.lastAuditScope = scope;
      withButton($btn, 'Auditando...', function(){
        return request('mlmm_quarantine_audit', {scope}).done(function(resp){
          if(resp.success){
            renderQuarantinePanels(resp.data.audit_html, resp.data.status_html);
            const audit = resp.data.audit || {};
            showToast('Auditoria concluída: ' + (audit.candidates || 0) + ' candidatos encontrados.', 'success');
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, 'Falha na auditoria.'), 'error');
        });
      });
    });

    $('#mlmm-quarantine-refresh').on('click', function(){
      const $btn = $(this);
      withButton($btn, MLMM_BATCH.strings.refreshBusy || 'Refreshing...', function(){
        return request('mlmm_quarantine_status',{}).done(function(resp){ if(resp.success){ renderQuarantinePanels(resp.data.audit_html, resp.data.status_html); showToast(MLMM_BATCH.strings.quarantineStatusSuccess || 'Quarantine status updated.', 'success'); } }).fail(function(xhr){ showToast(parseError(xhr, 'Falha ao atualizar a quarentena.'), 'error'); });
      });
    });

    $('#mlmm-quarantine-move').on('click', function(){
      if(!confirm('Mover para quarentena todos os candidatos da última auditoria?')) return;
      const $btn = $(this);
      withButton($btn, 'Movendo...', function(){
        return request('mlmm_quarantine_move',{scope: state.lastAuditScope}).done(function(resp){
          if(resp.success){
            renderQuarantinePanels(resp.data.audit_html, resp.data.status_html);
            refreshStats(true);
            const report = resp.data.report || {};
            showToast('Quarentena concluída: ' + (report.moved || 0) + ' arquivos movidos.', 'success');
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, 'Falha ao mover arquivos para quarentena.'), 'error');
        });
      });
    });

    $('#mlmm-quarantine-restore').on('click', function(){
      if(!confirm('Restaurar os arquivos atualmente na quarentena?')) return;
      const $btn = $(this);
      withButton($btn, 'Restaurando...', function(){
        return request('mlmm_quarantine_restore',{scope:'all'}).done(function(resp){
          if(resp.success){
            renderQuarantinePanels(undefined, resp.data.status_html);
            refreshStats(true);
            const report = resp.data.report || {};
            showToast('Quarentena restaurada: ' + (report.restored || 0) + ' arquivos restaurados.', 'success');
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, 'Falha ao restaurar a quarentena.'), 'error');
        });
      });
    });

    $('#mlmm-quarantine-clear').on('click', function(){
      if(!confirm('Apagar definitivamente os arquivos da quarentena?')) return;
      const $btn = $(this);
      withButton($btn, MLMM_BATCH.strings.reportBusy || 'Clearing...', function(){
        return request('mlmm_quarantine_clear',{scope:'all'}).done(function(resp){
          if(resp.success){
            renderQuarantinePanels(undefined, resp.data.status_html);
            const report = resp.data.report || {};
            showToast('Quarentena limpa com sucesso: ' + (report.deleted || 0) + ' arquivos removidos.', 'success');
          }
        }).fail(function(xhr){
          showToast(parseError(xhr, 'Falha ao limpar a quarentena.'), 'error');
        });
      });
    });
  });
})(jQuery);
