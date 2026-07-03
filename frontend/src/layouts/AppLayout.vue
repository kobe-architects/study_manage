<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NavIcon from '@/components/NavIcon.vue'
import { ICONS, NAV, daysBetween, parseDate } from '@/lib/design'
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

const vw = ref(window.innerWidth)
const ready = ref(false)
const onResize = () => (vw.value = window.innerWidth)

onMounted(async () => {
  window.addEventListener('resize', onResize)
  try {
    // 認証だけ待って即座にシェルを表示する（全データの取得は待たない）
    await auth.fetchMe()
    ready.value = true
    // 以降の学習データはバックグラウンドで取得し、各画面に届き次第反映する
    study.fetchAll().catch(() => {})
    vocab
      .fetchResources()
      .then((r) => (r ? vocab.fetchStats(r.id) : undefined))
      .catch(() => {})
    vocab.fetchProgress().catch(() => {})
  } catch {
    // 認証エラー時はインターセプタが /login へ
    ready.value = true
  }
})
onUnmounted(() => window.removeEventListener('resize', onResize))

const isMobile = computed(() => vw.value < 860)
const showTopNav = computed(() => !isMobile.value && ui.navStyle === 'トップナビ')
const showSide = computed(() => !isMobile.value && ui.navStyle !== 'トップナビ')
const isRail = computed(() => ui.navStyle === 'アイコンレール')
const navWidth = computed(() => (isRail.value ? '74px' : '232px'))
const showLabels = computed(() => !isRail.value)

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
    settings: 'settings',
  }
  return map[route.name as string] ?? 'home'
})

const navItems = computed(() =>
  NAV.map((n) => ({ ...n, icon: ICONS[n.key], active: activeKey.value === n.key })),
)

function go(routeName: string) {
  router.push({ name: routeName })
}
</script>

<template>
  <div v-if="!ready" class="loading">
    <div class="spinner"></div>
    <div style="font-size: 13px; color: var(--faint); letter-spacing: 0.04em">学習データを読み込み中…</div>
  </div>

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
      </aside>

      <main class="main">
        <!-- Mobile top bar -->
        <header v-if="isMobile" class="mobile-top">
          <div style="display: flex; align-items: center; gap: 9px">
            <div class="logo-sm dm" style="width: 28px; height: 28px; font-size: 14px">学</div>
            <div style="font-weight: 700; font-size: 16px">受験ナビ</div>
          </div>
          <div class="avatar" style="width: 32px; height: 32px">{{ userInitial }}</div>
        </header>

        <div class="scroll">
          <div class="content" :style="{ padding: isMobile ? '18px 16px 26px' : '28px 30px 40px' }">
            <router-view v-slot="{ Component }">
              <component :is="Component" :key="route.fullPath" class="fade-in" />
            </router-view>
          </div>
        </div>
      </main>
    </div>

    <!-- Mobile bottom tabs -->
    <nav v-if="isMobile" class="mobile-nav">
      <button v-for="n in navItems" :key="n.key" class="mnav-btn" :class="{ active: n.active }" @click="go(n.route)">
        <NavIcon :paths="n.icon" :size="22" />
        <span>{{ n.short }}</span>
      </button>
    </nav>
  </div>
</template>

<style scoped>
.loading {
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 14px;
  background: var(--bg);
}
.spinner {
  width: 34px;
  height: 34px;
  border: 3px solid #e3e6ea;
  border-top-color: #3b50cc;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
.shell {
  height: 100vh;
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
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 18px;
  height: 56px;
  background: #fff;
  border-bottom: 1px solid #e9ebee;
  flex-shrink: 0;
}
.scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}
.content {
  max-width: 1280px;
  margin: 0 auto;
}
.mobile-nav {
  display: flex;
  background: #fff;
  border-top: 1px solid #e9ebee;
  flex-shrink: 0;
  padding: 6px 4px 8px;
}
.mnav-btn {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  padding: 5px 2px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: #aeb4bd;
}
.mnav-btn.active {
  color: var(--ink);
}
.mnav-btn span {
  font-size: 10px;
  font-weight: 600;
}
</style>
