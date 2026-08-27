<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NavIcon from '@/components/NavIcon.vue'
import { ICONS, daysBetween, parseDate } from '@/lib/design'
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
    // 生徒の学習データ（目標・記録統計・課題）をバックグラウンドで取得
    study.fetchGoals().catch(() => {})
    study.fetchRecordStats().catch(() => {})
    study.fetchAssignments().catch(() => {})
  } catch {
    // 認証エラー時はインターセプタが /login へ
    ready.value = true
  }
})

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
  { key: 'home', route: 'tutor-home', label: 'トップページ', icon: ICONS.home },
  { key: 'assignment', route: 'tutor-assignments', label: '課題設定', icon: ICONS.record },
  { key: 'goal', route: 'tutor-goals', label: '学習計画', icon: ICONS.goal },
]

const activeKey = computed(() => {
  const map: Record<string, string> = {
    'tutor-home': 'home',
    'tutor-assignments': 'assignment',
    'tutor-goals': 'goal',
  }
  return map[route.name as string] ?? 'home'
})

function go(routeName: string) {
  router.push({ name: routeName })
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="!ready" class="loading">
    <div class="spinner"></div>
    <div style="font-size: 13px; color: var(--faint); letter-spacing: 0.04em">読み込み中…</div>
  </div>

  <div v-else class="shell">
    <header class="topbar">
      <div class="brand-mini">
        <div class="logo-sm dm">師</div>
        <div style="line-height: 1.25">
          <div style="font-weight: 700; font-size: 15.5px; white-space: nowrap">受験ナビ <span class="tutor-tag">講師用</span></div>
        </div>
      </div>
      <nav class="topnav">
        <button
          v-for="n in NAV_TUTOR"
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

    <main class="main">
      <div class="scroll">
        <div class="content">
          <router-view v-slot="{ Component }">
            <component :is="Component" :key="route.fullPath" class="fade-in" />
          </router-view>
        </div>
      </div>
    </main>
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
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}
.content {
  max-width: 1100px;
  margin: 0 auto;
  padding: 24px 22px 40px;
}
</style>
