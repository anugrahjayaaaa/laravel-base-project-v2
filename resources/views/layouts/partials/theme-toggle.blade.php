<button type="button" class="theme-toggle {{ $class ?? 'nav-link text-secondary' }}" title="Toggle theme">
    <i class="theme-icon"></i>
</button>
<script>
    (function() {
        var saved = localStorage.getItem('theme');
        var systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-bs-theme', saved || (systemDark ? 'dark' : 'light'));
    })();
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        if (!localStorage.getItem('theme')) {
            document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
        }
    });
    document.querySelectorAll('.theme-toggle').forEach(function(btn) {
        var icon = btn.querySelector('.theme-icon');
        function update() {
            var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
            if (icon) icon.className = current === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        update();
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            update();
        });
    });
</script>