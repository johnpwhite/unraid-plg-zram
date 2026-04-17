import { describe, it, expect } from 'vitest';
import { formatBytes, formatBytesRound, filterHistory } from '../../src/js/zram-card.js';

describe('formatBytes', () => {
  it('returns "0 B" for zero, negative, and NaN inputs', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(-1)).toBe('0 B');
    expect(formatBytes(NaN)).toBe('0 B');
    expect(formatBytes('not a number')).toBe('0 B');
  });

  it('formats bytes with default 2 decimal places', () => {
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(1024)).toBe('1 KB');
    expect(formatBytes(1536)).toBe('1.5 KB');
  });

  it('scales up through KB/MB/GB/TB', () => {
    expect(formatBytes(1048576)).toBe('1 MB');
    expect(formatBytes(1073741824)).toBe('1 GB');
    expect(formatBytes(1099511627776)).toBe('1 TB');
  });

  it('respects custom decimal precision', () => {
    expect(formatBytes(1536, 0)).toBe('2 KB');
    expect(formatBytes(1536, 3)).toBe('1.5 KB');
  });
});

describe('formatBytesRound', () => {
  // Axis tick formatter — rounds to nearest 5 in the natural unit for clean
  // graph labels without the noise of "762.9 MB" / "381.5 MB".

  it('returns "0 B" for zero, negative, and NaN', () => {
    expect(formatBytesRound(0)).toBe('0 B');
    expect(formatBytesRound(-1)).toBe('0 B');
    expect(formatBytesRound(NaN)).toBe('0 B');
  });

  it('rounds MB values to nearest 5', () => {
    // 762.9 MB ≈ 800,090,624 bytes → rounds to 765 MB
    expect(formatBytesRound(800_090_624)).toBe('765 MB');
    // 381.5 MB ≈ 400,031,744 bytes → rounds to 380 MB
    expect(formatBytesRound(400_031_744)).toBe('380 MB');
    // 250 MB = 262,144,000 bytes → stays 250 MB
    expect(formatBytesRound(262_144_000)).toBe('250 MB');
  });

  it('drops to a smaller unit when the value is below the step threshold', () => {
    // 1 KB (1024 bytes): 1 in KB is < 5, so fall back to bytes → 1025 B
    expect(formatBytesRound(1024)).toBe('1025 B');
    // 1 MB (1,048,576 bytes): 1 in MB is < 5, fall to KB → 1025 KB
    expect(formatBytesRound(1_048_576)).toBe('1025 KB');
  });

  it('respects a custom step', () => {
    // step=10 → 762.9 MB rounds to 760 MB
    expect(formatBytesRound(800_090_624, 10)).toBe('760 MB');
    // step=1 means "round to nearest integer" effectively
    expect(formatBytesRound(800_090_624, 1)).toBe('763 MB');
  });

  it('handles GB-scale values', () => {
    // 2.3 GB = ~2,469,606,195 bytes → rounds scaled 2.3 GB.
    // 2.3 in GB is < 5, drops to MB: scaled = 2355.2 MB → rounds to 2355 MB.
    expect(formatBytesRound(2_469_606_195)).toBe('2355 MB');
  });
});

describe('filterHistory', () => {
  // Regression guard for 2026.04.17.01 schema migration: the collector changed
  // history entries from {t,s,l} (saved) to {t,o,u,l} (original + used). The
  // frontend must drop legacy entries so the chart doesn't render bogus zeros.

  it('keeps new-schema entries {t,o,u,l}', () => {
    const raw = [
      { t: '12:00:00', o: 1000, u: 300, l: 5.0 },
      { t: '12:00:03', o: 1100, u: 320, l: 5.2 },
    ];
    const filtered = filterHistory(raw);
    expect(filtered).toHaveLength(2);
    expect(filtered[0].o).toBe(1000);
    expect(filtered[1].u).toBe(320);
  });

  it('drops legacy-schema entries {t,s,l}', () => {
    const raw = [
      { t: '11:59:00', s: 700, l: 4.0 },
      { t: '11:59:03', s: 750, l: 4.1 },
    ];
    expect(filterHistory(raw)).toHaveLength(0);
  });

  it('keeps only new entries in a mixed-schema array', () => {
    const raw = [
      { t: '11:59:00', s: 700, l: 4.0 },           // legacy — dropped
      { t: '12:00:00', o: 1000, u: 300, l: 5.0 },  // new — kept
      { t: '12:00:03', s: 999 },                    // legacy partial — dropped
      { t: '12:00:06', o: 1100, u: 320, l: 5.2 },  // new — kept
    ];
    const filtered = filterHistory(raw);
    expect(filtered).toHaveLength(2);
    expect(filtered.map(e => e.t)).toEqual(['12:00:00', '12:00:06']);
  });

  it('tolerates malformed input', () => {
    expect(filterHistory(null)).toEqual([]);
    expect(filterHistory(undefined)).toEqual([]);
    expect(filterHistory('not an array')).toEqual([]);
    expect(filterHistory([null, undefined, { o: 1, u: 2, l: 0 }])).toHaveLength(1);
  });

  it('handles entries where o or u is explicitly 0 (boundary — must be kept)', () => {
    const raw = [
      { t: '12:00:00', o: 0, u: 0, l: 0 },  // ZRAM inactive but data structure valid
    ];
    expect(filterHistory(raw)).toHaveLength(1);
  });
});
