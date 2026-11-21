<script setup lang="ts">
/**
 * 添付画像への書き込み（図形・手書き・文字）。質問事項のメッセージに付ける画像を、送る前に添削エディタで編集する。
 * 全画面。「完了」で合成した JPEG を返し、「キャンセル」で何もしない。
 */
import { onBeforeUnmount, ref } from 'vue'
import AnnotationEditor from '@/components/AnnotationEditor.vue'
import { useUiStore } from '@/stores/ui'
import type { AnnotationDoc } from '@/types'

const props = defineProps<{ blob: Blob; name: string }>()
const emit = defineEmits<{ close: []; save: [blob: Blob] }>()

const ui = useUiStore()
const url = URL.createObjectURL(props.blob)
onBeforeUnmount(() => URL.revokeObjectURL(url))

const editor = ref<InstanceType<typeof AnnotationEditor> | null>(null)
const saving = ref(false)
let emitted = false

async function done() {
  if (!editor.value || saving.value) return
  saving.value = true
  emitted = false
  try {
    await editor.value.save()
    if (!emitted) ui.notify('画像の読み込みが終わっていません。少し待ってからもう一度押してください', { center: true })
  } finally {
    saving.value = false
  }
}
function onSave(_doc: AnnotationDoc, blob: Blob) {
  emitted = true
  emit('save', blob)
}
</script>

<template>
  <div class="im ui-fullscreen">
    <div class="im-top">
      <button class="im-btn" @click="emit('close')">キャンセル</button>
      <div class="im-title" :title="name">{{ name }}</div>
      <button class="im-btn primary" :disabled="saving" @click="done">{{ saving ? '保存中…' : '完了' }}</button>
    </div>
    <div class="im-body">
      <AnnotationEditor ref="editor" :image-url="url" :model-value="null" :auto-save="false" fit-to-container @save="onSave" />
    </div>
  </div>
</template>

<style scoped>
.im {
  position: fixed;
  inset: 0;
  z-index: 2700;
  display: flex;
  flex-direction: column;
  background: #f6f7f9;
  padding: 0 env(safe-area-inset-right) 0 env(safe-area-inset-left);
}
.im-top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: max(8px, env(safe-area-inset-top)) 10px 8px;
  background: #fff;
  border-bottom: 1px solid var(--line);
  flex-shrink: 0;
}
.im-title {
  flex: 1;
  min-width: 0;
  text-align: center;
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.im-btn {
  min-height: 36px;
  padding: 0 14px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--primary);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.im-btn.primary {
  background: var(--primary);
  color: #fff;
}
.im-btn:disabled {
  opacity: 0.5;
}
.im-body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 0 0 env(safe-area-inset-bottom);
}
.im-body > :deep(.editor) {
  flex: 1;
  min-height: 0;
}
</style>
