<script setup lang="ts">
import { onMounted } from 'vue'
import { useVocabularyStore } from '@/stores/vocabulary'

/** 単語帳（鉄壁 / LEAP basic など）の切替。選択は端末に記憶される */
const emit = defineEmits<{ change: [id: number] }>()
const vocab = useVocabularyStore()

onMounted(async () => {
  if (!vocab.resources.length) await vocab.fetchResources()
})

async function select(id: number) {
  if (vocab.resource?.id === id) return
  await vocab.selectResource(id)
  emit('change', id)
}
</script>

<template>
  <div v-if="vocab.resources.length > 1" class="switch" role="tablist" aria-label="単語帳">
    <button
      v-for="r in vocab.resources"
      :key="r.id"
      class="opt"
      :class="{ on: vocab.resource?.id === r.id }"
      role="tab"
      :aria-selected="vocab.resource?.id === r.id"
      @click="select(r.id)"
    >
      {{ r.name }}<span v-if="r.wordCount" class="cnt">{{ r.wordCount }}語</span>
    </button>
  </div>
</template>

<style scoped>
.switch {
  display: inline-flex;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}
.opt {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 13px;
  border: none;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.opt + .opt {
  border-left: 1px solid #e3e6ea;
}
.opt.on {
  background: #1c2024;
  color: #fff;
}
.cnt {
  font-size: 10px;
  font-weight: 500;
  opacity: 0.7;
}
</style>
