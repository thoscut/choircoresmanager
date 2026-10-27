<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page of a public link (module "public_links", milestone 0.7): a
 * whole piece or an excerpt of a score, viewed and played without signing
 * in, inside Nextcloud's public layout. The server sends the score with the
 * page (the excerpt already cut to its measures): no download of the score
 * file, no practice file, no names of accounts, no notes. When the link
 * offers the scan, a link opens it in the browser's own PDF viewer.
 */
import type { PublicExcerpt } from '../types.ts'

import { mdiAlertCircleOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { t } from '../l10n.ts'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Attribution from './Attribution.vue'
import ExcerptViewer from './ExcerptViewer.vue'

/**
 * The excerpt from the page, or null if it is missing.
 */
function initialExcerpt(): PublicExcerpt | null {
	try {
		const excerpt = loadState<PublicExcerpt | null>('notenarchiv', 'excerpt', null)
		return excerpt && typeof excerpt.xml === 'string' && excerpt.xml !== '' ? excerpt : null
	} catch {
		return null
	}
}

const excerpt = initialExcerpt()

/** The scan of this link, next to the page's own address (/p/{token}/scan) */
const scanUrl = window.location.pathname.replace(/\/+$/, '') + '/scan'
</script>

<template>
	<!-- The public layout of Nextcloud has no main landmark of its own -->
	<main class="public-excerpt">
		<template v-if="excerpt">
			<h2 class="public-excerpt__title">
				{{ excerpt.title }}
			</h2>
			<p v-if="excerpt.composer" class="public-excerpt__composer">
				{{ excerpt.composer }}
			</p>
			<Attribution :credits="excerpt.credits ?? []" :notices="excerpt.notices ?? []" />
			<NcNoteCard type="info">
				{{ excerpt.measureFrom === null
					? t('notenarchiv', 'A piece shared by the choir. Playback comes from a simple synthesizer in your browser; it is no substitute for a recording.')
					: t('notenarchiv', 'An excerpt of a score, shared by the choir. Playback comes from a simple synthesizer in your browser; it is no substitute for a recording.') }}
			</NcNoteCard>
			<p v-if="excerpt.scan">
				<!-- The browser's own PDF viewer, in a tab of its own -->
				<a :href="scanUrl"
					class="public-excerpt__scan"
					target="_blank"
					rel="noopener noreferrer">{{ t('notenarchiv', 'Open the scan (PDF)') }}<span class="hidden-visually"> {{ t('notenarchiv', '(opens in a new tab)') }}</span></a>
			</p>
			<ExcerptViewer :xml="excerpt.xml"
				:title="excerpt.title"
				:composer="excerpt.composer"
				:measure-from="excerpt.measureFrom"
				:measure-to="excerpt.measureTo"
				:voice="excerpt.voice"
				:verse="excerpt.verse"
				:tempo="excerpt.tempo"
				:can-play="excerpt.canPlay" />
		</template>
		<NcEmptyContent v-else
			:name="t('notenarchiv', 'The score cannot be shown')"
			:description="t('notenarchiv', 'Please reload the page. If it stays empty, the link may no longer be valid.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
			</template>
		</NcEmptyContent>
	</main>
</template>

<style>
/* Nextcloud's public layout holds #content to the height of the window and
   clips it: the app scrolls in its own element, as in the other apps.
   Without this the page cannot be scrolled at all, on a phone not even to
   the score. */
#notenarchiv-public {
	flex: 1 1 auto;
	min-width: 0;
	overflow-y: auto;
}
</style>

<style scoped>
.public-excerpt {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	box-sizing: border-box;
	width: 100%;
	max-width: 1200px;
	margin: 0 auto;
	padding: calc(3 * var(--default-grid-baseline));
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.public-excerpt__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.public-excerpt__scan {
	display: inline-block;
	min-height: var(--default-clickable-area);
	line-height: var(--default-clickable-area);
	color: var(--color-main-text);
	text-decoration: underline;
}

.public-excerpt__scan:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.public-excerpt__composer {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
