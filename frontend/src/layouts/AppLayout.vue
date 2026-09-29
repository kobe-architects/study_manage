<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MobileMenu from '@/components/MobileMenu.vue'
import NavIcon from '@/components/NavIcon.vue'
import PullIndicator from '@/components/PullIndicator.vue'
import SplashScreen from '@/components/SplashScreen.vue'
import { ICONS, NAV, daysBetween, parseDate } from '@/lib/design'
import { appConfirm } from '@/lib/dialog'
import { isTouch, viewportWidth } from '@/lib/native'
import { usePageNav } from '@/lib/pageNav'
import { useAuthStore } from '@/stores/auth'
import { useStudyStore } from '@/stores/study'
import { useVocabularyStore } from '@/stores/vocabulary'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const study = useStudyStore()
const vocab = useVocabularyStore()
const ui = useUiStore()

const ready = ref(false)

function fetchVocab() {
  return Promise.all([
    vocab.fetchResources().then((r) => (r ? vocab.fetchStats(r.id) : undefined)),
    vocab.fetchProgress(),
  ])
}

onMounted(async () => {
  try {
    // 認証だけ待って即座にシェルを表示する（全データの取得は待たない）
    await auth.fetchMe()
    ready.value = true
    // 以降の学習データはバックグラウンドで取得し、各画面に届き次第反映する
    study.fetchAll().catch(() => {})
    fetchVocab().catch(() => {})
  } catch {
    // 認証エラー時はインターセプタが /login へ
    ready.value = true
  }
})

const isMobile = computed(() => viewportWidth.value < 860)
const showTopNav = computed(() => !isMobile.value && ui.navStyle === 'トップナビ')
const showSide = computed(() => !isMobile.value && ui.navStyle !== 'トップナビ')
const isRail = computed(() => ui.navStyle === 'アイコンレール')
const navWidth = computed(() => (isRail.value ? '74px' : '232px'))
const showLabels = computed(() => !isRail.value)

// ---- 画面遷移（アニメーション・スクロール位置・引っ張って更新） ----
const scrollEl = ref<HTMLElement | null>(null)
const contentEl = ref<HTMLElement | null>(null)
const { pageAnim, refreshKey, pull, refreshing, pullTrigger, scrollToTop, onPullRefresh, bind } = usePageNav(scrollEl, contentEl)
watch(scrollEl, (el) => bind(el))
onPullRefresh(() => Promise.all([auth.fetchMe(), study.fetchAll(), fetchVocab()]))

const settings = computed(() => auth.settings)
const userName = computed(() => settings.value?.name ?? '学習者')
const userInitial = computed(() => (userName.value || '学')[0])

const daysToExam = computed(() => {
  if (!settings.value?.examDate) return 0
  const exam = parseDate(settings.value.examDate)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.max(0, daysBetween(today, exam))
})
const examDateLabel = computed(() => {
  if (!settings.value?.examDate) return ''
  const d = parseDate(settings.value.examDate)
  return `${d.getFullYear()}.${d.getMonth() + 1}.${d.getDate()}`
})

const activeKey = computed(() => {
  const map: Record<string, string> = {
    home: 'home',
    data: 'data',
    resource: 'resource',
    record: 'record',
    quiz: 'quiz',
    vocabulary: 'quiz',
    review: 'quiz',
    flashcard: 'quiz',
    goals: 'goal',
    quizzes: 'test',
    invoices: 'invoice',
    settings: 'settings',
  }
  return map[route.name as string] ?? 'home'
})

const navItems = computed(() =>
  NAV.map((n) => ({ ...n, icon: ICONS[n.key], active: activeKey.value === n.key })),
)


// スマホ上部バー: 画面名と、下の階層の画面では「‹ 戻る」
const pageTitle = computed(
  () => (route.meta.title as string | undefined) ?? NAV.find((n) => n.key === activeKey.value)?.label ?? '',
)
const parentRoute = computed(() => route.meta.parent as string | undefined)
const parentLabel = computed(() => NAV.find((n) => n.route === parentRoute.value)?.short ?? '戻る')

function goBack() {
  const parent = parentRoute.value
  if (!parent) return
  if (window.history.state?.back === router.resolve({ name: parent }).fullPath) router.back()
  else router.push({ name: parent })
}

/** ナビのタップ。今いる画面のタブをもう一度押したら先頭までスクロールする（ネイティブアプリと同じ） */
function go(routeName: string) {
  if (route.name === routeName) scrollToTop()
  else router.push({ name: routeName })
}

async function logout() {
  if (!(await appConfirm('ログアウトしますか？', { okText: 'ログアウト' }))) return
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <SplashScreen v-if="!ready" message="学習データを読み込み中…" />

  <div v-else class="shell">
    <!-- Desktop top-nav -->
    <header v-if="showTopNav" class="topbar">
      <div class="brand-mini">
        <div class="logo-sm dm">学</div>
        <div style="font-weight: 700; font-size: 15.5px">受験ナビ</div>
      </div>
      <nav class="topnav">
        <button v-for="n in navItems" :key="n.key" class="topnav-btn" :class="{ active: n.active }" @click="go(n.route)">
          <NavIcon :paths="n.icon" :size="18" />
          {{ n.label }}
        </button>
      </nav>
      <div class="user-box">
        <div style="text-align: right; line-height: 1.3">
          <div style="font-size: 12.5px; font-weight: 500">{{ userName }}</div>
          <div style="font-size: 11px; color: var(--faint)">受験まで {{ daysToExam }}日</div>
        </div>
        <div class="avatar">{{ userInitial }}</div>
        <button class="logout-btn" title="ログアウト" @click="logout">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5" /><path d="M21 12H9" /></svg>
        </button>
      </div>
    </header>

    <div class="body">
      <!-- Desktop sidebar / rail -->
      <aside v-if="showSide" class="sidebar" :style="{ width: navWidth }">
        <div class="side-brand">
          <div class="logo-sm dm" style="width: 34px; height: 34px; font-size: 16px">学</div>
          <div v-if="showLabels" style="line-height: 1.25">
            <div style="font-weight: 700; font-size: 15px">受験ナビ</div>
            <div style="font-size: 10.5px; color: var(--faint); letter-spacing: 0.02em">STUDY MANAGER</div>
          </div>
        </div>
        <button
          v-for="n in navItems"
          :key="n.key"
          class="side-btn"
          :class="{ active: n.active }"
          :title="n.label"
          @click="go(n.route)"
        >
          <span class="side-bar" :style="{ height: n.active ? '20px' : '0' }"></span>
          <NavIcon :paths="n.icon" :size="19" />
          <span v-if="showLabels">{{ n.label }}</span>
        </button>
        <div style="flex: 1"></div>
        <div v-if="showLabels" class="exam-box">
          <div style="font-size: 11px; color: var(--faint); margin-bottom: 6px">受験本番まで</div>
          <div style="font-size: 24px; font-weight: 700" class="dm">
            {{ daysToExam }}<span style="font-size: 12px; color: var(--mut); margin-left: 3px">日</span>
          </div>
          <div style="font-size: 11px; color: var(--faint); margin-top: 2px">{{ examDateLabel }}</div>
        </div>
        <button class="side-btn" title="ログアウト" @click="logout">
          <NavIcon :paths="['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9']" :size="19" />
          <span v-if="showLabels">ログアウト</span>
        </button>
      </aside>

      <main class="main">
        <!-- Mobile top bar: 左=ロゴ or 戻る / 中央=画面名 / 右=メニュー -->
        <header v-if="isMobile" class="mobile-top">
          <div class="mt-side">
            <button v-if="parentRoute" class="mt-back" @click="goBack">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
              <span>{{ parentLabel }}</span>
            </button>
            <div v-else class="logo-sm dm" style="width: 28px; height: 28px; font-size: 14px">学</div>
          </div>
          <Transition name="mt-title" mode="out-in">
            <div :key="pageTitle" class="mt-title">{{ pageTitle }}</div>
          </Transition>
          <!-- 右上のハンバーガーメニュー -->
          <div class="mt-side">
            <MobileMenu :items="navItems" :name="userName" :initial="userInitial" :sub="settings?.examDate ? `受験まで ${daysToExam}日（${examDateLabel}）` : undefined" @navigate="go" @logout="logout" />
          </div>
        </header>

        <div ref="scrollEl" class="scroll app-scroll">
          <PullIndicator v-if="isTouch" :pull="pull" :trigger="pullTrigger" :refreshing="refreshing" />
          <div ref="contentEl" class="content" :class="{ m: isMobile }">
            <router-view v-slot="{ Component }">
              <component :is="Component" :key="route.fullPath + '#' + refreshKey" :class="pageAnim" />
            </router-view>
          </div>
        </div>
      </main>
    </div>

  </div>
</template>

<style scoped>
.shell {
  height: 100vh;
  height: 100dvh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.topbar {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 0 20px;
  height: 62px;
  background: #fff;
  border-bottom: 1px solid #e9ebee;
  flex-shrink: 0;
}
.brand-mini {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  white-space: nowrap;
}
.logo-sm {
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: #1c2024;
  color: #fff;
  font-weight: 700;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.topnav {
  display: flex;
  align-items: center;
  gap: 2px;
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  scrollbar-width: none;
}
.topnav::-webkit-scrollbar {
  height: 0;
  display: none;
}
.topnav-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 12px;
  border: none;
  border-radius: 9px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  background: transparent;
  color: var(--mut);
}
.topnav-btn.active {
  background: #f1f2f4;
  color: var(--ink);
}
.user-box {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #eef0f3;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  color: #6b7280;
  font-size: 13px;
}
.logout-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  padding: 6px;
  color: #9aa1ab;
  display: flex;
  align-items: center;
}
.logout-btn:hover {
  color: var(--ink);
}
.body {
  flex: 1;
  display: flex;
  min-height: 0;
}
.sidebar {
  flex-shrink: 0;
  background: #fff;
  border-right: 1px solid #e9ebee;
  display: flex;
  flex-direction: column;
  padding: 18px 14px;
  gap: 4px;
}
.side-brand {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 6px 8px 18px;
}
.side-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 11px;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  font-size: 13.5px;
  font-weight: 500;
  background: transparent;
  color: var(--mut);
  text-align: left;
  position: relative;
}
.side-btn.active {
  background: #f1f2f4;
  color: var(--ink);
}
.side-bar {
  position: absolute;
  left: -14px;
  top: 50%;
  transform: translateY(-50%);
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: #1c2024;
}
.exam-box {
  margin: 8px 4px 4px;
  padding: 13px;
  border-radius: 12px;
  background: #f6f7f9;
}
.main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.mobile-top {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  height: calc(52px + env(safe-area-inset-top));
  padding: env(safe-area-inset-top) max(12px, env(safe-area-inset-right)) 0 max(12px, env(safe-area-inset-left));
  background: #fff;
  border-bottom: 1px solid #e9ebee;
  flex-shrink: 0;
  -webkit-user-select: none;
  user-select: none;
}
.mt-side {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  z-index: 1;
}
.mt-back {
  display: flex;
  align-items: center;
  margin-left: -6px;
  padding: 6px 8px 6px 0;
  border: none;
  background: transparent;
  color: var(--primary);
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
}
.mt-title {
  position: absolute;
  left: 92px;
  right: 92px;
  bottom: 0;
  line-height: 52px;
  text-align: center;
  font-weight: 700;
  font-size: 16px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  pointer-events: none;
}
.mt-title-enter-active,
.mt-title-leave-active {
  transition: opacity 0.14s ease, transform 0.14s ease;
}
.mt-title-enter-from {
  opacity: 0;
  transform: translateY(4px);
}
.mt-title-leave-to {
  opacity: 0;
}
.scroll {
  position: relative;
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  /* 端まで来たときに外側（画面全体）まで引っ張られないようにする */
  overscroll-behavior-y: contain;
}
.content {
  max-width: 1280px;
  margin: 0 auto;
  padding: 28px 30px 40px;
}
.content.m {
  padding: 18px max(16px, env(safe-area-inset-right)) calc(26px + env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
}
</style>
