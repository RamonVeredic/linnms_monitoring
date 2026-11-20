(() => {
  'use strict';

  const modal = document.querySelector('[data-device-add-modal]');
  const openButton = document.querySelector('[data-device-add-open]');
  if (!(modal instanceof HTMLElement) || !(openButton instanceof HTMLElement)) return;
  const body = modal.querySelector('[data-device-add-body]');
  const closeButton = modal.querySelector('[data-device-add-close]');
  if (!(body instanceof HTMLElement)) return;

  let opener = null;
  let request = null;
  let formLoaded = false;

  const clearUrl = () => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('add_device')) return;
    url.searchParams.delete('add_device');
    window.history.replaceState(null, '', url.toString());
  };

  const close = () => {
    if (modal.hidden) return;
    request?.abort();
    request = null;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('device-add-modal-open');
    clearUrl();
    if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
    opener = null;
  };

  const focusForm = () => {
    const field = body.querySelector('[aria-invalid="true"]')
      || body.querySelector('[data-device-type] + .select-button, [data-device-type]')
      || closeButton;
    field?.focus({ preventScroll: true });
  };

  const open = async () => {
    if (!modal.hidden) return;
    opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
      ? document.activeElement : openButton;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('device-add-modal-open');
    if (formLoaded) {
      focusForm();
      return;
    }
    closeButton?.focus({ preventScroll: true });
    body.removeAttribute('role');
    body.innerHTML = '<p class="text-muted" role="status">Memuat formulir...</p>';
    const controller = new AbortController();
    request = controller;
    const url = new URL('data.php', window.location.href);
    url.searchParams.set('type', 'device-add-form');
    try {
      const response = await window.linnmsAjax.request(url.toString(), { signal: controller.signal, cache: 'no-store' });
      const data = await response.json().catch(() => ({}));
      if (request !== controller) return;
      if (!response.ok || data.ok !== true || typeof data.html !== 'string') {
        throw new Error(String(data.message || 'Form tambah perangkat tidak dapat dimuat.'));
      }
      body.innerHTML = data.html;
      window.linnmsDeviceFormInit?.(body);
      formLoaded = true;
      focusForm();
    } catch (error) {
      if (request !== controller || error?.name === 'AbortError') return;
      body.textContent = error instanceof Error ? error.message : 'Form tambah perangkat tidak dapat dimuat.';
      body.setAttribute('role', 'alert');
    } finally {
      if (request === controller) request = null;
    }
  };

  openButton.addEventListener('click', open);
  closeButton?.addEventListener('click', close);
  document.addEventListener('keydown', (event) => {
    if (modal.hidden || event.defaultPrevented) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab') return;
    const items = [...modal.querySelectorAll('button, input, select, textarea, [tabindex]')]
      .filter((item) => item instanceof HTMLElement && !item.matches(':disabled, [hidden], [tabindex="-1"]') && item.getClientRects().length > 0);
    if (items.length === 0) return;
    if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1).focus(); }
    else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0].focus(); }
  });
  if (modal.dataset.openOnLoad === '1') open();
})();
