/**
 * Alpine component of the monitor builder ("constructor"). The canvas is the real widget grid drawn inside a 16:9 TV
 * frame (96rem x 54rem, scaled to the page with a CSS transform), with an overlay layer above every widget:
 *
 *  - drag the overlay to move, the 4 corner and 4 edge handles to resize, a palette item onto the canvas to add;
 *  - everything snaps to the 12-column grid and shows a ghost + a "w x h" badge while the pointer is down;
 *  - on release one Livewire action is called (move / place / addWidgetAt); the SERVER clamps the size to the catalog
 *    limits and resolves collisions (App\Monitor\Grid), so the browser never decides the layout;
 *  - pointer events only (no library); the number inputs of the side panel and the arrow keys are the keyboard / touch
 *    alternative, a tap on a palette item still adds it.
 *
 * All measurements are in unscaled layout pixels (offsetWidth ignores transforms); pointer deltas are divided by the zoom.
 */
export default function monitorCanvas({ cols = 12 } = {}) {
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

    return {
        scale: 1,
        frameW: 1536,
        frameH: 864,
        mode: null, // 'move' | 'resize' | 'palette'
        id: null,
        edge: null,
        ghost: null,
        origin: null,
        limits: null,
        pending: null, // palette press that has not moved far enough to be a drag yet
        floating: null,
        overCanvas: false,
        justDragged: false,
        // Light / dark preview of the TV frame; the same localStorage key as the real monitor screens.
        theme: 'dark',

        init() {
            try {
                this.theme = localStorage.getItem('monitorTheme') === 'light' ? 'light' : 'dark';
            } catch (e) {}
            this.fit();
            const observer = new ResizeObserver(() => this.fit());
            observer.observe(this.$refs.stage);
            this.$watch('$wire.zoom', () => this.$nextTick(() => this.fit()));
            window.addEventListener('beforeunload', () => observer.disconnect());
            // A drag that ends over another element would fire a click on the common ancestor (e.g. "click empty canvas" =
            // deselect); swallow the click that follows a real drag.
            this.$refs.frame.addEventListener('click', (e) => {
                if (this.justDragged) {
                    e.stopPropagation();
                    e.preventDefault();
                }
            }, true);
        },

        fit() {
            const frame = this.$refs.frame;
            if (!frame) {
                return;
            }
            this.frameW = frame.offsetWidth || 1536;
            this.frameH = frame.offsetHeight || 864;
            const zoom = String(this.$wire.zoom || 'fit');
            const style = getComputedStyle(this.$refs.stage);
            const avail = (this.$refs.stage.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0)) || this.frameW;
            // On a phone the whole screen is shown small (an overview); 75% / 100% in the zoom select give a scrollable close-up.
            this.scale = zoom === 'fit' ? clamp(avail / this.frameW, 0.2, 1.5) : parseFloat(zoom) || 1;
        },

        saveTheme() {
            try {
                localStorage.setItem('monitorTheme', this.theme);
            } catch (e) {}
        },

        grid() {
            return this.$refs.frame.querySelector('.mon-grid');
        },

        metrics() {
            const grid = this.grid();
            const gap = parseFloat(getComputedStyle(grid).columnGap) || 0;
            const rows = Math.max(1, parseInt(grid.dataset.rows, 10) || 1);

            return {
                grid,
                gap,
                rows,
                stepX: (grid.offsetWidth + gap) / cols,
                stepY: (grid.offsetHeight + gap) / rows,
            };
        },

        begin(event, mode, edge = null) {
            const tile = event.currentTarget.closest('[data-widget-id]');
            if (!tile || event.button > 0 || this.mode) {
                return;
            }

            const d = tile.dataset;
            this.mode = mode;
            this.edge = edge;
            this.id = d.widgetId;
            this.origin = { px: event.clientX, py: event.clientY, x: +d.x, y: +d.y, w: +d.w, h: +d.h };
            this.limits = { minW: +d.minW, maxW: +d.maxW, minH: +d.minH, maxH: +d.maxH };
            this.ghost = { x: +d.x, y: +d.y, w: +d.w, h: +d.h };
        },

        /** Press on a palette item: becomes a drag only after a few pixels, otherwise it is a tap (click adds). */
        beginPalette(event, key, w, h) {
            if (event.button > 0 || this.mode) {
                return;
            }
            this.pending = { key, w, h, x: event.clientX, y: event.clientY, label: event.currentTarget.dataset.label || key };
        },

        drag(event) {
            if (this.pending) {
                if (Math.hypot(event.clientX - this.pending.x, event.clientY - this.pending.y) < 6) {
                    return;
                }
                this.mode = 'palette';
                this.id = this.pending.key;
                this.origin = this.pending;
                this.pending = null;
            }
            if (!this.mode) {
                return;
            }

            const m = this.metrics();
            const s = this.scale;
            const o = this.origin;

            if (this.mode === 'palette') {
                const r = m.grid.getBoundingClientRect();
                const px = (event.clientX - r.left) / s;
                const py = (event.clientY - r.top) / s;
                this.floating = { x: event.clientX, y: event.clientY };
                this.overCanvas = px >= 0 && py >= 0 && px <= m.grid.offsetWidth && py <= m.grid.offsetHeight + m.stepY * 2;
                this.ghost = {
                    x: clamp(Math.floor(px / m.stepX) - Math.floor(o.w / 2), 0, cols - o.w),
                    y: Math.max(0, Math.floor(py / m.stepY) - Math.floor(o.h / 2)),
                    w: o.w,
                    h: o.h,
                };

                return;
            }

            const dx = Math.round((event.clientX - o.px) / s / m.stepX);
            const dy = Math.round((event.clientY - o.py) / s / m.stepY);
            const l = this.limits;

            if (this.mode === 'move') {
                this.ghost = { ...o, x: clamp(o.x + dx, 0, cols - o.w), y: Math.max(0, o.y + dy) };

                return;
            }

            let { x, y, w, h } = o;
            const e = this.edge;
            if (e.includes('e')) {
                w = clamp(o.w + dx, l.minW, Math.min(l.maxW, cols - o.x));
            }
            if (e.includes('w')) {
                w = clamp(o.w - dx, l.minW, Math.min(l.maxW, o.x + o.w));
                x = o.x + o.w - w;
            }
            if (e.includes('s')) {
                h = clamp(o.h + dy, l.minH, l.maxH);
            }
            if (e.includes('n')) {
                h = clamp(o.h - dy, l.minH, Math.min(l.maxH, o.y + o.h));
                y = o.y + o.h - h;
            }
            this.ghost = { x, y, w, h };
        },

        end() {
            this.pending = null;
            if (!this.mode) {
                return;
            }

            const { mode, id, ghost, origin, overCanvas } = this;
            this.reset();

            if (mode === 'palette') {
                this.justDragged = true;
                setTimeout(() => (this.justDragged = false), 80);
                if (overCanvas && ghost) {
                    this.$wire.addWidgetAt(id, ghost.x, ghost.y);
                }
            } else if (mode === 'move' && (ghost.x !== origin.x || ghost.y !== origin.y)) {
                this.swallowClick();
                this.$wire.move(id, ghost.x, ghost.y);
            } else if (mode === 'resize' && (ghost.x !== origin.x || ghost.y !== origin.y || ghost.w !== origin.w || ghost.h !== origin.h)) {
                this.swallowClick();
                this.$wire.place(id, ghost.x, ghost.y, ghost.w, ghost.h);
            } else if (mode === 'resize') {
                this.swallowClick();
            }
        },

        swallowClick() {
            this.justDragged = true;
            setTimeout(() => (this.justDragged = false), 120);
        },

        cancel() {
            this.pending = null;
            this.reset();
        },

        reset() {
            this.mode = null;
            this.id = null;
            this.edge = null;
            this.ghost = null;
            this.floating = null;
            this.overCanvas = false;
        },

        /** A tap (or Enter) on a palette item adds the widget at the bottom; a finished drag must not add it twice. */
        add(key) {
            if (!this.justDragged) {
                this.$wire.addWidget(key);
            }
        },

        shortcut(event) {
            const t = event.target;
            if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) {
                return;
            }
            if (event.key === 'Escape') {
                this.cancel();
            } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
                event.preventDefault();
                event.shiftKey ? this.$wire.redo() : this.$wire.undo();
            } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
                event.preventDefault();
                this.$wire.redo();
            }
        },

        ghostStyle() {
            if (!this.ghost || !this.mode) {
                return { display: 'none' };
            }

            const m = this.metrics();
            const g = this.ghost;

            return {
                display: 'block',
                left: g.x * m.stepX + 'px',
                top: g.y * m.stepY + 'px',
                width: g.w * m.stepX - m.gap + 'px',
                height: g.h * m.stepY - m.gap + 'px',
            };
        },
    };
}
