import { reactive } from 'vue';

const dialogState = reactive({
    show: false,
    mode: 'confirm', // 'confirm' | 'alert'
    title: '',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    variant: 'primary', // 'primary' | 'danger' | 'warning' | 'info'
});

let pendingResolve = null;

function openDialog(mode, options, defaults) {
    if (pendingResolve) {
        pendingResolve(mode === 'confirm' ? false : undefined);
        pendingResolve = null;
    }

    const opts = typeof options === 'string' ? { message: options } : (options || {});

    dialogState.mode = mode;
    dialogState.title = opts.title ?? defaults.title;
    dialogState.message = opts.message ?? '';
    dialogState.confirmText = opts.confirmText ?? defaults.confirmText;
    dialogState.cancelText = opts.cancelText ?? defaults.cancelText;
    dialogState.variant = opts.variant ?? defaults.variant;
    dialogState.show = true;

    return new Promise((resolve) => {
        pendingResolve = resolve;
    });
}

export function confirmDialog(options) {
    return openDialog('confirm', options, {
        title: 'Confirm Action',
        confirmText: 'Confirm',
        cancelText: 'Cancel',
        variant: 'primary',
    });
}

export function alertDialog(options) {
    return openDialog('alert', options, {
        title: 'Notice',
        confirmText: 'OK',
        cancelText: 'Cancel',
        variant: 'info',
    });
}

export function resolveDialog(result) {
    dialogState.show = false;
    if (pendingResolve) {
        const resolve = pendingResolve;
        pendingResolve = null;
        resolve(result);
    }
}

export function useDialog() {
    return {
        dialogState,
        confirmDialog,
        alertDialog,
        resolveDialog,
    };
}

