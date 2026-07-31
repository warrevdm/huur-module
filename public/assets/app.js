'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const startDate = document.querySelector('[name="start_date"]');
  const endDate = document.querySelector('[name="end_date"]');

  if (startDate && endDate) {
    startDate.addEventListener('change', () => {
      if (!endDate.value || endDate.value < startDate.value) {
        endDate.value = startDate.value;
      }
    });
  }

  const params = new URLSearchParams(window.location.search);
  if (params.get('route') === 'reservation-view' && params.get('id')) {
    const statusForm = document.querySelector('form[action*="route=reservation-status"]');
    const statusCard = statusForm ? statusForm.closest('.card') : null;

    fetch(`reservation-stamp.php?id=${encodeURIComponent(params.get('id'))}`, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Registratiestempels konden niet worden geladen.');
        }
        return response.text();
      })
      .then((html) => {
        if (!html.trim() || document.querySelector('[data-reservation-stamps]')) {
          return;
        }
        if (statusCard) {
          statusCard.insertAdjacentHTML('beforebegin', html);
        }
      })
      .catch(() => {
        // De verhuurfiche blijft bruikbaar wanneer de aanvullende stempelweergave niet laadt.
      });
  }
});
