<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MobileMenu from '@/components/MobileMenu.vue'
import NavIcon from '@/components/NavIcon.vue'
import PullIndicator from '@/components/PullIndicator.vue'
import SplashScreen from '@/components/SplashScreen.vue'
import { ICONS, daysBetween, parseDate } from '@/lib/design'
import { appConfirm } from '@/lib/dialog'
import { isTouch, viewportWidth } from '@/lib/native'
import { usePageNav } from '@/lib/pageNav'
import { useAuthStore } from '@/stores/auth'
import { useStudyStore } from '@/stores/study'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const study = useStudyStore()

const ready = ref(false)

onMounted(async () => {
  try {
    await auth.fetchMe()
    ready.value = true
    // 生徒の学習データ（課題）をバックグラウンドで取得
    study.fetchAssignments().catch(() => {})
  } catch {
    // 認証エラー時はインターセプタが /login へ
    ready.value = true
  }
})

/** スマホ・iPad 縦: 上部バー＋右上のハンバーガーメニュー */
const isMobile = computed(() => viewportWidth.value < 860)

// ---- 画面遷移（アニメーション・スクロール位置・引っ張って更新） ----
const scrollEl = ref<HTMLElement | null>(null)
const contentEl = ref<HTMLElement | null>(null)
const { pageAnim, refreshKey, pull, refreshing, pullTrigger, scrollToTop, onPullRefresh, bind } = usePageNav(scrollEl, contentEl)
watch(scrollEl, (el) => bind(el))
onPullRefresh(() => Promise.all([auth.fetchMe(), study.fetchAssignments()]))

const studentName = computed(() => auth.user?.student?.name ?? '生徒')
const tutorName = computed(() => auth.user?.name ?? '先生')
const tutorInitial = computed(() => (tutorName.value || '先')[0])

const daysToExam = computed(() => {
  const exam = auth.user?.student?.examDate
  if (!exam) return null
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.max(0, daysBetween(today, parseDate(exam)))
})

const NAV_TUTOR = [
  { key: 'home', route: 'tutor-home', label: 'トップページ', short: 'トップ', icon: ICONS.home },
  { key: 'subjects', route: 'tutor-subjects', label: '科目別学習状況', short: '科目別', icon: ICONS.data },
  { key: 'assignment', route: 'tutor-assignments', label: '課題設定', short: '課題', icon: ICONS.record },
  { key: 'quiz', route: 'tutor-quizzes', label: '小テスト', short: '小テスト', icon: ICONS.test },
  { key: 'invoice', route: 'tutor-invoices', label: '請求書管理', short: '請求', icon: ICONS.invoice },
]

/** 「小テスト」「科目別学習状況」は生徒側の設定でオンのときだけ表示する */
const navItems = computed(() =>
  NAV_TUTOR.filter((n) => {
    if (n.key === 'quiz') return auth.user?.student?.tutorQuizEnabled === true
    if (n.key === 'subjects') return auth.user?.student?.tutorSubjectsEnabled !== false
    return true
  }),
)

const activeKey = computed(() => {
  const map: Record<string, string> = {
    'tutor-home': 'home',
    'tutor-subjects': 'subjects',
    'tutor-assignments': 'assignment',
    'tutor-quizzes': 'quiz',
    'tutor-quiz-grade': 'quiz',
    'tutor-invoices': 'invoice',
  }
  return map[route.name as string] ?? 'home'
})

// スマホ上部バー: 画面名と、下の階層の画面では「‹ 戻る」
const pageTitle = computed(
  () => (route.meta.title as string | undefined) ?? NAV_TUTOR.find((n) => n.key === activeKey.value)?.label ?? '',
)
const parentRoute = computed(() => route.meta.parent as string | undefined)
const parentLabel = computed(() => NAV_TUTOR.find((n) => n.route === parentRoute.value)?.short ?? '戻る')

function goBack() {
  const parent = parentRoute.value
  if (!parent) return
  if (window.history.state?.back === router.resolve({ name: parent }).fullPath) router.back()
  else router.push({ name: parent })
}

/** ナビのタップ。今いる画面のタブをもう一度押したら先頭までスクロールする */
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
  <SplashScreen v-if="!ready" />

  <div v-else class="shell">
    <!-- PC・iPad 横: 上部ナビ -->
    <header v-if="!isMobile" class="topbar">
      <div class="brand-mini">
        <div class="logo-sm dm">師</div>
        <div style="line-height: 1.25">
          <div style="font-weight: 700; font-size: 15.5px; white-space: nowrap">受験ナビ <span class="tutor-tag">講師用</span></div>
        </div>
      </div>
      <nav class="topnav">
        <button
          v-for="n in navItems"
          :key="n.key"
          class="topnav-btn"
          :class="{ active: activeKey === n.key }"
          @click="go(n.route)"
        >
          <NavIcon :paths="n.icon" :size="18" />
          {{ n.label }}
        </button>
      </nav>
      <div class="user-box">
        <div style="text-align: right; line-height: 1.3">
          <div style="font-size: 12.5px; font-weight: 500">{{ tutorName }}</div>
          <div style="font-size: 11px; color: var(--faint); white-space: nowrap">
            担当: {{ studentName }}<template v-if="daysToExam !== null">・受験まで{{ daysToExam }}日</template>
          </div>
        </div>
        <div class="avatar">{{ tutorInitial }}</div>
        <button class="logout-btn" title="ログアウト" @click="logout">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="M16 17l5-5-5-5" /><path d="M21 12H9" /></svg>
        </button>
      </div>
    </header>

    <!-- スマホ・iPad 縦: 左=ロゴ or 戻る / 中央=画面名 / 右=メニュー -->
    <header v-else class="mobile-top">
      <div class="mt-side">
        <button v-if="parentRoute" class="mt-back" @click="goBack">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
          <span>{{ parentLabel }}</span>
        </button>
        <div v-else class="logo-sm dm" style="width: 28px; height: 28px; font-size: 14px">師</div>
      </div>
      <Transition name="mt-title" mode="out-in">
        <div :key="pageTitle" class="mt-title">{{ pageTitle }}</div>
      </Transition>
      <!-- 右上のハンバーガーメニュー -->
      <div class="mt-side">
        <MobileMenu
          :items="navItems.map((n) => ({ ...n, active: activeKey === n.key }))"
          :name="tutorName"
          :initial="tutorInitial"
          :sub="`担当: ${studentName}` + (daysToExam !== null ? `・受験まで${daysToExam}日` : '')"
          tutor
          @navigate="go"
          @logout="logout"
        />
      </div>
    </header>

    <main class="main">
      <div ref="scrollEl" class="scroll app-scroll">
        <PullIndicator v-if="isTouch" :pull="pull" :trigger="pullTrigger" :refreshing="refreshing" />
        <div ref="contentEl" class="content" :class="{ m: isMobile }">
          <!-- スマホでは担当生徒を上部バーに出せないので、先頭に小さく表示する -->
          <div v-if="isMobile && !parentRoute" class="m-student">
            担当: {{ studentName }}<template v-if="daysToExam !== null">・受験まで{{ daysToExam }}日</template>
          </div>
          <router-view v-slot="{ Component }">
            <component :is="Component" :key="route.fullPath + '#' + refreshKey" :class="pageAnim" />
          </router-view>
        </div>
      </div>
    </main>

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
  gap: 14px;
  padding: 0 18px;
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
  background: #2e4a8f;
  color: #fff;
  font-weight: 700;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tutor-tag {
  font-size: 10.5px;
  font-weight: 700;
  color: #2e4a8f;
  background: #e8eefb;
  padding: 2px 8px;
  border-radius: 99px;
  vertical-align: 2px;
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
  background: #e8eefb;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  color: #2e4a8f;
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
.main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.scroll {
  position: relative;
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  overscroll-behavior-y: contain;
}
.content {
  max-width: 1100px;
  margin: 0 auto;
  padding: 24px 22px 40px;
}
.content.m {
  padding: 14px max(16px, env(safe-area-inset-right)) calc(26px + env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
}
.m-student {
  font-size: 11.5px;
  color: var(--faint);
  margin-bottom: 10px;
}

/* ---------- スマホ・iPad 縦 ---------- */
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
</style>
