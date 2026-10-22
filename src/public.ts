import { createApp } from 'vue'
import PublicApp from './public/PublicApp.vue'

import './types/nextcloud.d.ts'

const app = createApp(PublicApp)
app.mount('#choirscores-public')
