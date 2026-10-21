import { createApp } from 'vue'
import ShareApp from './share/ShareApp.vue'

import './types/nextcloud.d.ts'

const app = createApp(ShareApp)
app.mount('#orchestrascoresmanager-share')
