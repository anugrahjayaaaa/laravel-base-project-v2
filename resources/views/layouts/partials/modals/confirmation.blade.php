<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="confirmModalForm" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header" id="confirmModalHeader">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fs-3" id="confirmModalIcon"></i>
                        <h5 class="modal-title" id="confirmModalTitle">Confirm Action</h5>
                    </div>
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
                        'warning' => 'btn btn-warning',
                        'info' => 'btn btn-info',
                        default => 'btn btn-danger',
                    })
                    @php($confirmBtnLabel = $confirmLabel ?? $action)
                    <button type="submit" class="btn {{ $btnClass }}" id="confirmModalSubmit">
                        {{ $confirmBtnLabel }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
