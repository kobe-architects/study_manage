<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue'
import client from '@/api/client'

/**
 * 認証（Bearer トークン）が必要な API 経由の画像を表示するコンポーネント。
 * ネイティブの <img src> ではトークンが付与されず 401 になるため、
 * axios クライアントで blob として取得し object URL に変換して表示する。
 */
const props = defineProps<{ src: string | null; alt?: string }>()

const objectUrl = ref<string | null>(null)

function release() {
  if (objectUrl.value) {
    URL.revokeObjectURL(objectUrl.value)
    objectUrl.value = null
  }
}

async function load(src: string | null) {
  release()
  if (!src) return
  // baseURL('/api') と重複しないよう先頭の絶対 URL/`/api` を除去（クエリは保持）
  const path = src.replace(/^.*\/api/, '')
  try {
    const res = await client.get(path, { responseType: 'blob' })
    objectUrl.value = URL.createObjectURL(res.data)
  } catch {
    objectUrl.value = null
  }
}

watch(() => props.src, (s) => load(s), { immediate: true })
onBeforeUnmount(release)
</script>

<template>
  <img v-if="objectUrl" :src="objectUrl" :alt="alt ?? ''" />
</template>
