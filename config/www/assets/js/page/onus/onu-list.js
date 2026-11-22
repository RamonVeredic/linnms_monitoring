(() => {
  'use strict';

  const oltFilter = document.querySelector('[data-onu-olt-filter]');
  const ponFilter = document.querySelector('[data-onu-pon-filter]');
  if (!(oltFilter instanceof HTMLSelectElement) || !(ponFilter instanceof HTMLSelectElement)) return;

  const syncPonOptions = () => {
    const selectedOlt = String(oltFilter.value || '');
    [...ponFilter.options].forEach((option) => {
      if (option.value === '') {
        option.disabled = false;
        return;
      }
      option.disabled = selectedOlt !== '' && option.dataset.oltDeviceId !== selectedOlt;
    });

    if (ponFilter.selectedOptions[0]?.disabled) ponFilter.value = '';
  };

  oltFilter.addEventListener('change', syncPonOptions);
  syncPonOptions();
})();

(() => {
  'use strict';
  const panel = document.querySelector('.onu-list-panel');
  const table = panel?.querySelector('[data-onu-server-search]');
  const search = document.querySelector('[data-table-search="onusTable"]');
  if (!table || !search) return;
  const pageSize = document.querySelector('[data-onu-page-size]');
  const body = table.tBodies[0];
  const footer = document.querySelector('[data-table-footer="onusTable"]');
  const batchFeedback = document.querySelector('[data-onu-batch-feedback]');
  const batchMessage = batchFeedback.querySelector('[data-onu-batch-message]');
  const batchSpinner = batchFeedback.querySelector('[data-onu-batch-spinner]');
  const batchRetry = batchFeedback.querySelector('[data-onu-batch-retry]');
  const filters = [...document.querySelectorAll('[data-table-filter="onusTable"]')];
  let timer, controller, sequence = 0;
  let currentPage = Number(table.dataset.onuPage) || 1;
  let nextOffset = Number(table.dataset.onuNextOffset) || 0;
  let hasMore = table.dataset.onuHasMore === '1';
  let loading = false;
  let canReuseRows = true;
  let activeFilters;
  const area = table.closest('.table-wrap');
  let previousScrollTop = area.scrollTop;
  let batchFailed = false;
  const header = document.querySelector('[data-onu-table-head]');
  const host = document.createElement('div');
  host.className = 'onu-search-host onu-search-table-host';
  area.before(host);
  host.append(area);
  const overlay = document.createElement('div');
  overlay.className = 'onu-search-status';
  overlay.hidden = true;
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'polite');
  const icon = document.createElement('i');
  icon.className = 'bi bi-arrow-repeat sync-loading-spinner';
  icon.setAttribute('aria-hidden', 'true');
  const label = document.createElement('span');
  const badge = document.createElement('span');
  badge.className = 'onu-loading-badge';
  badge.append(icon);
  overlay.append(badge, label);
  host.append(overlay);
  const positionOverlay = () => {
    const rect = host.getBoundingClientRect();
    const top = Math.max(rect.top, 0);
    const bottom = Math.min(rect.bottom, window.innerHeight);
    overlay.style.top = Math.max(0, top - rect.top) + 'px';
    overlay.style.height = Math.max(0, bottom - top) + 'px';
  };
  window.addEventListener('resize', () => { if (!overlay.hidden) positionOverlay(); });
  document.addEventListener('scroll', () => { if (!overlay.hidden) positionOverlay(); }, {capture: true, passive: true});
  const status = (message, loading = false) => {
    positionOverlay();
    label.hidden = loading;
    label.textContent = loading ? '' : message;
    badge.hidden = !loading;
    overlay.setAttribute('aria-label', loading ? 'Pencarian sedang diproses' : message);
    icon.hidden = !loading;
    overlay.hidden = false;
    host.classList.add('is-loading');
    table.setAttribute('aria-busy', String(loading));
    if (footer) footer.inert = true;
  };
  const clearStatus = () => {
    overlay.hidden = true;
    host.classList.remove('is-loading');
    table.setAttribute('aria-busy', 'false');
    if (footer) footer.inert = false;
  };
  const hideBatchFeedback = () => {
    batchFeedback.hidden = true;
    batchMessage.textContent = '';
  };
  const showBatchFeedback = (failed = false) => {
    batchSpinner.hidden = failed;
    batchRetry.hidden = !failed;
    batchFeedback.hidden = false;
    batchMessage.textContent = failed ? 'Gagal memuat ONU berikutnya.' : 'Memuat ONU berikutnya…';
  };
  const requestFilters = () => {
    const term = search.value.trim();
    const params = { q: [...term].length >= 2 ? term : '' };
    const names = ['olt', 'pon', 'status'];
    filters.forEach((filter, index) => {
      params[names[index]] = index === 2 ? (filter.selectedOptions[0]?.dataset.apiValue || '') : filter.value;
    });
    const source = new URL(location.href).searchParams.get('source');
    if (source) params.source = source;
    return params;
  };
  const load = async (page = 1, searching = false, append = false, reuse = false) => {
    if (append && (loading || !hasMore)) return;
    if (append) {
      batchFailed = false;
      showBatchFeedback();
      area.scrollTop = area.scrollHeight - area.clientHeight;
    } else {
      hasMore = false;
      if (!reuse) canReuseRows = false;
      batchFailed = false;
      hideBatchFeedback();
    }
    const id = ++sequence;
    loading = true;
    controller?.abort();
    controller = new AbortController();
    if (searching) status('Memuat data ONU...', true);
    else if (!append) clearStatus();
    table.setAttribute('aria-busy', 'true');

    const query = (append || reuse) ? activeFilters : requestFilters();
    const params = { ...query, p: page };
    if (pageSize) params.per = pageSize.value;
    if (append || reuse) params.offset = nextOffset;
    if (reuse) params.metadata = '1';
    try {
      const request = window.linnmsOnusAjax.load(params, controller.signal);
      const [response] = await Promise.all([
        request,
        searching || append ? new Promise(resolve => setTimeout(resolve, 250)) : null,
      ]);
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Gagal memuat data ONU.');
      if (id !== sequence) return;
      if (append && Number(data.page) !== page) {
        hasMore = false;
        hideBatchFeedback();
        return;
      }
      currentPage = Number(data.page) || 1;
      nextOffset = Number(data.next_offset) || 0;
      hasMore = data.has_more === true;
      if (append) {
        const scrollTop = area.scrollTop;
        body.insertAdjacentHTML('beforeend', data.rows_html || '');
        if (area.scrollTop !== scrollTop) area.scrollTop = scrollTop;
        previousScrollTop = scrollTop;
        hideBatchFeedback();
        return;
      }
      if (reuse) {
        if (nextOffset > 0 || data.total > 0) {
          while (body.rows.length > nextOffset) body.deleteRow(-1);
        }
      } else {
        body.innerHTML = data.rows_html || '';
        activeFilters = query;
        canReuseRows = true;
      }
      if (!reuse) area.scrollTop = 0;
      previousScrollTop = area.scrollTop;
      clearStatus();
      if (footer) {
        footer.innerHTML = data.pagination_html;
        footer.hidden = false;
      }

    } catch (error) {
      if (id === sequence && error.name !== 'AbortError') {
        if (append) {
          batchFailed = true;
          showBatchFeedback(true);
        } else {
          status('Gagal memuat data ONU. Ketik ulang atau ubah filter untuk mencoba lagi.');
        }
      }
    } finally {
      if (id === sequence) {
        loading = false;
        table.setAttribute('aria-busy', 'false');
      }
    }
  };
  area.addEventListener('scroll', () => {
    if (header) header.scrollLeft = area.scrollLeft;
    const scrollTop = area.scrollTop;
    const movingDown = scrollTop > previousScrollTop;
    previousScrollTop = scrollTop;
    if (!movingDown || loading || batchFailed || !hasMore) return;

    if (area.scrollHeight - scrollTop - area.clientHeight <= 2) {
      load(currentPage, false, true);
    }
  }, { passive: true });
  batchRetry.addEventListener('click', () => load(currentPage, false, true));
  search.addEventListener('input', () => {
    clearTimeout(timer);
    ++sequence;
    canReuseRows = false;
    loading = true;
    hideBatchFeedback();
    controller?.abort();
    table.setAttribute('aria-busy', 'true');
    status('Memuat data ONU...', true);

    timer = setTimeout(() => load(1, true), 200);
  });
  filters.forEach(filter => filter.addEventListener('change', () => { clearTimeout(timer); load(1, true); }));
  pageSize?.addEventListener('change', () => {
    clearTimeout(timer);
    const url = new URL(location.href);
    if (pageSize.selectedIndex === 0) url.searchParams.delete('per');
    else url.searchParams.set('per', pageSize.value);
    url.searchParams.delete('p');
    history.replaceState(history.state, '', url);
    const reuse = currentPage === 1 && canReuseRows;
    load(1, !reuse, false, reuse);
  });
  footer?.addEventListener('click', event => {
    const button = event.target.closest('[data-onu-goto]');
    if (!button) return;
    event.preventDefault();
    clearTimeout(timer);
    load(Number(button.dataset.onuGoto) || 1, true);
  });
  const initial = new URL(location.href);
  search.value = initial.searchParams.get('q') || '';
  filters.forEach((filter, index) => {
    const value = initial.searchParams.get(['olt', 'pon', 'status'][index]);
    if (!value) return;
    filter.value = index === 2
      ? ([...filter.options].find((option) => option.dataset.apiValue === value)?.value || '')
      : value;
  });
  activeFilters = { ...requestFilters(), q: search.value.trim() };
})();
