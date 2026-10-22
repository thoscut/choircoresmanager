/**
 * Escape closes the topmost dialog, also while a form field has the focus.
 *
 * The dialogs of @nextcloud/vue close with Escape, but ignore the key while
 * an input, a text area or a select has the focus. Most dialogs of the app
 * start with a form field, so Escape would seldom work. This listener
 * presses the close button of the topmost dialog in that case. A dialog
 * that may not be closed (busy) has no close button; an open list of
 * options (NcSelect) or the browser's own date picker handle Escape first.
 */

/**
 * Whether the target is a form field, in which @nextcloud/vue ignores Escape.
 *
 * @param target - target of the key event
 */
function isFormField(target: EventTarget | null): target is HTMLElement {
	return target instanceof HTMLInputElement
		|| target instanceof HTMLTextAreaElement
		|| target instanceof HTMLSelectElement
}

/**
 * Close button of the dialog that contains the element, if that dialog is
 * the topmost one.
 *
 * @param element - focused form field
 */
function closeButtonFor(element: HTMLElement): HTMLButtonElement | null {
	const mask = element.closest('.modal-mask')
	if (!mask) {
		return null
	}
	const masks = [...document.querySelectorAll('.modal-mask')].filter((candidate) => candidate.getClientRects().length > 0)
	if (masks[masks.length - 1] !== mask) {
		return null
	}
	return mask.querySelector<HTMLButtonElement>('.modal-container__close:not([disabled])')
}

/**
 * Handle a key press (exported for tests).
 *
 * @param event - keydown event
 */
export function onEscapeKeydown(event: KeyboardEvent): void {
	if (event.key !== 'Escape' || event.defaultPrevented || event.isComposing || !isFormField(event.target)) {
		return
	}
	if (event.target.closest('.vs--open')) {
		// NcSelect closes its list of options first
		return
	}
	const button = closeButtonFor(event.target)
	if (button) {
		event.preventDefault()
		button.click()
	}
}

let installed = false

/** Start listening (once). */
export function installEscapeClose(): void {
	if (installed) {
		return
	}
	installed = true
	document.addEventListener('keydown', onEscapeKeydown)
}
