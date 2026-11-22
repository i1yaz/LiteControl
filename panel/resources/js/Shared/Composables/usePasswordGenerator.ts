import { ref } from 'vue';

function randomIndex(max: number): number {
    if (max <= 0) throw new RangeError('max must be greater than 0');
    const limit = Math.floor(0x100000000 / max) * max;
    const buf = new Uint32Array(1);

    let value: number;
    do {
        globalThis.crypto.getRandomValues(buf);
        value = buf[0];
    } while (value >= limit);

    return value % max;
}

export function generateStrongPassword(length = 16): string {
    const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const lower = 'abcdefghijklmnopqrstuvwxyz';
    const digits = '0123456789';
    const symbols = '!@#$%^&*()-_=+';
    const all = upper + lower + digits + symbols;
    const size = Math.max(8, length);

    const chars = [
        upper[randomIndex(upper.length)],
        lower[randomIndex(lower.length)],
        digits[randomIndex(digits.length)],
        symbols[randomIndex(symbols.length)],
    ];

    for (let i = 4; i < size; i++) {
        chars.push(all[randomIndex(all.length)]);
    }

    // Fisher-Yates shuffle with independent random indices
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomIndex(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}

export function useClipboard() {
    const copiedKey = ref<string | null>(null);
    let copyTimer: ReturnType<typeof setTimeout> | null = null;

    const copyText = async (text: string | number, key = 'default') => {
        const value = String(text ?? '');
        if (!value) return;

        try {
            await navigator.clipboard.writeText(value);
        } catch {
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }

        copiedKey.value = key;
        if (copyTimer) clearTimeout(copyTimer);
        copyTimer = setTimeout(() => {
            copiedKey.value = null;
        }, 1800);
    };

    return {
        copiedKey,
        copyText,
    };
}
