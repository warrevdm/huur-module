'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-reservation-form][data-visual-bike-picker]');
  if (!form) return;

  const select = form.querySelector('[data-bike-select]');
  const cards = Array.from(form.querySelectorAll('[data-rental-bike-card]'));
  const search = form.querySelector('[data-rental-bike-search]');
  const filterButtons = Array.from(form.querySelectorAll('[data-rental-filter]'));
  const selectedCount = form.querySelector('[data-rental-selected-count]');
  const summaryBikes = form.querySelector('[data-rental-summary-bikes]');
  const summaryPeriod = form.querySelector('[data-rental-summary-period]');
  const priceButton = form.querySelector('[data-calculate-rental-price]');
  const totalPrice = form.querySelector('[data-total-price]');
  const periodFields = ['start_date', 'start_time', 'end_date', 'end_time']
    .map((name) => form.querySelector(`[name="${name}"]`));
  let activeFilter = 'ALL';
  let priceTimer = null;

  if (!select) return;

  const optionById = (id) => select.querySelector(`option[value="${CSS.escape(String(id))}"]`);

  const euro = (value) => new Intl.NumberFormat('nl-BE', {
    style: 'currency',
    currency: 'EUR',
  }).format(Number(value || 0));

  const updatePeriodSummary = () => {
    if (!summaryPeriod) return;
    const [startDate, startTime, endDate, endTime] = periodFields.map((field) => field?.value || '');
    if (!startDate || !startTime || !endDate || !endTime) {
      summaryPeriod.textContent = 'Kies eerst een volledige huurperiode.';
      return;
    }
    summaryPeriod.textContent = `${startDate} · ${startTime} → ${endDate} · ${endTime}`;
  };

  const schedulePrice = () => {
    window.clearTimeout(priceTimer);
    priceTimer = window.setTimeout(() => {
      if (priceButton && !priceButton.disabled) priceButton.click();
    }, 220);
  };

  const syncCardsFromOptions = () => {
    for (const card of cards) {
      const id = Number.parseInt(card.dataset.bikeId || '0', 10);
      const option = optionById(id);
      if (!option) continue;
      const selected = option.selected && !option.disabled;
      card.classList.toggle('is-selected', selected);
      card.classList.toggle('is-unavailable', option.disabled);
      card.disabled = option.disabled;
      card.setAttribute('aria-pressed', selected ? 'true' : 'false');

      const status = card.querySelector('[data-rental-card-status]');
      if (status) {
        const text = String(option.textContent || '');
        const suffix = text.includes(' · ') ? text.split(' · ').pop() : '';
        status.textContent = option.disabled ? (suffix || 'Niet beschikbaar') : 'Beschikbaar';
      }
    }
    updateSummary();
    applySearchAndFilter();
  };

  const selectedOptions = () => Array.from(select.selectedOptions).filter((option) => !option.disabled);

  const updateSummary = () => {
    const selected = selectedOptions();
    if (selectedCount) selectedCount.textContent = `${selected.length} geselecteerd`;
    if (!summaryBikes) return;

    if (selected.length === 0) {
      summaryBikes.innerHTML = '<div class="rental-summary-empty">Nog geen fiets geselecteerd.</div>';
      return;
    }

    summaryBikes.innerHTML = '';
    for (const option of selected) {
      const item = document.createElement('div');
      item.className = 'rental-summary-bike';

      const thumb = document.createElement('div');
      thumb.className = 'rental-summary-bike-thumb';
      if (option.dataset.bikePhoto) {
        const img = document.createElement('img');
        img.src = option.dataset.bikePhoto;
        img.alt = '';
        img.loading = 'lazy';
        thumb.appendChild(img);
      }

      const copy = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = `${option.dataset.bikeCode || ''} · ${option.dataset.bikeName || ''}`;
      const meta = document.createElement('small');
      meta.textContent = option.dataset.priceLabel || option.dataset.bikeCategory || '';
      copy.appendChild(title);
      copy.appendChild(meta);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'rental-summary-remove';
      remove.setAttribute('aria-label', `Verwijder ${option.dataset.bikeCode || 'fiets'}`);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        option.selected = false;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        syncCardsFromOptions();
        schedulePrice();
      });

      item.appendChild(thumb);
      item.appendChild(copy);
      item.appendChild(remove);
      summaryBikes.appendChild(item);
    }
  };

  const applySearchAndFilter = () => {
    const needle = String(search?.value || '').trim().toLowerCase();
    for (const card of cards) {
      const haystack = [card.dataset.bikeCode, card.dataset.bikeName, card.dataset.bikeCategory]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      const matchesSearch = !needle || haystack.includes(needle);
      const matchesFilter = activeFilter === 'ALL' || card.dataset.bikeGroup === activeFilter;
      card.hidden = !(matchesSearch && matchesFilter);
    }
  };

  for (const card of cards) {
    card.addEventListener('click', () => {
      const id = Number.parseInt(card.dataset.bikeId || '0', 10);
      const option = optionById(id);
      if (!option || option.disabled) return;
      option.selected = !option.selected;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      syncCardsFromOptions();
      schedulePrice();
    });
  }

  search?.addEventListener('input', applySearchAndFilter);

  for (const button of filterButtons) {
    button.addEventListener('click', () => {
      activeFilter = button.dataset.rentalFilter || 'ALL';
      for (const candidate of filterButtons) {
        candidate.classList.toggle('is-active', candidate === button);
      }
      applySearchAndFilter();
    });
  }

  for (const field of periodFields) {
    field?.addEventListener('change', () => {
      updatePeriodSummary();
      schedulePrice();
    });
  }

  const observer = new MutationObserver(() => {
    syncCardsFromOptions();
    schedulePrice();
  });
  observer.observe(select, {
    subtree: true,
    attributes: true,
    characterData: true,
    childList: true,
  });

  totalPrice?.addEventListener('input', () => {
    const value = Number(totalPrice.value || 0);
    totalPrice.setAttribute('aria-label', `Totaalprijs ${euro(value)}`);
  });

  updatePeriodSummary();
  syncCardsFromOptions();
  schedulePrice();
});
