/**
 * Action config — single source of truth for modal title + message per action.
 * Used by confirmation-modal.js and bulk-actions.js.
 * __ITEM__ in msg is replaced dynamically (bold).
 */
export function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export const ACTION_CONFIG = {
    // --- destructive ---
    delete: {
        title: 'Move to Trash',
        msg: 'Move <b>__ITEM__</b> to trash? They can be restored later.',
        variant: 'danger',
        icon: 'bi bi-trash'
    },
    force_delete: {
        title: 'Permanent Delete',
        msg: 'Permanently delete <b>__ITEM__</b>? This cannot be undone.',
        variant: 'danger',
        icon: 'bi bi-exclamation-triangle'
    },
    // --- state ---
    restore: {
        title: 'Restore',
        msg: 'Restore <b>__ITEM__</b>? They will be reactivated.',
        variant: 'success',
        icon: 'bi bi-person-check'
    },
    lock: {
        title: 'Lock',
        msg: 'Lock <b>__ITEM__</b>? All sessions will be revoked.',
        variant: 'warning',
        icon: 'bi bi-lock'
    },
    unlock: {
        title: 'Unlock',
        msg: 'Unlock <b>__ITEM__</b>? They can log in again.',
        variant: 'success',
        icon: 'bi bi-unlock'
    },
    activate: {
        title: 'Activate',
        msg: 'Activate <b>__ITEM__</b>? They can log in again.',
        variant: 'success',
        icon: 'bi bi-person-check'
    },
    deactivate: {
        title: 'Deactivate',
        msg: 'Deactivate <b>__ITEM__</b>? They will be immediately logged out.',
        variant: 'warning',
        icon: 'bi bi-person-slash'
    },
    // --- session ---
    logout_all: {
        title: 'Logout All Devices?',
        msg: 'Are you sure you want to logout from all other devices? You will need to login again on those devices.',
        variant: 'danger',
        icon: 'bi bi-signpost-2'
    },
};