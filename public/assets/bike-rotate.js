'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const bikeId = Number.parseInt(params.get('edit') || '0', 10);
  if (!Number.isInteger(bikeId) || bikeId < 1) return;

  const photoWrap = document.querySelector('.bike-current-photo');
  const form = document.querySelector('.bike-form-card form');
  const token = form?.querySelector('input[name="_token"]')?.value || '';
  if (!photoWrap || !form || !token) return;

  const controls = document.createElement('div');
  controls.className = 'actions';
  controls.setAttribute('data-bike-rotate-controls', '');

  const status = document.createElement('span');
  status.className = 'help';
  status.setAttribute('aria-live', 'polite');

  const createButton = (direction, label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button button-secondary';
    button.textContent = label;
    button.dataset.rotateDirection = direction;
    return button;
  };

  const leftButton = createButton('left', '↶ 90° links');
  const rightButton = createButton('right', '↷ 90° rechts');
  controls.append(leftButton, rightButton);

  photoWrap.insertAdjacentElement('afterend', controls);
  controls.insertAdjacentElement('afterend', status);

  const rotate = async (direction) => {
    leftButton.disabled = true;
    rightButton.disabled = true;
    status.textContent = 'Foto draaien…';

    try {
      const response = await fetch('bike-rotate.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: new URLSearchParams({
          _token: token,
          id: String(bikeId),
          direction,
        }),
      });

      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || 'De foto kon niet worden gedraaid.');
      }

      status.textContent = 'Foto gedraaid. Pagina vernieuwen…';
      window.location.reload();
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : 'De foto kon niet worden gedraaid.';
      leftButton.disabled = false;
      rightButton.disabled = false;
    }
  };

  leftButton.addEventListener('click', () => rotate('left'));
  rightButton.addEventListener('click', () => rotate('right'));
});
