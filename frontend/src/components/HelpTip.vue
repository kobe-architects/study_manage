<script setup lang="ts">
/**
 * ハテナアイコン。マウスではホバー、タッチ端末ではタップで説明文をポップアップ表示する（もう一度タップ・外側タップで閉じる）。
 * 画面右端に近い場所では align="right" で吹き出しを左側に展開する。はみ出す分は自動で内側へずらす。
 */
import { nextTick, onBeforeUnmount, ref } from 'vue'

defineProps<{ text: string; align?: 'left' | 'right' }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const tip = ref<HTMLElement | null>(null)
/** 画面からはみ出さないよう横にずらす量（px） */
const shift = ref(0)
/** 下に入りきらないときは上に開く */
const up = ref(false)

async function measure() {
  shift.value = 0
  up.value = false
  await nextTick()
  requestAnimationFrame(() => {
    const r = tip.value?.getBoundingClientRect()
    if (!r || !r.width) return
    const vw = document.documentElement.clientWidth
    const vh = window.visualViewport?.height ?? window.innerHeight
    if (r.right > vw - 8) shift.value = Math.round(vw - 8 - r.right)
    else if (r.left < 8) shift.value = Math.round(8 - r.left)
    const icon = root.value?.getBoundingClientRect()
    if (icon && r.bottom > vh - 8 && icon.top - r.height > 8) up.value = true
  })
}

function onDocDown(e: Event) {
  if (root.value && !root.value.contains(e.target as Node)) close()
}
function show() {
  open.value = true
  measure()
  document.addEventListener('pointerdown', onDocDown, true)
  window.addEventListener('scroll', close, true)
}
function close() {
  open.value = false
  document.removeEventListener('pointerdown', onDocDown, true)
  window.removeEventListener('scroll', close, true)
}
function toggle() {
  if (open.value) close()
  else show()
}
function onEnter(e: PointerEvent) {
  if (e.pointerType === 'mouse') measure()
}
onBeforeUnmount(close)
</script>

<template>
  <span
    ref="root"
    class="help"
    :class="{ r: align === 'right', open, up }"
    tabindex="0"
    role="button"
    aria-label="説明"
    :aria-expanded="open"
    @click.stop.prevent="toggle"
    @keydown.enter.prevent="toggle"
    @keydown.esc="close"
    @pointerenter="onEnter"
  >
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10" />
      <path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4" />
      <circle cx="12" cy="17.5" r="0.6" fill="currentColor" stroke="none" />
    </svg>
    <span ref="tip" class="tip" :style="{ '--shift': shift + 'px' }">{{ text }}</span>
  </span>
</template>

<style scoped>
.help {
  position: relative;
  display: inline-flex;
  align-items: center;
  color: #9aa1ab;
  cursor: help;
  outline: none;
  flex-shrink: 0;
  /* 見た目は 15px のまま、指で押せる範囲だけ広げる */
  padding: 8px;
  margin: -8px;
  -webkit-user-select: none;
  user-select: none;
}
.help:hover,
.help.open,
.help:focus-visible {
  color: #3b50cc;
}
.tip {
  display: none;
  position: absolute;
  top: calc(100% - 2px);
  left: -2px;
  width: min(340px, 78vw);
  background: #1c2024;
  color: #fff;
  font-size: 11.5px;
  font-weight: 400;
  line-height: 1.8;
  padding: 10px 13px;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
  white-space: pre-line;
  z-index: 120;
  text-align: left;
  cursor: default;
  transform: translateX(var(--shift, 0px));
}
.help.r .tip {
  left: auto;
  right: -2px;
}
/* 吹き出しの三角はアイコンの位置に残す */
.tip::before {
  content: '';
  position: absolute;
  top: -5px;
  left: 14px;
  width: 10px;
  height: 10px;
  background: #1c2024;
  transform: translateX(calc(-1 * var(--shift, 0px))) rotate(45deg);
}
.help.r .tip::before {
  left: auto;
  right: 14px;
}
.help.up .tip {
  top: auto;
  bottom: calc(100% - 2px);
}
.help.up .tip::before {
  top: auto;
  bottom: -5px;
}
.help:hover .tip,
.help.open .tip {
  display: block;
  animation: tipIn 0.16s ease;
}
@keyframes tipIn {
  from {
    opacity: 0;
  }
}
</style>
