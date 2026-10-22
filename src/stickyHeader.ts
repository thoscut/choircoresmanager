/**
 * Keeps the focus visible below a sticky header (WCAG 2.4.11 focus not
 * obscured): the scrolling content area gets a scroll padding as high as
 * the header, so that the browser scrolls a focused element below the
 * header instead of under it (e.g. when tabbing backwards through a list).
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, onMounted } from 'vue'

/** Room between the header and a focused element (px). */
const GAP = 8

let owners = 0

/**
 * Use in a component with a sticky header.
 *
 * @param header - the sticky element
 */
export function useStickyHeader(header: Readonly<Ref<HTMLElement | null>>): void {
	let observer: ResizeObserver | null = null
	let container: HTMLElement | null = null
	const owner = ++owners

	const update = (): void => {
		if (container && header.value) {
			container.style.scrollPaddingTop = `${Math.ceil(header.value.getBoundingClientRect().height) + GAP}px`
			container.dataset.stickyOwner = String(owner)
		}
	}

	onMounted(() => {
		container = header.value?.closest<HTMLElement>('#app-content-vue, .app-content') ?? null
		if (!container || !header.value) {
			return
		}
		update()
		if (typeof ResizeObserver !== 'undefined') {
			observer = new ResizeObserver(update)
			observer.observe(header.value)
		}
	})

	onBeforeUnmount(() => {
		observer?.disconnect()
		// Another view may already have taken over the container
		if (container && container.dataset.stickyOwner === String(owner)) {
			container.style.scrollPaddingTop = ''
			delete container.dataset.stickyOwner
		}
	})
}
