import { onMounted, onScopeDispose, toValue } from 'vue';

/**
 * Adaptable click-outside composable following Vue 3 Composition API best practices.
 * Supports refs, getters, and raw DOM elements (MaybeRefOrGetter).
 *
 * @param {import('vue').MaybeRefOrGetter<HTMLElement | null>} target
 * @param {(event: Event) => void} handler
 */
export function useClickOutside(target, handler) {
    if (typeof window === 'undefined') return;

    const listener = (event) => {
        const el = toValue(target);
        if (!el || el === event.target || el.contains(event.target)) {
            return;
        }
        handler(event);
    };

    onMounted(() => {
        window.addEventListener('pointerdown', listener, { passive: true });
    });

    onScopeDispose(() => {
        window.removeEventListener('pointerdown', listener);
    });
}

