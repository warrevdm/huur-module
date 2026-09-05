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
    const image = card.querySelector('img');
    if (!editLink || !image) return null;

    try {
      const url = new URL(editLink.href, window.location.href);
      const id = Number.parseInt(url.searchParams.get('edit') || '0', 10);
      if (!Number.isInteger(id) || id < 1) return null;
      return { id, card, image };
    } catch {
      return null;
    }
  }).filter(Boolean);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (button.disabled) return;

    // Bestaande statische WebP-thumbnails hoeven niet opnieuw verwerkt te worden.
    const items = bikeCards().filter(({ image }) => image.src.includes('bike-photo.php'));
    if (items.length === 0) {
      status.textContent = 'Alle fietsafbeeldingen zijn al geoptimaliseerd.';
      return;
    }

    button.disabled = true;
    const originalLabel = button.textContent;
    let success = 0;
    const failures = [];

    for (let index = 0; index < items.length; index += 1) {
      const { id, image } = items[index];
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

        let data = null;
        try {
          data = await response.json();
        } catch {
          throw new Error('Server gaf geen geldige JSON-respons.');
        }

        if (!response.ok || !data.ok || !data.url) {
          const code = data?.code || `ID ${id}`;
          throw new Error(`${code}: ${data?.error || 'Optimalisatie mislukt.'}`);
        }

        image.src = data.url;
        image.removeAttribute('srcset');
        success += 1;
      } catch (error) {
        failures.push(error instanceof Error ? error.message : `ID ${id}: optimalisatie mislukt.`);
      }
    }

    button.disabled = false;
    button.textContent = originalLabel;

    if (failures.length === 0) {
      status.textContent = `${success} resterende foto’s geoptimaliseerd. Alle thumbnails zijn klaar.`;
      return;
    }

    status.textContent = `${success} geoptimaliseerd · ${failures.length} niet verwerkt: ${failures.join(' | ')}`;
  });
});
