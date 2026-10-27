<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The authors besides the composer and the notices of the licenses, on the
 * pages for choir members and the public (milestone 0.11): names only, as
 * the server sends them.
 */
import type { Credit } from '../types.ts'

import { t } from '../l10n.ts'
import { computed } from 'vue'

const props = defineProps<{
	credits: Credit[]
	notices: string[]
}>()

const lines = computed(() => props.credits.map((credit) => {
	switch (credit.role) {
	case 'lyricist':
		return t('notenarchiv', 'Text: {name}', { name: credit.name })
	case 'translator':
		return t('notenarchiv', 'Translation: {name}', { name: credit.name })
	default:
		return t('notenarchiv', 'Arrangement: {name}', { name: credit.name })
	}
}))
</script>

<template>
	<div v-if="lines.length > 0 || notices.length > 0" class="attribution">
		<p v-if="lines.length > 0" class="attribution__credits">
			{{ lines.join(' · ') }}
		</p>
		<p v-for="(notice, index) in notices" :key="index" class="attribution__notice">
			{{ notice }}
		</p>
	</div>
</template>

<style scoped>
.attribution p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
