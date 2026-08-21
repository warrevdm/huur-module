'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const startDate = document.querySelector('[name="start_date"]');
  const endDate = document.querySelector('[name="end_date"]');

  if (startDate && endDate) {
    startDate.addEventListener('change', () => {
      if (!endDate.value || endDate.value < startDate.value) {
        endDate.value = startDate.value;
        endDate.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  const reservationForm = document.querySelector('[data-reservation-form]');
  const bikeSelect = reservationForm?.querySelector('[data-bike-select]');
  const availabilityMessage = reservationForm?.querySelector('[data-availability-message]');
  const priceButton = reservationForm?.querySelector('[data-calculate-rental-price]');
  const priceBreakdown = reservationForm?.querySelector('[data-price-breakdown]');
  const totalPrice = reservationForm?.querySelector('[data-total-price]');
  const priceMode = reservationForm?.querySelector('[data-price-calculation-mode]');
  let availabilityTimer = null;

  const euro = (value) => new Intl.NumberFormat('nl-BE', {
    style: 'currency',
    currency: 'EUR',
  }).format(value);

  const rentalDays = () => {
    if (!reservationForm) return 0;
    const startDateValue = reservationForm.querySelector('[name="start_date"]')?.value;
    const startTimeValue = reservationForm.querySelector('[name="start_time"]')?.value;
    const endDateValue = reservationForm.querySelector('[name="end_date"]')?.value;
    const endTimeValue = reservationForm.querySelector('[name="end_time"]')?.value;
    if (!startDateValue || !startTimeValue || !endDateValue || !endTimeValue) return 0;

    const start = new Date(`${startDateValue}T${startTimeValue}:00`);
    const end = new Date(`${endDateValue}T${endTimeValue}:00`);
    const diff = end.getTime() - start.getTime();
    if (!Number.isFinite(diff) || diff <= 0) return 0;
    return Math.max(1, Math.ceil(diff / 86400000));
  };

  const priceForDays = (dayRate, weekRate, days) => {
    if (days <= 0 || (dayRate <= 0 && weekRate <= 0)) return 0;
    const fullWeeks = Math.floor(days / 7);
    const remainingDays = days % 7;
    return (fullWeeks * weekRate) + Math.min(remainingDays * dayRate, weekRate);
  };

  const calculateRentalPrice = () => {
    if (!reservationForm || !bikeSelect || !priceBreakdown || !totalPrice || !priceMode) return;

    const days = rentalDays();
    const selectedOptions = Array.from(bikeSelect.selectedOptions).filter((option) => !option.disabled);

    if (days <= 0) {
      priceBreakdown.textContent = 'Kies een geldige start- en eindperiode.';
      priceBreakdown.className = 'availability-message availability-warning';
      priceMode.value = 'manual';
      return;
    }

    if (selectedOptions.length === 0) {
      priceBreakdown.textContent = 'Selecteer minstens één fiets om de verhuurprijs te berekenen.';
      priceBreakdown.className = 'availability-message availability-warning';
      priceMode.value = 'manual';
      return;
    }

    const unsupported = selectedOptions.filter((option) => option.dataset.priceSupported !== '1');
    if (unsupported.length > 0) {
      const labels = unsupported.map((option) => option.dataset.bikeCode || option.dataset.baseLabel || 'fiets');
      priceBreakdown.textContent = `Geen automatisch tarief voor: ${labels.join(', ')}. Vul de totaalprijs manueel in.`;
      priceBreakdown.className = 'availability-message availability-warning';
      priceMode.value = 'manual';
      return;
    }

    let total = 0;
    const lines = [];

    for (const option of selectedOptions) {
      const dayRate = Number(option.dataset.priceDay || 0);
      const weekRate = Number(option.dataset.priceWeek || 0);
      const price = priceForDays(dayRate, weekRate, days);
      total += price;

      const code = option.dataset.bikeCode || '';
      const name = option.dataset.bikeName || '';
      const label = option.dataset.priceLabel || '';
      const detail = dayRate === 0 && weekRate === 0
        ? 'gratis inzet'
        : `${days} dag(en) · ${label}`;
      lines.push(`${code}${name ? ` — ${name}` : ''}: ${detail} → ${euro(price)}`);
    }

    totalPrice.value = total.toFixed(2);
    priceMode.value = 'auto';
    priceBreakdown.textContent = `${lines.join(' | ')} | Totaal: ${euro(total)}`;
    priceBreakdown.className = 'availability-message availability-success';
  };

  const markPriceStale = () => {
    if (!priceBreakdown || !priceMode) return;
    if (priceMode.value === 'auto') {
      priceBreakdown.textContent = 'Periode of fietsselectie gewijzigd. Klik opnieuw op “Bereken verhuurprijs”.';
      priceBreakdown.className = 'availability-message availability-warning';
      priceMode.value = 'manual';
    }
  };

  const refreshAvailability = async () => {
    if (!reservationForm || !bikeSelect) return;

    const fields = ['start_date', 'start_time', 'end_date', 'end_time'];
    const params = new URLSearchParams();
    for (const fieldName of fields) {
      const field = reservationForm.querySelector(`[name="${fieldName}"]`);
      if (!field?.value) return;
      params.set(fieldName, field.value);
    }

    if (availabilityMessage) {
      availabilityMessage.textContent = 'Beschikbaarheid controleren…';
      availabilityMessage.className = 'availability-message availability-loading';
    }

    try {
      const response = await fetch(`${reservationForm.dataset.availabilityUrl}?${params.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Beschikbaarheid kon niet worden gecontroleerd.');

      let availableCount = 0;
      let removedCount = 0;
      for (const item of data.items) {
        const option = bikeSelect.querySelector(`option[value="${CSS.escape(String(item.id))}"]`);
        if (!option) continue;
        const baseLabel = option.dataset.baseLabel || item.label;
        if (item.available) {
          option.disabled = false;
          option.textContent = `${baseLabel} · BESCHIKBAAR`;
          availableCount += 1;
        } else {
          if (option.selected) {
            option.selected = false;
            removedCount += 1;
          }
          option.disabled = true;
          option.textContent = `${baseLabel} · ${String(item.reason || 'NIET BESCHIKBAAR').toUpperCase()}`;
        }
      }

      if (removedCount) markPriceStale();

      if (availabilityMessage) {
        availabilityMessage.textContent = `${availableCount} fiets(en) beschikbaar.${removedCount ? ` ${removedCount} eerdere selectie(s) verwijderd omdat ze niet beschikbaar zijn.` : ''}`;
        availabilityMessage.className = removedCount
          ? 'availability-message availability-warning'
          : 'availability-message availability-success';
      }
    } catch (error) {
      if (availabilityMessage) {
        availabilityMessage.textContent = error.message;
        availabilityMessage.className = 'availability-message availability-error';
      }
    }
  };

  if (reservationForm && bikeSelect) {
    reservationForm.querySelectorAll('[name="start_date"], [name="start_time"], [name="end_date"], [name="end_time"]').forEach((field) => {
      field.addEventListener('change', () => {
        markPriceStale();
        window.clearTimeout(availabilityTimer);
        availabilityTimer = window.setTimeout(refreshAvailability, 150);
      });
    });
    bikeSelect.addEventListener('change', markPriceStale);
    refreshAvailability();
  }

  if (priceButton) {
    priceButton.addEventListener('click', calculateRentalPrice);
  }

  if (totalPrice && priceMode) {
    totalPrice.addEventListener('input', () => {
      if (priceMode.value === 'auto') {
        priceMode.value = 'manual';
        if (priceBreakdown) {
          priceBreakdown.textContent = 'Totaalprijs manueel aangepast. De manuele prijs wordt opgeslagen.';
          priceBreakdown.className = 'availability-message availability-warning';
        }
      }
    });
  }

  const paymentMethod = document.querySelector('[data-payment-method]');
  const paymentAmount = document.querySelector('[data-payment-amount]');
  if (paymentMethod && paymentAmount) {
    const syncPayment = () => {
      const active = paymentMethod.value !== '';
      paymentAmount.disabled = !active;
      paymentAmount.required = active;
      if (!active) paymentAmount.value = '0';
    };
    paymentMethod.addEventListener('change', syncPayment);
    syncPayment();
  }

  const params = new URLSearchParams(window.location.search);
  if (params.get('route') === 'reservation-view' && params.get('id')) {
    const statusForm = document.querySelector('form[action*="route=reservation-status"]');
    const statusCard = statusForm ? statusForm.closest('.card') : null;

    fetch(`reservation-stamp.php?id=${encodeURIComponent(params.get('id'))}`, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((response) => response.ok ? response.text() : '')
      .then((html) => {
        if (!html.trim() || document.querySelector('[data-reservation-stamps]')) return;
        if (statusCard) statusCard.insertAdjacentHTML('beforebegin', html);
      })
      .catch(() => {});
  }
});
