import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

export function formatAbsoluteTime(value: string | Date | null): string {
    if (!value) {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

export function formatRelativeTime(
    value: string | Date | null,
    now: number | Date = new Date(),
): string {
    if (!value) {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const base = typeof now === 'number' ? new Date(now) : now;
    const diffSeconds = Math.round((date.getTime() - base.getTime()) / 1000);
    const absSeconds = Math.abs(diffSeconds);

    if (absSeconds < 45) {
        return 'just now';
    }

    if (absSeconds < 90) {
        return diffSeconds < 0 ? '1m ago' : 'in 1m';
    }

    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    if (absSeconds < 3600) {
        return rtf.format(Math.round(diffSeconds / 60), 'minute');
    }

    if (absSeconds < 86400) {
        return rtf.format(Math.round(diffSeconds / 3600), 'hour');
    }

    if (absSeconds < 2592000) {
        return rtf.format(Math.round(diffSeconds / 86400), 'day');
    }

    if (absSeconds < 31536000) {
        return rtf.format(Math.round(diffSeconds / 2592000), 'month');
    }

    return rtf.format(Math.round(diffSeconds / 31536000), 'year');
}
