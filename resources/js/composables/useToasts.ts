import { readonly, ref } from 'vue';

export type ToastVariant = 'default' | 'success' | 'warning' | 'error';

export interface ToastItem {
    id: string;
    message: string;
    variant?: ToastVariant;
    duration?: number;
}

const toasts = ref<ToastItem[]>([]);

function removeToast(id: string) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function pushToast(
    message: string,
    options: Partial<Omit<ToastItem, 'id' | 'message'>> = {},
) {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const duration = options.duration ?? 3200;

    toasts.value = [
        {
            id,
            message,
            variant: options.variant ?? 'default',
            duration,
        },
        ...toasts.value,
    ].slice(0, 4);

    if (duration > 0) {
        setTimeout(() => removeToast(id), duration);
    }
}

export function useToasts() {
    return {
        toasts: readonly(toasts),
        pushToast,
        removeToast,
    };
}
