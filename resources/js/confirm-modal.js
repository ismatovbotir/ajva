// Branded replacement for Livewire's native wire:confirm dialog.
//
// Livewire runs window.confirm(message) inside the element's own click handler. We listen on the document in the
// CAPTURE phase, so we see the click first, stop it, and show #confirm-modal (components/ui/confirm-modal.blade.php).
// On OK the original element is clicked again while window.confirm is neutralised for that single synchronous
// dispatch, so Livewire runs the action exactly as before. Nothing is bound to the trigger elements themselves, so
// Livewire morphs, wire:navigate and buttons inside other modals keep working.
//
// Optional attributes on the trigger: data-confirm-title, data-confirm-tone="danger|primary", data-confirm-label.
// Without data-confirm-tone the tone is danger when the trigger is styled red (bg-red-* / text-red-*).

const SHORT_LABEL = 20;
let state = null; // { el, click, returnFocus } while open
let passing = false;

function confirmAttribute(el) {
    for (const attr of el.attributes) {
        if (attr.name === 'wire:confirm' || attr.name.startsWith('wire:confirm.')) return attr;
    }
    return null;
}

function findTrigger(node) {
    for (let el = node instanceof Element ? node : node?.parentElement; el; el = el.parentElement) {
        if (confirmAttribute(el)) return el;
    }
    return null;
}

function host() {
    return document.getElementById('confirm-modal');
}

function isDanger(el) {
    const tone = el.getAttribute('data-confirm-tone');
    if (tone) return tone === 'danger';
    return /(^|\s)(bg|text)-red-/.test(el.getAttribute('class') || '');
}

function labelFor(el, fallback) {
    const own = el.getAttribute('data-confirm-label');
    if (own) return own;
    const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    return text && text.length <= SHORT_LABEL ? text : fallback;
}

function focusables(root) {
    return [...root.querySelectorAll('button:not([disabled])')];
}

function open(el, attr) {
    const root = host();
    if (!root) return false;

    const danger = isDanger(el);
    const panel = root.querySelector('[data-confirm-panel]');
    const ok = root.querySelector('[data-confirm-ok]');
    const cancel = root.querySelector('[data-confirm-cancel]');
    const message = (attr.value || '').replaceAll('\\n', '\n');

    root.querySelector('[data-confirm-title]').textContent = el.getAttribute('data-confirm-title') || root.dataset.defaultTitle;
    root.querySelector('[data-confirm-message]').textContent = message;
    ok.textContent = labelFor(el, root.dataset.confirmLabel);
    cancel.textContent = root.dataset.cancelLabel;

    ok.classList.toggle('bg-red-600', danger);
    ok.classList.toggle('hover:bg-red-500', danger);
    ok.classList.toggle('focus:ring-red-500', danger);
    ok.classList.toggle('bg-brand-700', !danger);
    ok.classList.toggle('hover:bg-brand-600', !danger);
    ok.classList.toggle('focus:ring-brand-500', !danger);

    const wrap = root.querySelector('[data-confirm-icon-wrap]');
    wrap.classList.toggle('bg-red-100', danger);
    wrap.classList.toggle('text-red-600', danger);
    wrap.classList.toggle('bg-brand-50', !danger);
    wrap.classList.toggle('text-brand-700', !danger);
    root.querySelector('[data-confirm-icon-danger]').classList.toggle('hidden', !danger);
    root.querySelector('[data-confirm-icon-primary]').classList.toggle('hidden', danger);

    state = {
        el,
        click: el.getAttribute('wire:click'),
        returnFocus: document.activeElement instanceof HTMLElement ? document.activeElement : el,
    };

    root.classList.remove('hidden');
    root.classList.add('flex');
    requestAnimationFrame(() => {
        root.querySelector('[data-confirm-backdrop]').classList.replace('opacity-0', 'opacity-100');
        panel.classList.remove('translate-y-4', 'opacity-0');
        panel.classList.add('translate-y-0', 'opacity-100');
    });
    (danger ? cancel : ok).focus();
    return true;
}

function close() {
    const root = host();
    const current = state;
    state = null;
    if (!root) return current;
    root.classList.add('hidden');
    root.classList.remove('flex');
    root.querySelector('[data-confirm-backdrop]').classList.replace('opacity-100', 'opacity-0');
    const panel = root.querySelector('[data-confirm-panel]');
    panel.classList.remove('translate-y-0', 'opacity-100');
    panel.classList.add('translate-y-4', 'opacity-0');
    if (current?.returnFocus?.isConnected) current.returnFocus.focus({ preventScroll: true });
    return current;
}

function proceed(current) {
    // Livewire may have re-rendered the trigger while the dialog was open: find its replacement.
    let el = current.el;
    if (!el.isConnected && current.click) {
        el = [...document.querySelectorAll('[wire\\:click]')].find(
            (candidate) => candidate.getAttribute('wire:click') === current.click && confirmAttribute(candidate),
        );
    }
    if (!el) return;

    const nativeConfirm = window.confirm;
    passing = true;
    window.confirm = () => true;
    try {
        el.click();
    } finally {
        window.confirm = nativeConfirm;
        passing = false;
    }
}

document.addEventListener(
    'click',
    (event) => {
        if (passing) return;

        const root = host();
        if (state && root?.contains(event.target)) {
            if (event.target.closest('[data-confirm-backdrop]')) {
                close();
                return;
            }
            const button = event.target.closest('button');
            if (!button) return;
            if (button.hasAttribute('data-confirm-ok')) {
                const current = close();
                if (current) proceed(current);
            } else if (button.hasAttribute('data-confirm-cancel')) {
                close();
            }
            return;
        }

        const el = findTrigger(event.target);
        if (!el || el.disabled) return;
        const attr = confirmAttribute(el);
        if (attr.name.includes('.prompt')) return; // prompt needs typed input: keep the native flow
        if (!root) return; // no modal on this page: fall back to the native dialog

        event.preventDefault();
        event.stopImmediatePropagation();
        if (!state) open(el, attr);
    },
    true,
);

document.addEventListener(
    'keydown',
    (event) => {
        if (!state) return;
        const root = host();
        if (!root) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation(); // do not also close a form modal underneath
            close();
        } else if (event.key === 'Tab') {
            const items = focusables(root);
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (!root.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    },
    true,
);

document.addEventListener('livewire:navigating', () => {
    if (state) close();
});
