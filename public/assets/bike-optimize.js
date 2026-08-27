'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const actionInput = document.querySelector('form input[name="action"][value="optimize_images"]');
  const form = actionInput?.closest('form');
  const button = form?.querySelector('button[type="submit"]');
  const token = form?.querySelector('input[name="_token"]')?.value || '';

  if (!form || !button || !token) return;

  const status = document.createElement('span');
  status.className = 'help';
  status.setAttribute('aria-live', 'polite');
  form.insertAdjacentElement('afterend', status);

  const bikeCards = () => Array.from(document.querySelectorAll('.bike-card')).map((card) => {
    const editLink = card.querySelector('a[href*="bikes.php?edit="]');
    if (!editLink) return null;

    try {
      const url = new URL(editLink.href, window.location.href);
      const id = Number.parseInt(url.searchParams.get('edit') || '0', 10);
      if (!Number.isInteger(id) || id < 1) return null;
      return { id, card };
    } catch {
      return null;
    }
  }).filter(Boolean);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (button.disabled) return;

    const items = bikeCards().filter(({ card }) => card.querySelector('img'));
    if (items.length === 0) {
      status.textContent = 'Geen fietsafbeeldingen om te optimaliseren.';
      return;
    }

    button.disabled = true;
    const originalLabel = button.textContent;
    let success = 0;
    let failed = 0;

    for (let index = 0; index < items.length; index += 1) {
      const { id, card } = items[index];
      button.textContent = `Optimaliseren ${index + 1}/${items.length}`;
      status.textContent = `Foto ${index + 1} van ${items.length} verwerken…`;

      try {
        const response = await fetch('api-bike-thumbnail.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          },
          body: new URLSearchParams({
            _token: token,
            id: String(id),
          }),
        });

        const data = await response.json();
        if (!response.ok || !data.ok || !data.url) {
          throw new Error(data.error || 'Optimalisatie mislukt.');
        }

        const image = card.querySelector('img');
        if (image) {
          image.src = data.url;
          image.removeAttribute('srcset');
        }
        success += 1;
      } catch {
        failed += 1;
      }
    }

    button.disabled = false;
    button.textContent = originalLabel;
    status.textContent = failed === 0
      ? `${success} foto’s geoptimaliseerd. Volgende paginalading gebruikt de snelle thumbnails.`
      : `${success} foto’s geoptimaliseerd · ${failed} niet verwerkt.`;
  });
});
