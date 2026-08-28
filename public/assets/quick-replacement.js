'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-quick-replacement]');
  if (!shell) return;

  const buttons = Array.from(shell.querySelectorAll('[data-quick-filter]'));
  const cards = Array.from(shell.querySelectorAll('[data-quick-bike-card]'));
  const summary = shell.querySelector('[data-quick-filter-summary]');

  const applyFilter = (filter) => {
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

    if (summary) {
      summary.textContent = `${visible} fiets(en) zichtbaar · ${available} beschikbaar`;
    }
  };

  for (const button of buttons) {
    button.addEventListener('click', () => applyFilter(button.dataset.quickFilter || 'all'));
  }

  applyFilter('all');
});
