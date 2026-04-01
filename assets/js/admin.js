(function () {
  'use strict';

  const startButton = document.getElementById('cic-start-btn');
  const stopButton = document.getElementById('cic-stop-btn');
  const applyRecommendedButton = document.getElementById('cic-apply-recommended-btn');

  if (!startButton || !stopButton || !applyRecommendedButton || typeof cicAdminConfig === 'undefined') {
    return;
  }

  const byId = (id) => document.getElementById(id);
  const toast = byId('cic-toast');
  let statusRequestInFlight = false;
  let actionRequestInFlight = false;
  const applyRecommendedDefaultText = applyRecommendedButton.textContent;
  let applyRecommendedRestoreTimer = null;
  let toastTimer = null;
  const i18n = cicAdminConfig.i18n || {};

  const t = (key, fallback) => {
    const value = i18n[key];
    return typeof value === 'string' && value !== '' ? value : fallback;
  };

  const tf = (key, fallback, value) => {
    const template = t(key, fallback);
    return template.includes('%d') ? template.replace('%d', String(value)) : `${template} ${value}`;
  };

  const request = (action) => {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', cicAdminConfig.nonce);

    return fetch(cicAdminConfig.ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(async (response) => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      return response.json();
    });
  };

  const formatPercent = (value) => {
    const number = Number(value || 0);
    return `${number.toFixed(2)}%`;
  };

  const showToast = (message, type = 'success', timeout = 2500) => {
    if (!toast) {
      return;
    }

    toast.textContent = String(message || '');
    toast.classList.remove('is-success', 'is-error');
    toast.classList.add(type === 'error' ? 'is-error' : 'is-success');
    toast.classList.add('is-visible');

    if (toastTimer) {
      globalThis.clearTimeout(toastTimer);
    }

    toastTimer = globalThis.setTimeout(() => {
      toast.classList.remove('is-visible');
      toast.classList.remove('is-success', 'is-error');
    }, timeout);
  };

  const setActionButtonsDisabled = (disabled) => {
    startButton.disabled = disabled;
    stopButton.disabled = disabled;
    applyRecommendedButton.disabled = disabled;
  };

  const renderStatus = (payload) => {
    if (!payload) {
      return;
    }

    byId('cic-status-running').textContent = payload.running ? t('running', 'Running') : t('stopped', 'Stopped');
    byId('cic-status-webp-support').textContent = payload.webp_supported ? t('available', 'Available') : t('unavailable', 'Unavailable');
    byId('cic-status-month').textContent =
      `${formatPercent(payload.month.percentage)} (${payload.month.converted} / ${payload.month.total})`;

    byId('cic-status-total').textContent =
      `${formatPercent(payload.total.percentage)} (${payload.total.converted} / ${payload.total.total})`;

    byId('cic-status-total-images').textContent = String(payload.total.total);
    byId('cic-status-pending').textContent = String(payload.pending);

    const monthBatch = payload.last_month_batch || {};
    let summary =
      `${t('monthBatchProcessed', 'processed')}: ${Number(monthBatch.processed || 0)}` +
      `, ${t('monthBatchConverted', 'converted')}: ${Number(monthBatch.converted || 0)}` +
      `, ${t('monthBatchFailed', 'failed')}: ${Number(monthBatch.failed || 0)}`;

    if (monthBatch.updated_at) {
      summary += `, ${t('updatedAt', 'updated at')}: ${monthBatch.updated_at}`;
    }

    byId('cic-status-month-batch').textContent = summary;

    const performance = payload.performance || {};
    let performanceSummary =
      `${t('benchmarkRuns', 'runs')}: ${Number(performance.runs || 0)}` +
      `, ${t('benchmarkLast', 'last')}: ${Number(performance.last_duration_ms || 0)}ms / ${Number(performance.last_processed || 0)} ${t('imagesAbbr', 'imgs')}` +
      `, ${t('benchmarkAvg', 'avg')}: ${Number(performance.average_ms_per_image || 0).toFixed(2)}${t('msPerImage', 'ms/img')}` +
      `, ${t('benchmarkRecommended', 'recommended batch')}: ${Number(performance.recommended_batch_size || 0)}`;

    if (performance.last_run_at) {
      performanceSummary += `, ${t('updatedAt', 'updated at')}: ${performance.last_run_at}`;
    }

    byId('cic-status-performance').textContent = performanceSummary;
  };

  const refreshStatus = () => {
    if (statusRequestInFlight) {
      return;
    }

    statusRequestInFlight = true;

    request('cic_get_status').then((response) => {
      if (response?.success) {
        renderStatus(response.data);
      }
    }).catch(() => {
      byId('cic-status-running').textContent = t('statusErrorShort', 'Status error');
      showToast(t('statusErrorToast', 'Status update failed. Please try again.'), 'error');
    }).finally(() => {
      statusRequestInFlight = false;
    });
  };

  const setApplyRecommendedButtonState = (text, disabled, restoreInMs = 0) => {
    if (applyRecommendedRestoreTimer) {
      globalThis.clearTimeout(applyRecommendedRestoreTimer);
      applyRecommendedRestoreTimer = null;
    }

    applyRecommendedButton.textContent = text;
    applyRecommendedButton.disabled = disabled;

    if (restoreInMs > 0) {
      applyRecommendedRestoreTimer = globalThis.setTimeout(() => {
        applyRecommendedButton.textContent = applyRecommendedDefaultText;
        applyRecommendedButton.disabled = false;
        applyRecommendedRestoreTimer = null;
      }, restoreInMs);
    }
  };

  const runAction = (action, options = {}) => {
    if (actionRequestInFlight) {
      return;
    }

    actionRequestInFlight = true;
    setActionButtonsDisabled(true);

    request(action).then((response) => {
      if (response?.success) {
        renderStatus(response.data);

        if (typeof options.onSuccess === 'function') {
          options.onSuccess(response.data);
        }
      } else {
        if (typeof options.onError === 'function') {
          options.onError();
        }
      }
    }).catch(() => {
      if (typeof options.onError === 'function') {
        options.onError();
      }
    }).finally(() => {
      actionRequestInFlight = false;

      if (!applyRecommendedRestoreTimer) {
        setActionButtonsDisabled(false);
      } else {
        startButton.disabled = false;
        stopButton.disabled = false;
      }
    });
  };

  startButton.addEventListener('click', () => {
    runAction('cic_start_conversion', {
      onSuccess: () => {
        showToast(t('startSuccess', 'Conversion started successfully.'), 'success');
      },
      onError: () => {
        showToast(t('startError', 'Could not start conversion.'), 'error');
      }
    });
  });

  stopButton.addEventListener('click', () => {
    runAction('cic_stop_conversion', {
      onSuccess: () => {
        showToast(t('stopSuccess', 'Conversion stopped.'), 'success');
      },
      onError: () => {
        showToast(t('stopError', 'Could not stop conversion.'), 'error');
      }
    });
  });

  applyRecommendedButton.addEventListener('click', () => {
    if (actionRequestInFlight) {
      return;
    }

    setApplyRecommendedButtonState(t('applying', 'Applying...'), true);

    runAction('cic_apply_recommended_batch', {
      onSuccess: (data) => {
        const responseData = data || {};
        const appliedBatch = Number(responseData.batch_size_applied || 0);
        if (appliedBatch > 0) {
          setApplyRecommendedButtonState(tf('appliedWithValue', 'Applied: %d', appliedBatch), true, 2000);
          showToast(tf('applySuccessWithValue', 'Recommended batch applied: %d.', appliedBatch), 'success');
          return;
        }

        showToast(t('applySuccess', 'Recommended batch applied.'), 'success');
        setApplyRecommendedButtonState(t('applied', 'Applied'), true, 1500);
      },
      onError: () => {
        setApplyRecommendedButtonState(t('applyFailed', 'Apply failed'), true, 2000);
        showToast(t('applyError', 'Could not apply recommended batch.'), 'error');
      }
    });
  });

  refreshStatus();
  globalThis.setInterval(refreshStatus, Number(cicAdminConfig.pollInterval || 10000));
})();
