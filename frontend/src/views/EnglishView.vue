<script setup lang="ts">
/**
 * 英語学習。スクランブル（英文法・語法）／英単語／鉄緑会 英文法 を 1 つの画面にまとめ、上のタブで切り替える。
 * タブは URL の ?tab= と端末（localStorage）に記憶する。各タブの中身は従来の画面（GrammarView・QuizView）と学習カード（CardDeckView）。
 */
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CardDeckView from '@/views/CardDeckView.vue'
import GrammarView from '@/views/GrammarView.vue'
import QuizView from '@/views/QuizView.vue'

type Tab = 'scramble' | 'vocab' | 'tetsuryoku'
const TABS: { key: Tab; label: string; short: string }[] = [
  { key: 'scramble', label: 'スクランブル', short: 'スクランブル' },
  { key: 'vocab', label: '英単語', short: '英単語' },
  { key: 'tetsuryoku', label: '鉄緑会 英文法', short: '鉄緑会' },
]
const KEY = 'sm_english_tab'
const route = useRoute()
const router = useRouter()

function isTab(v: unknown): v is Tab {
  return typeof v === 'string' && TABS.some((t) => t.key === v)
}
const tab = computed<Tab>(() => {
  const q = route.query.tab
  if (isTab(q)) return q
  const saved = localStorage.getItem(KEY)
  return isTab(saved) ? saved : 'scramble'
})
watch(
  tab,
  (t) => {
    try {
      localStorage.setItem(KEY, t)
    } catch {
      // ignore
    }
  },
  { immediate: true },
)
function select(t: Tab) {
  if (t === tab.value) return
  router.replace({ name: 'english', query: { tab: t } })
}
</script>

<template>
  <div>
    <div v-seg class="etabs" role="tablist" aria-label="英語学習">
      <button v-for="t in TABS" :key="t.key" class="etab" :class="{ on: tab === t.key }" role="tab" :aria-selected="tab === t.key" @click="select(t.key)">{{ t.label }}</button>
    </div>
    <GrammarView v-if="tab === 'scramble'" />
    <QuizView v-else-if="tab === 'vocab'" />
    <CardDeckView v-else deck-key="tetsuryoku_grammar" subject="english" />
  </div>
</template>

<style scoped>
.etabs {
  display: flex;
  margin: 0 0 16px;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}
.etab {
  flex: 1;
  min-height: 42px;
  padding: 8px 10px;
  border: none;
  background: transparent;
  color: var(--mut);
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.etab.on {
  background: #1c2024;
  color: #fff;
}
</style>
