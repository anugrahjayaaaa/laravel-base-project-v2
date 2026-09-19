<button type="button" class="{{ $class ?? 'nav-link text-secondary' }}"
        id="theme-toggle" title="Toggle theme">
    <i id="theme-icon"></i>
</button>
<script>
    (function() {
        var saved = localStorage.getItem('theme');
        var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var theme = saved || (systemDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
        updateThemeIcon();
    })();
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        if (!localStorage.getItem('theme')) {
            document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
            updateThemeIcon();
        }
    });
    function updateThemeIcon() {
        var icon = document.getElementById('theme-icon');
        if (!icon) return;
        var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        icon.className = current === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
    document.getElementById('theme-toggle').addEventListener('click', function(e) {
        e.preventDefault();
        var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
        updateThemeIcon();
    });
</script>