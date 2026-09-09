<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { quizApi } from '@/api/quiz'
import { renderPage } from '@/lib/pdf'

/**
 * 教材 PDF の1ページをサムネイル表示する。画面に入ったときだけ、ページ単位の小さな PDF を取得して描画する。
 */
const props = defineProps<{ pdfId: number; page: number; width: number; eager?: boolean }>()

const src = ref<string | null>(null)
const error = ref(false)
const el = ref<HTMLElement | null>(null)
let observer: IntersectionObserver | null = null
let loading = false

async function load() {
  if (loading || src.value) return
  loading = true
  try {
    src.value = await renderPage(quizApi.pdfPageUrl(props.pdfId, props.page), props.width)
    error.value = false
  } catch {
    error.value = true
  } finally {
    loading = false
  }
}

onMounted(() => {
  if (props.eager || !('IntersectionObserver' in window)) {
    load()
    return
  }
  observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((e) => e.isIntersecting)) {
        load()
        observer?.disconnect()
      }
    },
    { rootMargin: '200px' },
  )
  if (el.value) observer.observe(el.value)
})

watch(
  () => [props.pdfId, props.page, props.width],
  () => {
    src.value = null
    error.value = false
    load()
  },
)

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div ref="el" class="thumb" :style="{ width: width + 'px' }">
    <img v-if="src" :src="src" alt="" draggable="false" />
    <div v-else class="ph" :class="{ error }">
      <span v-if="error">読込失敗</span>
      <span v-else class="dot"></span>
    </div>
  </div>
</template>

<style scoped>
.thumb {
  position: relative;
  background: #fff;
  border: 1px solid #e3e6ea;
  border-radius: 6px;
  overflow: hidden;
  aspect-ratio: 0.71;
}
.thumb img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.ph {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10.5px;
  color: #aeb4bd;
  background: #f6f7f9;
}
.dot {
  width: 14px;
  height: 14px;
  border: 2px solid #d8dce1;
  border-top-color: #3b50cc;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
