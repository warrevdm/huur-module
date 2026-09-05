'use strict';

(() => {
  const BRIDGE_URL = window.AAB_EID_BRIDGE_URL || 'http://127.0.0.1:17895';

  const setStatus = (element, message, state = 'neutral') => {
    element.textContent = message;
    element.className = `eid-bridge-status eid-bridge-status-${state}`;
  };

  const bridgeFetch = async (path) => {
    const url = `${BRIDGE_URL}${path}`;
    const options = {
      method: 'GET',
      mode: 'cors',
      cache: 'no-store',
      credentials: 'omit',
      headers: { Accept: 'application/json' },
    };

    try {
      const request = new Request(url, { ...options, targetAddressSpace: 'loopback' });
      return await fetch(request);
    } catch (error) {
      return await fetch(url, options);
    }
  };

  document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-reservation-form]');
    const nameField = form?.querySelector('[name="customer_name"]');
    const addressField = form?.querySelector('[name="customer_address"]');

    if (!form || !nameField || !addressField || form.querySelector('[data-eid-bridge-panel]')) {
      return;
    }

    const customerGrid = nameField.closest('.form-grid');
    if (!customerGrid) return;

    const panel = document.createElement('div');
    panel.className = 'eid-bridge-panel';
    panel.dataset.eidBridgePanel = '1';
    panel.innerHTML = `
      <div class="eid-bridge-copy">
        <strong>Identiteit via Belgische eID</strong>
        <span>Steek de eID in de DIGIPASS 905 en lees naam en adres rechtstreeks in. Er wordt geen foto of rijksregisternummer overgenomen.</span>
      </div>
      <div class="eid-bridge-actions">
        <button class="button button-secondary" type="button" data-eid-read>eID uitlezen</button>
        <span class="eid-bridge-status eid-bridge-status-neutral" data-eid-status>Lokale eID-reader nog niet aangesproken.</span>
      </div>
    `;
    customerGrid.parentNode.insertBefore(panel, customerGrid);

    const button = panel.querySelector('[data-eid-read]');
    const status = panel.querySelector('[data-eid-status]');

    button.addEventListener('click', async () => {
      button.disabled = true;
      setStatus(status, 'Kaartlezer aanspreken… steek de eID in de DIGIPASS 905.', 'loading');

      try {
        const response = await bridgeFetch('/v1/read?timeout=12000');
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.ok) {
          throw new Error(data.error || 'De eID kon niet worden uitgelezen.');
        }

        if (!data.identity?.fullName || !data.identity?.address) {
          throw new Error('De eID werd gelezen, maar naam of adres ontbreekt.');
        }

        nameField.value = data.identity.fullName;
        addressField.value = data.identity.address;
        nameField.dispatchEvent(new Event('input', { bubbles: true }));
        addressField.dispatchEvent(new Event('input', { bubbles: true }));

        const reader = data.reader ? ` via ${data.reader}` : '';
        const validUntil = data.identity.validUntil ? ` · kaart geldig t.e.m. ${data.identity.validUntil}` : '';
        setStatus(status, `eID gelezen${reader}. Naam en adres zijn ingevuld${validUntil}.`, 'success');
        button.textContent = 'eID opnieuw uitlezen';
      } catch (error) {
        const message = error instanceof TypeError
          ? 'AAB eID Bridge is niet bereikbaar. Start AAB-eID-Bridge.exe op deze Windows-pc en probeer opnieuw.'
          : error.message;
        setStatus(status, message, 'error');
      } finally {
        button.disabled = false;
      }
    });
  });
})();
