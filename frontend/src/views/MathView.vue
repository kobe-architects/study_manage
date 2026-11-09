<script setup lang="ts">
/**
 * 数学学習。合格るシリーズ（合格る確率＋場合の数／合格る計算）の問題を学習カードとして出題する。
 * 上のタブで教材を切り替える（URL の ?tab= と端末に記憶）。中身は学習カード（CardDeckView）。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cardsApi } from '@/api/cards'
import CardDeckView from '@/views/CardDeckView.vue'
import type { CardDeck } from '@/types'

const KEY = 'sm_math_tab'
const route = useRoute()
const router = useRouter()
const decks = ref<CardDeck[]>([])
const loaded = ref(false)

onMounted(async () => {
  try {
    decks.value = await cardsApi.decks('math')
  } catch {
    decks.value = []
  } finally {
    loaded.value = true
  }
})

const tab = computed<string>(() => {
  const q = route.query.tab
  if (typeof q === 'string' && decks.value.some((d) => d.key === q)) return q
  const saved = localStorage.getItem(KEY)
  if (saved && decks.value.some((d) => d.key === saved)) return saved
  return decks.value[0]?.key ?? ''
})
watch(tab, (t) => {
  if (!t) return
  try {
    localStorage.setItem(KEY, t)
  } catch {
    // ignore
  }
})
function select(key: string) {
  if (key === tab.value) return
  router.replace({ name: 'math', query: { tab: key } })
}
</script>

<template>
  <div>
    <div v-if="decks.length > 1" v-seg class="etabs" role="tablist" aria-label="数学学習">
      <button v-for="d in decks" :key="d.key" class="etab" :class="{ on: tab === d.key }" role="tab" :aria-selected="tab === d.key" @click="select(d.key)">
        {{ d.shortName }}<span v-if="d.due" class="due-n">{{ d.due }}</span>
      </button>
    </div>
    <CardDeckView v-if="tab" :deck-key="tab" subject="math" />
    <div v-else-if="loaded" class="card" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">数学学習の教材はまだ登録されていません</div>
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
.due-n {
  margin-left: 6px;
  padding: 1px 7px;
  border-radius: 99px;
  background: #fff4e5;
  color: #b7681a;
  font-size: 10.5px;
}
</style>
