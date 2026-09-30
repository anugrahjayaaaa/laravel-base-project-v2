/**
 * Action config — single source of truth for modal title + message per action.
 * Used by confirmation-modal.js and bulk-actions.js.
 * __ITEM__ in msg is replaced dynamically (bold).
 */
export function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Look up an action's copy, or null if the key is unknown.
 *
 * Both drivers used to fall back to ACTION_CONFIG.delete, so a typo'd or
 * half-renamed key silently offered "Move X to trash?" on a Lock button. No
 * log, no error — the user just reads the wrong words and clicks Confirm.
 * Returning null instead forces the caller to decide, and the warn makes the
 * typo visible while developing.
 */
export function resolveAction(key) {
    if (key && ACTION_CONFIG[key]) return ACTION_CONFIG[key];

    if (key) {
        console.warn('[action-config] unknown action key:', key, '— known keys:', Object.keys(ACTION_CONFIG).join(', '));
    }

    return null;
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
    // P6-E6. The server refuses to add or remove superadmin without a
    // deliberate confirmation (AssignRolesAction::guardSuperadminChange), so
    // the copy has to say what is being confirmed — a generic "are you sure?"
    // would be the wrong prompt for a change this permanent.
    remove_superadmin: {
        title: 'Remove Superadmin',
        msg: 'Remove the superadmin role from <b>__ITEM__</b>? They will immediately lose every permission in the system and will not be able to undo this. If they are the last superadmin, the change is refused.',
        variant: 'danger',
        icon: 'bi bi-shield-exclamation'
    },
    grant_superadmin: {
        title: 'Grant Superadmin',
        msg: 'Give <b>__ITEM__</b> the superadmin role? They will hold every permission in the system, including the ability to grant it to others.',
        variant: 'danger',
        icon: 'bi bi-shield-fill-check'
    },
    delete_role: {
        title: 'Move Role to Trash',
        msg: 'Move <b>__ITEM__</b> to trash? The role stops granting anything immediately and is removed from every user holding it. It can be restored, but those users are not re-assigned automatically.',
        variant: 'danger',
        icon: 'bi bi-shield-x'
    },
    restore_role: {
        title: 'Restore Role',
        msg: 'Restore <b>__ITEM__</b>? Its permission set comes back intact, but no user is re-assigned to it — assign it again yourself.',
        variant: 'success',
        icon: 'bi bi-shield-check'
    },
    force_delete_role: {
        title: 'Delete Role Permanently',
        msg: 'Permanently delete <b>__ITEM__</b>? This cannot be undone.',
        variant: 'danger',
        icon: 'bi bi-exclamation-triangle'
    },
    // --- session ---
    logout_all: {
        title: 'Logout All Devices?',
        msg: 'Are you sure you want to logout from all other devices? You will need to login again on those devices.',
        variant: 'danger',
        icon: 'bi bi-signpost-2'
    },
};