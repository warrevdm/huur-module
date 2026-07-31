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
});
