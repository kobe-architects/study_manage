<script setup lang="ts">
/**
 * スマホ・iPad 縦のメニュー。上部バー右上のハンバーガーボタンと、右からスライドして出るメニュー。
 * 背景タップ・×・右へスワイプ・Esc で閉じる。項目を選ぶと閉じてから画面を切り替える。
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import NavIcon from '@/components/NavIcon.vue'

interface MobileMenuItem {
  key: string
  route: string
  label: string
  icon: string[]
  active: boolean
}

const props = defineProps<{
  items: MobileMenuItem[]
  name: string
  initial: string
  /** 名前の下の小さな説明（受験までの日数など） */
  sub?: string
  /** 講師用の配色にする */
  tutor?: boolean
}>()
const emit = defineEmits<{ navigate: [route: string]; logout: [] }>()

const route = useRoute()
const open = ref(false)
const panel = ref<HTMLElement | null>(null)

function close() {
  open.value = false
}
function pick(r: string) {
  close()
  emit('navigate', r)
}
function logout() {
  close()
  emit('logout')
}

// 別の方法で画面が変わったときも閉じる
watch(() => route.fullPath, close)

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && open.value) close()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// ---- 右へスワイプして閉じる ----
let sw: { x0: number; y0: number; dx: number; active: boolean; lastX: number; lastT: number; v: number } | null = null
function onTouchStart(e: TouchEvent) {
  if (e.touches.length !== 1) return
  const t = e.touches[0]!
  sw = { x0: t.clientX, y0: t.clientY, dx: 0, active: false, lastX: t.clientX, lastT: e.timeStamp, v: 0 }
}
function onTouchMove(e: TouchEvent) {
  if (!sw || !panel.value) return
  const t = e.touches[0]!
  const dx = t.clientX - sw.x0
  const dy = t.clientY - sw.y0
  if (!sw.active) {
    if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
    // 縦の動き・左への動きは項目一覧のスクロールに任せる
    if (dx <= 0 || Math.abs(dy) > dx) {
      sw = null
      return
    }
    sw.active = true
  }
  e.preventDefault()
  const dt = e.timeStamp - sw.lastT
  if (dt > 0) sw.v = (t.clientX - sw.lastX) / dt
  sw.lastX = t.clientX
  sw.lastT = e.timeStamp
  sw.dx = Math.max(0, dx)
  panel.value.style.transition = 'none'
  panel.value.style.transform = `translateX(${sw.dx}px)`
}
function onTouchEnd() {
  const s = sw
  sw = null
  const el = panel.value
  if (!s?.active || !el) return
  if (s.dx > Math.min(90, el.offsetWidth * 0.3) || (s.v > 0.5 && s.dx > 24)) {
    // 指を離した位置から画面外まで流して閉じる
    el.style.transition = 'transform 0.22s cubic-bezier(0.4, 0, 1, 1)'
    el.style.transform = 'translateX(100%)'
    close()
  } else {
    el.style.transition = 'transform 0.28s cubic-bezier(0.2, 0.9, 0.25, 1)'
    el.style.transform = ''
  }
}
</script>

<template>
  <button class="mm-btn" :class="{ tutor }" aria-label="メニュー" :aria-expanded="open" @click="open = !open">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <path d="M4 7h16" />
      <path d="M4 12h16" />
      <path d="M4 17h16" />
    </svg>
  </button>

  <!-- ヘッダーの重なり順に縛られないよう body 直下に出す -->
  <Teleport to="body">
    <Transition name="mm" :duration="{ enter: 620, leave: 260 }">
      <div v-if="open" class="mm ui-lock" :class="{ tutor }">
        <div class="mm-scrim" @click="close"></div>
        <nav
          ref="panel"
          class="mm-panel"
          aria-label="メニュー"
          @touchstart.passive="onTouchStart"
          @touchmove="onTouchMove"
          @touchend="onTouchEnd"
          @touchcancel="onTouchEnd"
        >
          <div class="mm-head">
            <div class="mm-avatar">{{ initial }}</div>
            <div class="mm-user">
              <div class="mm-name">{{ name }}</div>
              <div v-if="sub" class="mm-sub">{{ sub }}</div>
            </div>
            <button class="mm-x" aria-label="閉じる" @click="close">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
          </div>

          <div class="mm-list">
            <button
              v-for="(n, i) in props.items"
              :key="n.key"
              class="mm-item"
              :class="{ active: n.active }"
              :style="{ '--i': i }"
              :aria-current="n.active ? 'page' : undefined"
              @click="pick(n.route)"
            >
              <NavIcon :paths="n.icon" :size="21" />
              <span>{{ n.label }}</span>
            </button>
          </div>

          <slot name="footer" />

          <button class="mm-logout" @click="logout">
            <NavIcon :paths="['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9']" :size="19" />
            ログアウト
          </button>
        </nav>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.mm-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  margin-right: -8px;
  border: none;
  border-radius: 12px;
  background: transparent;
  color: var(--ink);
  cursor: pointer;
}
.mm-btn:active {
  background: #f1f2f4;
}

.mm {
  position: fixed;
  inset: 0;
  z-index: 1000;
}
.mm-scrim {
  position: absolute;
  inset: 0;
  background: rgba(20, 24, 32, 0.38);
}
.mm-panel {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: min(86vw, 320px);
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 18px 0 0 18px;
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.16);
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) 0;
  overscroll-behavior: contain;
}
.mm-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 14px 14px 18px;
  border-bottom: 1px solid #eef0f3;
}
.mm-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #eef0f3;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 15px;
  flex-shrink: 0;
}
.tutor .mm-avatar {
  background: #e8eefb;
  color: #2e4a8f;
}
.mm-user {
  flex: 1;
  min-width: 0;
  line-height: 1.35;
}
.mm-name {
  font-size: 15px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.mm-sub {
  font-size: 11.5px;
  color: var(--faint);
  margin-top: 2px;
}
.mm-x {
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 50%;
  background: #f2f3f5;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
}
.mm-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 10px 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.mm-item {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 48px;
  padding: 0 14px;
  border: none;
  border-radius: 12px;
  background: transparent;
  color: #4b5260;
  font-size: 14.5px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
}
.mm-item:active {
  background: #f3f4f6;
  opacity: 1;
}
.mm-item.active {
  background: #eef0f5;
  color: var(--ink);
  font-weight: 700;
}
.tutor .mm-item.active {
  background: #e8eefb;
  color: #2e4a8f;
}
.mm-logout {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 52px;
  margin: 0 10px 6px;
  padding: 0 14px;
  border: none;
  border-top: 1px solid #eef0f3;
  background: transparent;
  color: var(--mut);
  font-size: 14px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
}

/* 開閉アニメーション: 背景はフェード、メニューは右からスライド、項目は少しずつ遅れて出る */
.mm-enter-active .mm-scrim,
.mm-leave-active .mm-scrim {
  transition: opacity 0.26s ease;
}
.mm-enter-from .mm-scrim,
.mm-leave-to .mm-scrim {
  opacity: 0;
}
.mm-enter-active .mm-panel {
  transition: transform 0.36s cubic-bezier(0.2, 0.9, 0.25, 1);
}
.mm-leave-active .mm-panel {
  transition: transform 0.24s cubic-bezier(0.4, 0, 1, 1);
}
.mm-enter-from .mm-panel,
.mm-leave-to .mm-panel {
  transform: translateX(100%);
}
.mm-enter-active .mm-item {
  transition:
    opacity 0.3s ease,
    transform 0.3s cubic-bezier(0.2, 0.9, 0.25, 1);
  transition-delay: calc(var(--i) * 22ms + 80ms);
}
.mm-enter-from .mm-item {
  opacity: 0;
  transform: translateX(18px);
}
@media (prefers-reduced-motion: reduce) {
  .mm-enter-active .mm-panel,
  .mm-leave-active .mm-panel,
  .mm-enter-active .mm-item {
    transition-duration: 0.01s;
    transition-delay: 0s;
  }
}
</style>
