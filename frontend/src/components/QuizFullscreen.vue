<script setup lang="ts">
/**
 * 出題中の全画面表示（英単語クイズ・英文法・語法 共用）。
 * 上: 中断（✕）・進み具合・何問目。中: 問題（ここだけスクロール）。
 * 下: 画面の下に固定。選択肢など「下部固定で出すもの」（fixed スロット、または #qf-fixed への Teleport）と、
 *     ボタン列（前へ・わからない／解説・解答／次の問題へ。global.css の .qf-ghost / .qf-main）。
 * 解説などのモーダルは modals スロットに置く（全画面の上に重ねるため）。
 */
defineProps<{
  progress: number
  counter: string
  /** 下部固定の領域（選択肢など）を使う（余白と区切り線を付ける） */
  fixed?: boolean
}>()
const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <div class="qf ui-fullscreen no-gesture">
    <header class="qf-head">
      <button class="qf-x" title="中断" aria-label="中断" @click="emit('close')">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
      <div class="qf-track"><div class="qf-bar" :style="{ width: progress + '%' }"></div></div>
      <span class="qf-count dm">{{ counter }}</span>
      <slot name="head" />
    </header>
    <div class="qf-body">
      <div class="qf-inner"><slot /></div>
    </div>
    <footer class="qf-foot">
      <div class="qf-inner">
        <div id="qf-fixed" class="qf-fixed" :class="{ on: fixed }"><slot name="fixed" /></div>
        <div class="qf-btns"><slot name="footer" /></div>
      </div>
    </footer>
    <slot name="modals" />
  </div>
</template>

<style scoped>
.qf {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  flex-direction: column;
  background: var(--bg);
}
.qf-head {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
  height: calc(54px + env(safe-area-inset-top));
  padding: env(safe-area-inset-top) max(16px, env(safe-area-inset-right)) 0 max(16px, env(safe-area-inset-left));
  background: #fff;
  border-bottom: 1px solid #e9ebee;
  -webkit-user-select: none;
  user-select: none;
}
.qf-x {
  width: 36px;
  height: 36px;
  margin-left: -6px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
}
.qf-x:active {
  background: #f1f2f4;
}
.qf-track {
  flex: 1;
  height: 7px;
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.qf-bar {
  height: 100%;
  background: #3b50cc;
  border-radius: 99px;
  transition: width 0.25s ease;
}
.qf-count {
  font-size: 13px;
  color: var(--mut);
  flex-shrink: 0;
}
.qf-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  padding: 18px max(16px, env(safe-area-inset-right)) 28px max(16px, env(safe-area-inset-left));
}
.qf-inner {
  max-width: 680px;
  margin: 0 auto;
}
.qf-foot {
  flex-shrink: 0;
  padding: 10px max(16px, env(safe-area-inset-right)) calc(10px + env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
  background: #fff;
  border-top: 1px solid #e9ebee;
  box-shadow: 0 -6px 18px rgba(20, 24, 32, 0.05);
}
/* 下部固定の選択肢など。高すぎるときはこの中だけスクロール */
.qf-fixed {
  max-height: 46vh;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}
.qf-fixed.on {
  padding-bottom: 10px;
  margin-bottom: 10px;
  border-bottom: 1px solid #f0f1f3;
}
.qf-btns {
  display: flex;
  gap: 10px;
  align-items: stretch;
}
@media (min-width: 700px) {
  .qf-body {
    padding-top: 26px;
  }
  .qf-foot {
    padding-top: 12px;
    padding-bottom: calc(12px + env(safe-area-inset-bottom));
  }
}
</style>
