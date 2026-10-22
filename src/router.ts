import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'
import { routes } from './navigation.ts'

const router = createRouter({
	history: createWebHistory(generateUrl('/apps/choirscores')),
	routes,
})

export default router
