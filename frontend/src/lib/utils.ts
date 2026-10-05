import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/**
 * Helper for fluid responsive typography (clamp) between mobile & desktop.
 * Prevents giant headings from overflowing small mobile screens while allowing full scaling on desktop.
 */
export function parseFluidFontSize(val: any): string | undefined {
  if (val === null || val === undefined || val === '') return undefined;
  let numVal: number | null = null;
  if (typeof val === 'number') {
    numVal = val;
  } else if (typeof val === 'string' && !isNaN(Number(val))) {
    numVal = Number(val);
  }

  if (numVal !== null) {
    const remMax = numVal > 15 ? Math.round((numVal / 2.5) * 10) / 10 : numVal;
    const minRem = Math.min(2.1, remMax);
    return `clamp(${minRem}rem, 5.5vw + 0.75rem, ${remMax}rem)`;
  }

  if (typeof val === 'string') return val;
  return undefined;
}

