'use strict';

document.addEventListener('DOMContentLoaded', () => {
  const blocks = document.querySelectorAll('.booking-block[data-customer-name]');
  if (!blocks.length) return;

  const tooltip = document.createElement('div');
  tooltip.className = 'planning-customer-tooltip';
  tooltip.setAttribute('role', 'tooltip');
  document.body.appendChild(tooltip);

  const placeTooltip = (block) => {
    const rect = block.getBoundingClientRect();
    const gap = 10;

    tooltip.style.left = '0px';
    tooltip.style.top = '0px';
    tooltip.classList.add('is-visible');

    const tipRect = tooltip.getBoundingClientRect();
    let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
    left = Math.max(16, Math.min(left, window.innerWidth - tipRect.width - 16));

    let top = rect.top - tipRect.height - gap;
    if (top < 16) {
      top = rect.bottom + gap;
    }

    tooltip.style.left = Math.round(left) + 'px';
    tooltip.style.top = Math.round(top) + 'px';
  };

  const show = (block) => {
    const name = (block.dataset.customerName || '').trim();
    if (!name) return;

    tooltip.textContent = name;
    placeTooltip(block);
  };

  const hide = () => {
    tooltip.classList.remove('is-visible');
  };

  blocks.forEach((block) => {
    block.addEventListener('mouseenter', () => show(block));
    block.addEventListener('mouseleave', hide);
    block.addEventListener('focus', () => show(block));
    block.addEventListener('blur', hide);
  });

  window.addEventListener('scroll', hide, { passive: true });
  window.addEventListener('resize', hide);
});
