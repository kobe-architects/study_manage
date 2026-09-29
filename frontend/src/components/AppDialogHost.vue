<script setup lang="ts">
/**
 * アプリ内の確認・お知らせダイアログ（lib/dialog.ts の appConfirm / appAlert）。App.vue に 1 つだけ置く。
 * iOS のアラートと同じく、画面中央の小さなカードに本文とボタンを並べる。
 */
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { answerDialog, dialogState } from '@/lib/dialog'

const okBtn = ref<HTMLButtonElement | null>(null)

watch(
  () => dialogState.current?.id,
  async (id) => {
    if (!id) return
    // キーボード操作（Enter で OK）のため OK ボタンにフォーカス。タッチ端末ではフォーカス枠を出さない
    await nextTick()
    okBtn.value?.focus({ preventScroll: true })
  },
)

function onKey(e: KeyboardEvent) {
  if (!dialogState.current) return
  if (e.key === 'Escape') {
    e.preventDefault()
    answerDialog(false)
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Transition name="ui-modal">
    <div v-if="dialogState.current" :key="dialogState.current.id" class="dlg-bg ui-overlay" role="alertdialog" aria-modal="true">
      <div class="dlg ui-panel">
        <div class="body">
          <div v-if="dialogState.current.title" class="title">{{ dialogState.current.title }}</div>
          <div class="msg" :class="{ solo: !dialogState.current.title }">{{ dialogState.current.message }}</div>
        </div>
        <div class="btns">
          <button v-if="dialogState.current.kind === 'confirm'" class="b cancel" @click="answerDialog(false)">
            {{ dialogState.current.cancelText }}
          </button>
          <button ref="okBtn" class="b ok" :class="{ danger: dialogState.current.danger }" @click="answerDialog(true)">
            {{ dialogState.current.okText }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.dlg-bg {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: rgba(20, 24, 32, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.dlg {
  width: min(320px, 100%);
  background: rgba(255, 255, 255, 0.97);
  border-radius: 16px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}
.body {
  padding: 20px 18px 16px;
  text-align: center;
}
.title {
  font-size: 15px;
  font-weight: 700;
  margin-bottom: 6px;
}
.msg {
  font-size: 13px;
  line-height: 1.65;
  color: #3d434b;
  white-space: pre-line;
  word-break: break-word;
}
.msg.solo {
  font-size: 13.5px;
  color: var(--ink);
}
.btns {
  display: flex;
  border-top: 1px solid #e6e8ec;
}
.b {
  flex: 1;
  min-height: 46px;
  border: none;
  background: transparent;
  font-size: 14.5px;
  font-family: inherit;
  cursor: pointer;
  color: #3b50cc;
  outline: none;
}
.b + .b {
  border-left: 1px solid #e6e8ec;
}
.b.ok {
  font-weight: 700;
}
.b.ok.danger {
  color: #d23c4b;
}
.b:focus-visible {
  background: #f1f3f8;
}
@media (hover: hover) {
  .b:hover {
    background: #f5f6f8;
  }
}
</style>
