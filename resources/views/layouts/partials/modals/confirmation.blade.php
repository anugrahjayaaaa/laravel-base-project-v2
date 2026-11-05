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
                    {{-- Member deactivation only. Hidden AND disabled, so a trigger
                         that does not ask for it cannot submit these keys by
                         accident; the driver enables the block per trigger. The
                         hidden 0 ahead of the checkbox is what makes an unchecked
                         box arrive as violation=0 instead of arriving as nothing. --}}
                    <div id="confirmModalDeactivation" class="d-none mt-3">
                        <div class="form-check form-switch">
                            <input type="hidden" name="violation" value="0" disabled>
                            <input class="form-check-input" type="checkbox" role="switch"
                                id="confirmModalViolation" name="violation" value="1" disabled>
                            <label class="form-check-label" for="confirmModalViolation">Violation of rules</label>
                        </div>
                        <div class="mt-2">
                            <label class="form-label fs-7" for="confirmModalReason">Reason</label>
                            <textarea id="confirmModalReason" name="reason" class="form-control"
                                rows="3" maxlength="500" disabled></textarea>
                            <div class="form-text">Goes on the audit trail. Required for a violation.</div>
                        </div>
                    </div>
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
