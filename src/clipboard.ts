/**
 * Copy text to the clipboard, with a fallback for browsers that block the
 * asynchronous clipboard API (e.g. outside a secure context).
 */

/**
 * @param text - text to copy
 * @return whether copying worked
 */
export async function copyText(text: string): Promise<boolean> {
	try {
		if (navigator.clipboard && window.isSecureContext) {
			await navigator.clipboard.writeText(text)
			return true
		}
	} catch {
		// fall back below
	}
	const area = document.createElement('textarea')
	area.value = text
	area.setAttribute('readonly', '')
	area.style.position = 'fixed'
	area.style.opacity = '0'
	document.body.appendChild(area)
	area.select()
	let copied = false
	try {
		copied = document.execCommand('copy')
	} catch {
		copied = false
	}
	area.remove()
	return copied
}
