<script setup lang="ts">
/**
 * 「操作」ボタン。タップするとボタンの下に操作の一覧がぽよんと出て、1 つ選ぶと閉じる。
 * 表の中でボタンが折り返してしまう幅（タブレット・スマホ）で使う。一覧は body 直下に出すので表の横スクロール枠に切られない。
 */
import { onBeforeUnmount, ref } from 'vue'

export interface ActionMenuItem {
  key: string
  label: string
  /** 削除など取り消せない操作は赤字にする */
  danger?: boolean
}

defineProps<{ items: ActionMenuItem[]; label?: string }>()
const emit = defineEmits<{ select: [key: string] }>()

const open = ref(false)
const btn = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function place() {
  const r = btn.value?.getBoundingClientRect()
  if (!r) return
  const w = 180
  pos.value = { top: r.bottom + 6, left: Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) }
}
function show() {
  place()
  open.value = true
  window.setTimeout(() => {
    document.addEventListener('pointerdown', onDocDown, true)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    window.addEventListener('keydown', onKey)
  })
}
function close() {
  open.value = false
  document.removeEventListener('pointerdown', onDocDown, true)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
  window.removeEventListener('keydown', onKey)
}
function toggle() {
  if (open.value) close()
  else show()
}
function onDocDown(e: Event) {
  const t = e.target as HTMLElement
  if (!t.closest('.am-pop') && !btn.value?.contains(t)) close()
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') close()
}
function pick(key: string) {
  close()
  emit('select', key)
}
onBeforeUnmount(close)
</script>

<template>
  <button ref="btn" type="button" class="am-btn" :class="{ on: open }" :aria-expanded="open" @click.stop="toggle">
    {{ label ?? '操作' }}
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
  </button>
  <Teleport to="body">
    <Transition name="am">
      <div v-if="open" class="am-pop" role="menu" :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
        <button
          v-for="(it, i) in items"
          :key="it.key"
          type="button"
          class="am-item"
          :class="{ danger: it.danger }"
          :style="{ '--i': i }"
          role="menuitem"
          @click="pick(it.key)"
        >
          {{ it.label }}
        </button>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.am-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 9px 5px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
  white-space: nowrap;
}
.am-btn svg {
  transition: transform 0.2s ease;
}
.am-btn.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.am-btn.on svg {
  transform: rotate(180deg);
}
</style>

<style>
/* 一覧は body 直下に出すためグローバル */
.am-pop {
  position: fixed;
  z-index: 1200;
  min-width: 150px;
  padding: 6px;
  border-radius: 14px;
  background: #fff;
  border: 1px solid #e3e6ea;
  box-shadow: 0 14px 36px rgba(15, 20, 30, 0.2);
  display: flex;
  flex-direction: column;
  gap: 2px;
  transform-origin: top left;
}
.am-item {
  display: block;
  width: 100%;
  padding: 10px 14px;
  border: none;
  border-radius: 9px;
  background: transparent;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ink);
  text-align: left;
  cursor: pointer;
  white-space: nowrap;
  animation: amItem 0.34s cubic-bezier(0.34, 1.56, 0.64, 1) both;
  animation-delay: calc(var(--i, 0) * 40ms + 40ms);
}
.am-item:active {
  background: #f1f2f4;
  opacity: 1;
}
.am-item.danger {
  color: #c0444f;
}
@media (hover: hover) {
  .am-item:hover {
    background: #f3f4f6;
  }
}
/* ぽよんと出る */
.am-enter-active {
  animation: amPop 0.36s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.am-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}
.am-leave-to {
  opacity: 0;
  transform: scale(0.92);
}
@keyframes amPop {
  from {
    opacity: 0;
    transform: scale(0.6);
  }
}
@keyframes amItem {
  from {
    opacity: 0;
    transform: translateY(-6px) scale(0.9);
  }
}
</style>
