(function () {
  'use strict';

  const startButton = document.getElementById('cic-start-btn');
  const stopButton = document.getElementById('cic-stop-btn');

  if (!startButton || !stopButton || typeof cicAdminConfig === 'undefined') {
    return;
  }

  const byId = (id) => document.getElementById(id);
  let statusRequestInFlight = false;

  const request = (action) => {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', cicAdminConfig.nonce);

    return fetch(cicAdminConfig.ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then((response) => response.json());
  };

  const formatPercent = (value) => {
    const number = Number(value || 0);
    return `${number.toFixed(2)}%`;
  };

  const renderStatus = (payload) => {
    if (!payload) {
      return;
    }

    byId('cic-status-running').textContent = payload.running ? 'Running' : 'Stopped';
    byId('cic-status-webp-support').textContent = payload.webp_supported ? 'Available' : 'Unavailable';
    byId('cic-status-month').textContent =
      `${formatPercent(payload.month.percentage)} (${payload.month.converted} / ${payload.month.total})`;

    byId('cic-status-total').textContent =
      `${formatPercent(payload.total.percentage)} (${payload.total.converted} / ${payload.total.total})`;

    byId('cic-status-total-images').textContent = String(payload.total.total);
    byId('cic-status-pending').textContent = String(payload.pending);

    const monthBatch = payload.last_month_batch || {};
    let summary =
      `processed: ${Number(monthBatch.processed || 0)}` +
      `, converted: ${Number(monthBatch.converted || 0)}` +
      `, failed: ${Number(monthBatch.failed || 0)}`;

    if (monthBatch.updated_at) {
      summary += `, updated at: ${monthBatch.updated_at}`;
    }

    byId('cic-status-month-batch').textContent = summary;
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
      byId('cic-status-running').textContent = 'Status error';
    }).finally(() => {
      statusRequestInFlight = false;
    });
  };

  startButton.addEventListener('click', () => {
    request('cic_start_conversion').then((response) => {
      if (response?.success) {
        renderStatus(response.data);
      }
    });
  });

  stopButton.addEventListener('click', () => {
    request('cic_stop_conversion').then((response) => {
      if (response?.success) {
        renderStatus(response.data);
      }
    });
  });

  refreshStatus();
  globalThis.setInterval(refreshStatus, 10000);
})();
