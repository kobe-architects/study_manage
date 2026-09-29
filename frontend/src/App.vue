<script setup lang="ts">
import AppDialogHost from '@/components/AppDialogHost.vue'
import DocViewerHost from '@/components/DocViewerHost.vue'
import { useUiStore } from '@/stores/ui'
const ui = useUiStore()
</script>

<template>
  <v-app>
    <router-view />
    <!-- グローバルトースト（iPhone のホームバーに重ならない位置に出す） -->
    <v-snackbar v-model="ui.toastShow" class="app-toast" location="bottom" timeout="-1" color="secondary" rounded="pill">
      <div style="display: flex; align-items: center; gap: 9px">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#7dd88f" stroke-width="2.2">
          <path d="M20 6L9 17l-5-5"></path>
        </svg>
        {{ ui.toast }}
      </div>
    </v-snackbar>
    <!-- アプリ内の書類ビューア（ホーム画面から起動したときの PDF・印刷用一覧の表示） -->
    <DocViewerHost />
    <!-- 確認・お知らせダイアログ（confirm() / alert() の代わり） -->
    <AppDialogHost />
  </v-app>
</template>
