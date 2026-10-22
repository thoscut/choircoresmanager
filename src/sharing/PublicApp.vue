<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page of a public link (module "public_links", milestone 0.7): one
 * excerpt of a score, viewed and played without signing in, inside
 * Nextcloud's public layout. The server sends the excerpt with the page,
 * already cut to its measures: no further requests, no download, no
 * practice file, no names of accounts, no notes.
 */
import type { PublicExcerpt } from '../types.ts'

import { mdiAlertCircleOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { t } from '../l10n.ts'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
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
</script>

<template>
	<div class="public-excerpt">
		<template v-if="excerpt">
			<h2 class="public-excerpt__title">
				{{ excerpt.title }}
			</h2>
			<p v-if="excerpt.composer" class="public-excerpt__composer">
				{{ excerpt.composer }}
			</p>
			<NcNoteCard type="info">
				{{ t('notenarchiv', 'An excerpt of a score, shared by the choir. Playback comes from a simple synthesizer in your browser; it is no substitute for a recording.') }}
			</NcNoteCard>
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
			:name="t('notenarchiv', 'The excerpt cannot be shown')"
			:description="t('notenarchiv', 'Please reload the page. If it stays empty, the link may no longer be valid.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
			</template>
		</NcEmptyContent>
	</div>
</template>

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

.public-excerpt__composer {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
