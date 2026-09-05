'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const path = window.location.pathname.toLowerCase();
  if (!path.endsWith('/reservation.php') && !path.endsWith('reservation.php')) return;

  const params = new URLSearchParams(window.location.search);
  const id = params.get('id');
  if (!id || !/^\d+$/.test(id)) return;

  const activeBadge = document.querySelector('.badge.status-confirmed, .badge.status-picked_up');
  if (!activeBadge) return;

  const headerActions = document.querySelector('.card.col-8 .actions.actions-between > .actions');
  if (!headerActions || headerActions.querySelector('[data-edit-end-date]')) return;

  const link = document.createElement('a');
  link.className = 'button button-secondary';
  link.href = `reservation-end-date.php?id=${encodeURIComponent(id)}`;
  link.textContent = 'Einddatum aanpassen';
  link.setAttribute('data-edit-end-date', '');
  headerActions.insertBefore(link, headerActions.firstChild);
});
