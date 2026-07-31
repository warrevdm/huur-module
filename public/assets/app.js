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
  let availabilityTimer = null;

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
        window.clearTimeout(availabilityTimer);
        availabilityTimer = window.setTimeout(refreshAvailability, 150);
      });
    });
    refreshAvailability();
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
