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
                    <div id="confirmModalError" class="alert alert-danger d-none mt-2 mb-0"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    @php($action = $action ?? 'Delete')
                    @php($variant = $variant ?? 'danger')
                    @php($btnClass = match($variant) {
                        'warning' => 'bg-warning-subtle text-warning',
                        'info' => 'bg-info-subtle text-info',
                        default => 'bg-danger-subtle text-danger',
                    })
                    @php($confirmBtnLabel = $confirmLabel ?? $action)
                    <button type="submit" class="btn {{ $btnClass }}">
                        {{ $confirmBtnLabel }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
