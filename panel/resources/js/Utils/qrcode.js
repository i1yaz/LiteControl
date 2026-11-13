/**
 * Minimal, zero-dependency QR Code generator (Byte mode, EC Level L, Versions 1-10).
 * Returns a 2D boolean matrix (true = dark module) or an SVG data string.
 */

// GF(256) log/antilog tables for Reed-Solomon (primitive polynomial 0x11d)
const EXP = new Uint8Array(512);
const LOG = new Uint8Array(256);
{
    let x = 1;
    for (let i = 0; i < 255; i++) {
        EXP[i] = x;
        LOG[x] = i;
        x <<= 1;
        if (x & 0x100) x ^= 0x11d;
    }
    for (let i = 255; i < 512; i++) {
        EXP[i] = EXP[i - 255];
    }
}

function gfMul(a, b) {
    if (a === 0 || b === 0) return 0;
    return EXP[LOG[a] + LOG[b]];
}

function rsGeneratorPoly(degree) {
    let poly = [1];
    for (let i = 0; i < degree; i++) {
        const next = new Array(poly.length + 1).fill(0);
        const factor = EXP[i];
        for (let j = 0; j < poly.length; j++) {
            next[j] ^= poly[j];
            next[j + 1] ^= gfMul(poly[j], factor);
        }
        poly = next;
    }
    return poly;
}

function rsEncode(data, ecCount) {
    const gen = rsGeneratorPoly(ecCount);
    const res = new Uint8Array(data.length + ecCount);
    res.set(data);
    for (let i = 0; i < data.length; i++) {
        const coef = res[i];
        if (coef !== 0) {
            for (let j = 0; j < gen.length; j++) {
                res[i + j] ^= gfMul(gen[j], coef);
            }
        }
    }
    return res.slice(data.length);
}

// Version 1-10, EC Level L: [totalDataCodewords, ecCodewordsPerBlock, numBlocksGroup1, dataCodewordsGroup1, numBlocksGroup2, dataCodewordsGroup2]
const VERSION_SPECS_L = [
    null,
    [19, 7, 1, 19, 0, 0],     // v1 (21x21)
    [34, 10, 1, 34, 0, 0],    // v2 (25x25)
    [55, 15, 1, 55, 0, 0],    // v3 (29x29)
    [80, 20, 1, 80, 0, 0],    // v4 (33x33)
    [108, 26, 1, 108, 0, 0],  // v5 (37x37)
    [136, 18, 2, 68, 0, 0],   // v6 (41x41)
    [156, 20, 2, 78, 0, 0],   // v7 (45x45)
    [194, 24, 2, 97, 0, 0],   // v8 (49x49)
    [232, 30, 2, 116, 0, 0],  // v9 (53x53)
    [274, 18, 2, 68, 2, 69],  // v10 (57x57)
];

const ALIGNMENT_POSITIONS = [
    null,
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
];

function encodeData(bytes) {
    let version = 1;
    for (; version <= 10; version++) {
        const cap = VERSION_SPECS_L[version][0];
        const headerBits = 4 + (version >= 10 ? 16 : 8);
        if (Math.ceil((headerBits + bytes.length * 8) / 8) <= cap) {
            break;
        }
    }
    if (version > 10) {
        throw new Error('QR payload too long');
    }

    const [totalData, ecPerBlock, g1Blocks, g1Data, g2Blocks, g2Data] = VERSION_SPECS_L[version];
    const bits = [];
    const pushBits = (val, len) => {
        for (let i = len - 1; i >= 0; i--) {
            bits.push((val >> i) & 1);
        }
    };

    // Byte mode indicator (0100)
    pushBits(0b0100, 4);
    pushBits(bytes.length, version >= 10 ? 16 : 8);
    for (const b of bytes) pushBits(b, 8);

    // Terminator
    const maxBits = totalData * 8;
    for (let i = 0; i < 4 && bits.length < maxBits; i++) bits.push(0);
    while (bits.length % 8 !== 0) bits.push(0);

    const dataCodewords = [];
    for (let i = 0; i < bits.length; i += 8) {
        let byte = 0;
        for (let j = 0; j < 8; j++) byte = (byte << 1) | bits[i + j];
        dataCodewords.push(byte);
    }

    const padBytes = [0xec, 0x11];
    let padIdx = 0;
    while (dataCodewords.length < totalData) {
        dataCodewords.push(padBytes[padIdx % 2]);
        padIdx++;
    }

    // Split into blocks and compute EC
    const dataBlocks = [];
    const ecBlocks = [];
    let offset = 0;
    for (let i = 0; i < g1Blocks; i++) {
        const block = Uint8Array.from(dataCodewords.slice(offset, offset + g1Data));
        offset += g1Data;
        dataBlocks.push(block);
        ecBlocks.push(rsEncode(block, ecPerBlock));
    }
    for (let i = 0; i < g2Blocks; i++) {
        const block = Uint8Array.from(dataCodewords.slice(offset, offset + g2Data));
        offset += g2Data;
        dataBlocks.push(block);
        ecBlocks.push(rsEncode(block, ecPerBlock));
    }

    // Interleave
    const finalBytes = [];
    const maxDataLen = Math.max(g1Data, g2Data);
    for (let i = 0; i < maxDataLen; i++) {
        for (const b of dataBlocks) {
            if (i < b.length) finalBytes.push(b[i]);
        }
    }
    for (let i = 0; i < ecPerBlock; i++) {
        for (const b of ecBlocks) {
            finalBytes.push(b[i]);
        }
    }

    return { version, codewords: finalBytes };
}

function buildMatrix(version, codewords) {
    const size = version * 4 + 17;
    const matrix = Array.from({ length: size }, () => Array(size).fill(null));
    const reserved = Array.from({ length: size }, () => Array(size).fill(false));

    const setModule = (r, c, val, isFunc = true) => {
        if (r < 0 || r >= size || c < 0 || c >= size) return;
        matrix[r][c] = val;
        if (isFunc) reserved[r][c] = true;
    };

    const drawFinder = (r0, c0) => {
        for (let r = -1; r <= 7; r++) {
            for (let c = -1; c <= 7; c++) {
                const rr = r0 + r;
                const cc = c0 + c;
                if (rr < 0 || rr >= size || cc < 0 || cc >= size) continue;
                const inOuter = r >= 0 && r <= 6 && c >= 0 && c <= 6;
                const onBorder = r === 0 || r === 6 || c === 0 || c === 6;
                const inInner = r >= 2 && r <= 4 && c >= 2 && c <= 4;
                setModule(rr, cc, inOuter && (onBorder || inInner));
            }
        }
    };

    drawFinder(0, 0);
    drawFinder(0, size - 7);
    drawFinder(size - 7, 0);

    // Timing patterns
    for (let i = 8; i < size - 8; i++) {
        setModule(6, i, i % 2 === 0);
        setModule(i, 6, i % 2 === 0);
    }

    // Alignment patterns
    const alignPos = ALIGNMENT_POSITIONS[version];
    for (const r0 of alignPos) {
        for (const c0 of alignPos) {
            if (reserved[r0][c0]) continue;
            for (let r = -2; r <= 2; r++) {
                for (let c = -2; c <= 2; c++) {
                    const isDark = Math.abs(r) === 2 || Math.abs(c) === 2 || (r === 0 && c === 0);
                    setModule(r0 + r, c0 + c, isDark);
                }
            }
        }
    }

    // Dark module
    setModule(size - 8, 8, true);

    // Reserve format info
    for (let i = 0; i < 9; i++) {
        if (!reserved[8][i]) reserved[8][i] = true;
        if (!reserved[i][8]) reserved[i][8] = true;
    }
    for (let i = 0; i < 8; i++) {
        if (!reserved[8][size - 1 - i]) reserved[8][size - 1 - i] = true;
        if (!reserved[size - 1 - i][8]) reserved[size - 1 - i][8] = true;
    }

    // Reserve version info for v >= 7
    if (version >= 7) {
        for (let i = 0; i < 6; i++) {
            for (let j = 0; j < 3; j++) {
                reserved[size - 11 + j][i] = true;
                reserved[i][size - 11 + j] = true;
            }
        }
        // Format/version bits for v7-10
        const versionBits = getVersionInfoBits(version);
        for (let i = 0; i < 18; i++) {
            const bit = ((versionBits >> i) & 1) === 1;
            const r = Math.floor(i / 3);
            const c = size - 11 + (i % 3);
            matrix[r][c] = bit;
            matrix[c][r] = bit;
        }
    }

    // Place data bits
    const allBits = [];
    for (const cw of codewords) {
        for (let i = 7; i >= 0; i--) allBits.push(((cw >> i) & 1) === 1);
    }

    let bitIdx = 0;
    let up = true;
    for (let col = size - 1; col > 0; col -= 2) {
        if (col === 6) col--;
        for (let i = 0; i < size; i++) {
            const row = up ? size - 1 - i : i;
            for (let dx = 0; dx < 2; dx++) {
                const c = col - dx;
                if (!reserved[row][c]) {
                    matrix[row][c] = bitIdx < allBits.length ? allBits[bitIdx++] : false;
                }
            }
        }
        up = !up;
    }

    // Evaluate all 8 masks
    let bestMask = 0;
    let bestScore = Infinity;
    let bestMatrix = null;

    for (let mask = 0; mask < 8; mask++) {
        const candidate = matrix.map((row, r) =>
            row.map((val, c) => {
                if (reserved[r][c]) return val;
                return applyMask(mask, r, c) ? !val : val;
            })
        );
        writeFormatInfo(candidate, size, mask);
        const score = penaltyScore(candidate, size);
        if (score < bestScore) {
            bestScore = score;
            bestMask = mask;
            bestMatrix = candidate;
        }
    }

    return bestMatrix;
}

function applyMask(mask, r, c) {
    switch (mask) {
        case 0: return (r + c) % 2 === 0;
        case 1: return r % 2 === 0;
        case 2: return c % 3 === 0;
        case 3: return (r + c) % 3 === 0;
        case 4: return (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0;
        case 5: return ((r * c) % 2) + ((r * c) % 3) === 0;
        case 6: return (((r * c) % 2) + ((r * c) % 3)) % 2 === 0;
        case 7: return (((r + c) % 2) + ((r * c) % 3)) % 2 === 0;
        default: return false;
    }
}

function getFormatInfoBits(mask) {
    // EC Level L = 01
    const data = (0b01 << 3) | mask;
    let rem = data << 10;
    const gen = 0b10100110111;
    for (let i = 14; i >= 10; i--) {
        if ((rem >> i) & 1) rem ^= gen << (i - 10);
    }
    return ((data << 10) | rem) ^ 0b101010000010010;
}

function getVersionInfoBits(version) {
    let rem = version << 12;
    const gen = 0b1111100100101;
    for (let i = 17; i >= 12; i--) {
        if ((rem >> i) & 1) rem ^= gen << (i - 12);
    }
    return (version << 12) | rem;
}

function writeFormatInfo(matrix, size, mask) {
    const bits = getFormatInfoBits(mask);
    for (let i = 0; i < 15; i++) {
        const bit = ((bits >> i) & 1) === 1;
        if (i < 6) matrix[i][8] = bit;
        else if (i < 8) matrix[i + 1][8] = bit;
        else matrix[size - 15 + i][8] = bit;

        if (i < 8) matrix[8][size - 1 - i] = bit;
        else if (i < 9) matrix[8][15 - i] = bit;
        else matrix[8][14 - i] = bit;
    }
}

function penaltyScore(m, size) {
    let score = 0;
    // Rule 1: 5+ consecutive same-color modules
    for (let r = 0; r < size; r++) {
        let run = 1;
        for (let c = 1; c < size; c++) {
            if (m[r][c] === m[r][c - 1]) {
                run++;
                if (run === 5) score += 3;
                else if (run > 5) score += 1;
            } else {
                run = 1;
            }
        }
    }
    for (let c = 0; c < size; c++) {
        let run = 1;
        for (let r = 1; r < size; r++) {
            if (m[r][c] === m[r - 1][c]) {
                run++;
                if (run === 5) score += 3;
                else if (run > 5) score += 1;
            } else {
                run = 1;
            }
        }
    }
    // Rule 2: 2x2 blocks
    for (let r = 0; r < size - 1; r++) {
        for (let c = 0; c < size - 1; c++) {
            const v = m[r][c];
            if (v === m[r][c + 1] && v === m[r + 1][c] && v === m[r + 1][c + 1]) {
                score += 3;
            }
        }
    }
    return score;
}

/**
 * Generate an SVG path string (d attribute) and viewBox size for a QR code.
 */
export function generateQrSvgData(text, quietZone = 2) {
    const bytes = new TextEncoder().encode(text);
    const { version, codewords } = encodeData(bytes);
    const matrix = buildMatrix(version, codewords);
    const size = matrix.length;
    const total = size + quietZone * 2;
    let d = '';
    for (let r = 0; r < size; r++) {
        for (let c = 0; c < size; c++) {
            if (matrix[r][c]) {
                d += `M${c + quietZone},${r + quietZone}h1v1h-1z`;
            }
        }
    }
    return { viewBox: `0 0 ${total} ${total}`, path: d };
}

