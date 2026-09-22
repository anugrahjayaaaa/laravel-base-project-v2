<script>
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-password-toggle]');
        if (!btn) return;
        var target = document.getElementById(btn.dataset.passwordToggle);
        if (!target) return;
        var isPassword = target.type === 'password';
        target.type = isPassword ? 'text' : 'password';
        var icon = btn.querySelector('i');
        icon.classList.toggle('fa-eye', !isPassword);
        icon.classList.toggle('fa-eye-slash', isPassword);
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
</script>
