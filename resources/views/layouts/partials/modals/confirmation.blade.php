<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="confirmModalForm" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header" id="confirmModalHeader">
                    <h5 class="modal-title" id="confirmModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmModalMessage">Are you sure?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    @php($action = $action ?? 'Delete')
                    @php($variant = $variant ?? 'danger')
                    @php($btnClass = $variant === 'warning' ? 'btn-warning' : ($variant === 'info' ? 'btn-info' : 'btn-danger'))
                    @php($confirmBtnLabel = $confirmLabel ?? $action)
                    <button type="submit" class="btn {{ $btnClass }} {{ $variant === 'info' ? 'text-white' : '' }}">
                        {{ $confirmBtnLabel }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    // Reusable confirmation modal: triggers set data attributes, this listener copies them
    (function () {
        var modalEl = document.getElementById('confirmModal');
        if (!modalEl) return;
        var modal = new bootstrap.Modal(modalEl);
        var form = modalEl.querySelector('#confirmModalForm');
        var header = modalEl.querySelector('#confirmModalHeader');
        var title = modalEl.querySelector('#confirmModalTitle');
        var message = modalEl.querySelector('#confirmModalMessage');
        var submitBtn = modalEl.querySelector('button[type="submit"]');

        modalEl.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            var action = btn.getAttribute('data-action') || '#';
            var titleText = btn.getAttribute('data-title') || 'Confirm Action';
            var msgText = btn.getAttribute('data-message') || 'Are you sure?';
            var variant = btn.getAttribute('data-variant') || 'danger';
            var confirmLabel = btn.getAttribute('data-label') || (btn.getAttribute('data-action-label') || 'Confirm');

            form.action = action;
            form.method = btn.getAttribute('data-method') || 'POST';

            // Append PUT/DELETE method as hidden input if needed
            var methodInput = form.querySelector('input[name="_method"]');
            if (!methodInput) {
                methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                form.appendChild(methodInput);
            }
            methodInput.value = btn.getAttribute('data-method') || 'POST';

            title.textContent = titleText;
            message.textContent = msgText;

            // Variant styling
            header.className = 'modal-header ' +
                (variant === 'warning' ? 'bg-warning bg-opacity-10 border-warning' :
                 variant === 'info' ? 'bg-info bg-opacity-10 border-info' :
                 'bg-danger bg-opacity-10 border-danger');

            var btnClass = variant === 'warning' ? 'btn-warning' :
                          variant === 'info' ? 'btn-info' : 'btn-danger';
            submitBtn.className = 'btn ' + btnClass;
            submitBtn.textContent = confirmLabel;
        });
    })();
</script>
