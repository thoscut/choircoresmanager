/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The size of the notes in the score views (archive and shared pieces),
 * remembered in this browser (milestone 0.12): whoever needs larger notes
 * on the phone should not have to zoom in again for every piece.
 */
import { readStored, writeStored } from '../storage.ts'

/** Verovio scale per zoom step; ZOOM_DEFAULT is shown as 100 %. */
export const ZOOM_STEPS = [16, 20, 24, 28, 32, 36, 40, 46, 52, 60, 70, 80]
export const ZOOM_DEFAULT = 6

const STORAGE_KEY = 'zoom'

/**
 * The zoom step chosen last in this browser, or the default.
 */
export function storedZoom(): number {
	const stored = readStored(STORAGE_KEY)
	const value = stored === null ? NaN : Number(stored)
	return Number.isInteger(value) && value >= 0 && value < ZOOM_STEPS.length ? value : ZOOM_DEFAULT
}

/**
 * Remember the zoom step in this browser.
 *
 * @param index - index into ZOOM_STEPS
 */
export function storeZoom(index: number): void {
	writeStored(STORAGE_KEY, String(index))
}

/**
 * The zoom step after a change, within the steps.
 *
 * @param index - current step
 * @param step - +1 bigger, -1 smaller
 */
export function nextZoom(index: number, step: number): number {
	return Math.min(ZOOM_STEPS.length - 1, Math.max(0, index + step))
}
