'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-quick-replacement]');
  if (!shell) return;

  const buttons = Array.from(shell.querySelectorAll('[data-quick-filter]'));
  const cards = Array.from(shell.querySelectorAll('[data-quick-bike-card]'));
  const summary = shell.querySelector('[data-quick-filter-summary]');
  const startDate = shell.querySelector('[data-quick-start-date]');
  const returnDate = shell.querySelector('[data-quick-return-date]');
  const periodLabel = shell.querySelector('[data-quick-period-label]');
  const availabilityUrl = shell.dataset.availabilityUrl || 'api-bike-availability.php';
  const today = shell.dataset.today || '';
  const currentTime = shell.dataset.currentTime || '09:00';
  let activeFilter = 'all';
  let availabilityTimer = null;

  const formatDate = (value) => {
    if (!value) return '';
    const [year, month, day] = value.split('-');
    return year && month && day ? `${day}/${month}/${year}` : value;
  };

  const startTimeForDate = () => startDate?.value === today ? currentTime : '09:00';

  const applyFilter = (filter = activeFilter) => {
    activeFilter = filter;
    let visible = 0;
    let available = 0;

    for (const card of cards) {
      const matches = filter === 'all' || card.dataset.category === filter;
      card.hidden = !matches;
      if (matches) {
        visible += 1;
        if (card.dataset.available === '1') available += 1;
      }
    }

    for (const button of buttons) {
      const active = button.dataset.quickFilter === filter;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    if (summary) summary.textContent = `${visible} fiets(en) zichtbaar · ${available} beschikbaar`;
  };

  const setCardAvailability = (card, available, reason = '') => {
    const input = card.querySelector('input[name="bike_id"]');
    const state = card.querySelector('[data-quick-bike-state]');

    card.dataset.available = available ? '1' : '0';
    card.classList.toggle('quick-bike-card-disabled', !available);

    if (input) {
      if (!available && input.checked) input.checked = false;
      input.disabled = !available;
    }

    if (state) {
      state.textContent = available ? 'Beschikbaar' : (reason || 'Niet beschikbaar');
      state.classList.toggle('quick-bike-state-available', available);
      state.classList.toggle('quick-bike-state-unavailable', !available);
    }
  };

  const syncDateLimits = () => {
    if (!startDate || !returnDate) return;
    if (today) startDate.min = today;
    returnDate.min = startDate.value || today;
    if (returnDate.value && startDate.value && returnDate.value < startDate.value) {
      returnDate.value = startDate.value;
    }
  };

  const refreshAvailability = async () => {
    if (!startDate?.value || !returnDate?.value) return;

    const startTime = startTimeForDate();
    if (periodLabel) {
      periodLabel.textContent = `Beschikbaarheid controleren voor ${formatDate(startDate.value)} ${startTime} → ${formatDate(returnDate.value)} 17:00…`;
    }

    const params = new URLSearchParams({
      start_date: startDate.value,
      start_time: startTime,
      end_date: returnDate.value,
      end_time: '17:00',
    });

    try {
      const response = await fetch(`${availabilityUrl}?${params.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Beschikbaarheid kon niet worden gecontroleerd.');

      const byId = new Map(data.items.map((item) => [String(item.id), item]));
      for (const card of cards) {
        const item = byId.get(String(card.dataset.bikeId || ''));
        if (!item) continue;
        setCardAvailability(card, Boolean(item.available), String(item.reason || ''));
      }

      if (periodLabel) {
        periodLabel.textContent = `Beschikbaarheid: ${formatDate(startDate.value)} ${startTime} → ${formatDate(returnDate.value)} 17:00.`;
      }
      applyFilter();
    } catch (error) {
      if (periodLabel) {
        periodLabel.textContent = error instanceof Error ? error.message : 'Beschikbaarheid kon niet worden gecontroleerd.';
      }
    }
  };

  const scheduleAvailability = () => {
    syncDateLimits();
    window.clearTimeout(availabilityTimer);
    availabilityTimer = window.setTimeout(refreshAvailability, 120);
  };

  for (const button of buttons) {
    button.addEventListener('click', () => applyFilter(button.dataset.quickFilter || 'all'));
  }

  startDate?.addEventListener('change', scheduleAvailability);
  returnDate?.addEventListener('change', scheduleAvailability);

  syncDateLimits();
  applyFilter('all');
});
