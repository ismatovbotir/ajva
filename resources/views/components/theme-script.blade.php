{{-- Sets html[data-theme] before first paint (saved choice, else the OS preference): no flash of the wrong theme. --}}
<script>
    (function () {
        try {
            var t = localStorage.getItem('theme');
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    })();
</script>
