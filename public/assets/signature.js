(() => {
    const canvas = document.getElementById('signature-canvas');
    const form = document.getElementById('signature-form');
    if (!canvas || !form) return;

    const context = canvas.getContext('2d');
    const clearButton = document.getElementById('signature-clear');
    const dataInput = document.getElementById('signature-data');
    const errorBox = document.getElementById('signature-error');
    let drawing = false;
    let hasInk = false;

    context.lineWidth = 3;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#17211a';

    const position = (event) => {
        const rect = canvas.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) * (canvas.width / rect.width),
            y: (event.clientY - rect.top) * (canvas.height / rect.height),
        };
    };

    const start = (event) => {
        event.preventDefault();
        drawing = true;
        const point = position(event);
        context.beginPath();
        context.moveTo(point.x, point.y);
        canvas.setPointerCapture?.(event.pointerId);
    };

    const move = (event) => {
        if (!drawing) return;
        event.preventDefault();
        const point = position(event);
        context.lineTo(point.x, point.y);
        context.stroke();
        hasInk = true;
    };

    const stop = (event) => {
        if (!drawing) return;
        drawing = false;
        context.closePath();
        canvas.releasePointerCapture?.(event.pointerId);
    };

    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    canvas.addEventListener('pointerleave', stop);

    clearButton?.addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        hasInk = false;
        dataInput.value = '';
        if (errorBox) errorBox.hidden = true;
    });

    form.addEventListener('submit', (event) => {
        if (!hasInk) {
            event.preventDefault();
            if (errorBox) {
                errorBox.textContent = 'Plaats eerst uw handtekening in het vak.';
                errorBox.hidden = false;
            }
            return;
        }
        dataInput.value = canvas.toDataURL('image/png');
    });
})();
