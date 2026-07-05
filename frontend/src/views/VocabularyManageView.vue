<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import client from '@/api/client'
import AuthImage from '@/components/AuthImage.vue'
import { speak } from '@/lib/design'
import { useVocabularyStore } from '@/stores/vocabulary'
import { useUiStore } from '@/stores/ui'
import type { Vocabulary, VocabularyLabel, VocabularyProficiency } from '@/types'

const router = useRouter()
const vocab = useVocabularyStore()
const ui = useUiStore()

const resourceId = computed(() => vocab.resource?.id ?? 0)
const sections = computed(() => vocab.resource?.sections ?? [])

const q = ref('')
const fSection = ref<'all' | number>('all')
const fLabel = ref<'all' | VocabularyLabel>('all')
const fImportance = ref<'all' | number>('all')
const fProf = ref<'all' | VocabularyProficiency>('all')
const expanded = reactive<Record<number, boolean>>({})

onMounted(async () => {
  if (!vocab.resource) await vocab.fetchResources()
  if (resourceId.value) await vocab.fetchByResource(resourceId.value)
})

const labelMap: Record<VocabularyLabel, { t: string; bg: string; fg: string }> = {
  easy: { t: '易', bg: '#e9f5ee', fg: '#2e9d62' },
  normal: { t: '普', bg: '#eef1f6', fg: '#5b6b8c' },
  hard: { t: '難', bg: '#fdeef0', fg: '#cf5563' },
}
const profMap = { high: { t: '高', c: '#2e9d62' }, medium: { t: '中', c: '#d98a2b' }, low: { t: '低', c: '#cf5563' } }

// 学習（正答数/回答数）チップ。正答率で色を変える
function learnInfo(w: Vocabulary): { text: string; color: string } {
  const st = w.learningStat
  const total = st ? st.correctCount + st.incorrectCount : 0
  if (!st || total === 0) return { text: '–', color: '#cdd2d9' }
  const pct = Math.round((st.correctCount / total) * 100)
  const color = pct >= 80 ? '#2e9d62' : pct >= 50 ? '#d98a2b' : '#cf5563'
  return { text: `${st.correctCount}/${total}`, color }
}

const filtered = computed(() => {
  const term = q.value.trim().toLowerCase()
  return vocab.items.filter((w) => {
    if (fSection.value !== 'all' && w.sectionId !== fSection.value) return false
    if (fLabel.value !== 'all' && w.label !== fLabel.value) return false
    if (fImportance.value !== 'all' && w.importance !== fImportance.value) return false
    if (fProf.value !== 'all' && w.proficiency !== fProf.value) return false
    if (term) {
      const hay = `${w.word} ${w.meaning} ${w.meaningSupplement ?? ''} ${w.exampleSentence ?? ''} ${w.exampleTranslation ?? ''}`.toLowerCase()
      if (!hay.includes(term)) return false
    }
    return true
  })
})

const sectionName = (id: number) => sections.value.find((s) => s.id === id)?.name ?? ''

// ---- ページネーション ----
const page = ref(1)
const perPage = 20
const totalCount = computed(() => filtered.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / perPage)))
const curPage = computed(() => Math.min(page.value, totalPages.value))
const paged = computed(() => {
  const start = (curPage.value - 1) * perPage
  return filtered.value.slice(start, start + perPage)
})
const rangeText = computed(() => {
  if (!totalCount.value) return '0件'
  const start = (curPage.value - 1) * perPage
  return `${start + 1}–${Math.min(start + perPage, totalCount.value)} / 全${totalCount.value}件`
})
function resetPage() {
  page.value = 1
}

// ---- form dialog ----
const dialog = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({
  sectionId: 0,
  word: '',
  meaning: '',
  meaningSupplement: '',
  partOfSpeech: '',
  importance: 1,
  label: 'normal' as VocabularyLabel,
  proficiency: 'low' as VocabularyProficiency,
  memo: '',
  exampleSentence: '',
  exampleTranslation: '',
  exampleExplanation: '',
})
const imageUrl = ref<string | null>(null)
const pendingFile = ref<File | null>(null) // 新規単語用：保存時にアップロードする画像
const fileInput = ref<HTMLInputElement | null>(null)
const importInput = ref<HTMLInputElement | null>(null)

const DRAFT_KEY = 'vocab_new_draft'

function blankForm() {
  return {
    sectionId: sections.value[0]?.id ?? 0,
    word: '',
    meaning: '',
    meaningSupplement: '',
    partOfSpeech: '',
    importance: 1,
    label: 'normal' as VocabularyLabel,
    proficiency: 'low' as VocabularyProficiency,
    memo: '',
    exampleSentence: '',
    exampleTranslation: '',
    exampleExplanation: '',
  }
}

function openNew() {
  editingId.value = null
  Object.assign(form, blankForm())
  // 前回入力途中の内容を自動復元
  const draft = localStorage.getItem(DRAFT_KEY)
  if (draft) {
    try {
      Object.assign(form, JSON.parse(draft))
    } catch {
      // ignore broken draft
    }
  }
  if (!form.sectionId) form.sectionId = sections.value[0]?.id ?? 0
  imageUrl.value = null
  pendingFile.value = null
  dialog.value = true
}

// 新規入力中はフォーム内容を下書きとして保存（復元用）
watch(
  form,
  () => {
    if (dialog.value && editingId.value === null) {
      localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...form }))
    }
  },
  { deep: true },
)
function openEdit(w: Vocabulary) {
  editingId.value = w.id
  Object.assign(form, {
    sectionId: w.sectionId,
    word: w.word,
    meaning: w.meaning,
    meaningSupplement: w.meaningSupplement ?? '',
    partOfSpeech: w.partOfSpeech ?? '',
    importance: w.importance,
    label: w.label,
    proficiency: w.proficiency ?? 'low',
    memo: w.memo ?? '',
    exampleSentence: w.exampleSentence ?? '',
    exampleTranslation: w.exampleTranslation ?? '',
    exampleExplanation: w.exampleExplanation ?? '',
  })
  imageUrl.value = w.imageUrl
  pendingFile.value = null
  dialog.value = true
}

async function save() {
  if (!form.word.trim() || !form.meaning.trim()) {
    ui.notify('語と意味は必須です')
    return
  }
  const payload = {
    word: form.word,
    meaning: form.meaning,
    meaningSupplement: form.meaningSupplement || null,
    partOfSpeech: form.partOfSpeech || null,
    importance: form.importance,
    label: form.label,
    proficiency: form.proficiency,
    memo: form.memo || null,
    exampleSentence: form.exampleSentence || null,
    exampleTranslation: form.exampleTranslation || null,
    exampleExplanation: form.exampleExplanation || null,
  }
  try {
    if (editingId.value) {
      await vocab.update(editingId.value, payload)
      ui.notify('更新しました')
    } else {
      const created = await vocab.create(form.sectionId, payload)
      editingId.value = created.id
      localStorage.removeItem(DRAFT_KEY) // 保存できたので下書きを破棄
      // 新規単語に画像が選択されていれば続けてアップロード
      if (pendingFile.value) {
        await vocab.uploadImage(created.id, pendingFile.value)
        pendingFile.value = null
      }
      await vocab.fetchByResource(resourceId.value)
      ui.notify('単語を追加しました')
    }
    dialog.value = false
  } catch {
    ui.notify('保存に失敗しました')
  }
}

async function remove(w: Vocabulary) {
  if (!window.confirm(`「${w.word}」を削除しますか？`)) return
  await vocab.remove(w.id)
  ui.notify('削除しました')
}

function pickImage() {
  fileInput.value?.click()
}
async function onImageSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (editingId.value) {
    // 既存単語：その場でアップロード
    const updated = await vocab.uploadImage(editingId.value, file)
    imageUrl.value = updated.imageUrl
    ui.notify('画像を保存しました')
  } else {
    // 新規単語：保存時にアップロードするため一旦保持＋プレビュー
    pendingFile.value = file
    imageUrl.value = URL.createObjectURL(file)
  }
  input.value = '' // 同じファイルを選び直せるように
}
async function removeImage() {
  if (editingId.value) {
    await vocab.deleteImage(editingId.value)
    ui.notify('画像を削除しました')
  } else {
    pendingFile.value = null
  }
  imageUrl.value = null
}

// ---- Excel 入出力 ----
async function download(url: string, filename: string) {
  const res = await client.get(url, { responseType: 'blob' })
  const blobUrl = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = blobUrl
  a.download = filename
  a.click()
  URL.revokeObjectURL(blobUrl)
}
function exportExcel() {
  download(`/study-resources/${resourceId.value}/vocabularies/export`, 'vocabulary_export.xlsx')
}
function templateExcel() {
  download('/vocabularies/template', 'vocabulary_template.xlsx')
}
const importing = ref(false)

function pickImport() {
  importInput.value?.click()
}
async function onImportSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  input.value = '' // 同じファイルを選び直せるように
  if (!resourceId.value) {
    ui.notify('教材が読み込めていません。時間をおいて再度お試しください')
    return
  }
  importing.value = true
  try {
    const res = await vocab.importFile(resourceId.value, file)
    await Promise.all([vocab.fetchByResource(resourceId.value), vocab.fetchResources()])
    ui.notify(`取込 ${res.imported} 件 / スキップ ${res.skipped} 件`)
  } catch {
    ui.notify('インポートに失敗しました。ファイル形式をご確認ください')
  } finally {
    importing.value = false
  }
}

async function deleteAll() {
  if (!window.confirm('教材内の全単語とセクションを削除します。よろしいですか？')) return
  await client.delete(`/study-resources/${resourceId.value}/vocabularies`)
  // セクションも削除されるため、単語一覧と教材（セクション一覧）の両方を再取得する
  await Promise.all([vocab.fetchByResource(resourceId.value), vocab.fetchResources()])
  ui.notify('全単語を削除しました')
}
</script>

<template>
  <div>
    <div class="row-between" style="margin-bottom: 14px">
      <div style="font-size: 16px; font-weight: 700">単語帳管理 <span style="font-size: 12px; color: var(--faint); font-weight: 400">{{ vocab.resource?.name }}</span></div>
      <button class="link-btn" @click="router.push({ name: 'quiz' })">クイズへ</button>
    </div>

    <!-- filters -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px">
      <div style="position: relative; flex: 1; min-width: 180px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%)"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
        <input v-model="q" placeholder="語・意味・例文で検索…" class="search" @input="resetPage" />
      </div>
      <select v-model="fSection" class="filter" @change="resetPage">
        <option value="all">全セクション</option>
        <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <select v-model="fLabel" class="filter" @change="resetPage"><option value="all">全ラベル</option><option value="easy">易</option><option value="normal">普</option><option value="hard">難</option></select>
      <select v-model="fImportance" class="filter" @change="resetPage"><option value="all">全重要度</option><option :value="0">無印</option><option :value="1">★</option><option :value="2">★★</option></select>
      <select v-model="fProf" class="filter" @change="resetPage"><option value="all">全習熟</option><option value="high">高</option><option value="medium">中</option><option value="low">低</option></select>
    </div>

    <div style="display: flex; gap: 9px; flex-wrap: wrap; margin-bottom: 16px">
      <button class="btn-dark" @click="openNew"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>新規単語</button>
      <button class="btn-out" @click="pickImport">Excelインポート</button>
      <button class="btn-out" @click="exportExcel">Excelエクスポート</button>
      <button class="btn-out" @click="templateExcel">Excelフォーマット</button>
      <button class="btn-out" style="color: #cf5563; margin-left: auto" @click="deleteAll">全削除</button>
      <input ref="importInput" type="file" accept=".xlsx,.xls,.csv,.txt" style="display: none" @change="onImportSelected" />
    </div>

    <div class="card" style="overflow: hidden">
      <div style="overflow-x: auto">
        <table class="tbl">
          <thead>
            <tr><th style="width: 30px"></th><th style="width: 44px">No</th><th style="width: 150px">セクション</th><th>単語</th><th>意味</th><th style="width: 110px; white-space: nowrap">品詞</th><th style="width: 70px">重要度</th><th style="width: 60px">ラベル</th><th style="width: 60px">習熟</th><th style="width: 64px">学習</th><th style="width: 80px; text-align: right">操作</th></tr>
          </thead>
          <tbody>
            <template v-for="(w, i) in paged" :key="w.id">
              <tr class="v-row">
                <td class="c-toggle" data-label="詳細"><button class="bare" style="color: #9aa1ab" @click="expanded[w.id] = !expanded[w.id]"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" :style="{ transform: expanded[w.id] ? 'rotate(90deg)' : 'none' }"><path d="M9 6l6 6-6 6" /></svg></button></td>
                <td class="c-no" data-label="No" style="color: #bcc2cb">{{ (curPage - 1) * perPage + i + 1 }}</td>
                <td data-label="セクション" style="color: #9aa1ab; font-size: 12px">{{ sectionName(w.sectionId) }}</td>
                <td class="c-word" data-label="単語">
                  <span style="display: inline-flex; align-items: center; gap: 7px">
                    <AuthImage v-if="w.imageUrl" :src="w.imageUrl" style="width: 26px; height: 26px; object-fit: cover; border-radius: 5px; border: 1px solid #e3e6ea" />
                    <button class="bare dm" style="font-weight: 700; font-size: 13.5px" @click="speak(w.word)">{{ w.word }}</button>
                  </span>
                </td>
                <td data-label="意味" style="color: #4b5563">
                  <div>{{ w.meaning }}</div>
                  <div v-if="w.meaningSupplement" style="color: #9aa1ab; font-size: 11.5px; margin-top: 2px">{{ w.meaningSupplement }}</div>
                </td>
                <td data-label="品詞" style="color: #9aa1ab; white-space: nowrap">{{ w.partOfSpeech }}</td>
                <td data-label="重要度" style="color: #e0a93b">{{ '★'.repeat(w.importance) || '–' }}</td>
                <td data-label="ラベル"><span :style="{ fontSize: '11px', fontWeight: 600, padding: '2px 7px', borderRadius: '99px', background: labelMap[w.label].bg, color: labelMap[w.label].fg }">{{ labelMap[w.label].t }}</span></td>
                <td data-label="習熟" :style="{ fontWeight: 700, color: w.proficiency ? profMap[w.proficiency].c : '#cdd2d9' }">{{ w.proficiency ? profMap[w.proficiency].t : '—' }}</td>
                <td data-label="学習"><span :style="{ fontSize: '11.5px', fontWeight: 700, color: learnInfo(w).color }">{{ learnInfo(w).text }}</span></td>
                <td class="c-actions" style="text-align: right; white-space: nowrap">
                  <button class="icon-btn" title="編集" @click="openEdit(w)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg></button>
                  <button class="icon-btn danger" title="削除" @click="remove(w)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg></button>
                </td>
              </tr>
              <tr v-if="expanded[w.id]" class="v-detail">
                <td class="c-detail-pad"></td>
                <td class="c-detail" colspan="10" style="background: #f8f9fb; color: #4b5563; font-size: 12.5px">
                  <div v-if="w.exampleSentence"><strong>例文:</strong> {{ w.exampleSentence }}</div>
                  <div v-if="w.exampleTranslation" style="color: #9aa1ab">{{ w.exampleTranslation }}</div>
                  <div v-if="w.memo"><strong>メモ:</strong> {{ w.memo }}</div>
                  <div v-if="!w.exampleSentence && !w.memo" style="color: #9aa1ab">追加情報はありません</div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div v-if="!totalCount" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">該当する単語がありません</div>
      <div class="pager">
        <span>{{ rangeText }}</span>
        <div style="display: flex; align-items: center; gap: 6px">
          <button class="pg-btn" @click="page = Math.max(1, curPage - 1)">前へ</button>
          <span style="padding: 0 6px">{{ curPage }} / {{ totalPages }}</span>
          <button class="pg-btn" @click="page = Math.min(totalPages, curPage + 1)">次へ</button>
        </div>
      </div>
    </div>

    <!-- インポート中モーダル -->
    <div v-if="importing" class="overlay" style="z-index: 60">
      <div class="import-modal">
        <span class="spinner"></span>
        <div style="font-size: 14px; font-weight: 700">インポート中…</div>
        <div style="font-size: 12px; color: var(--faint); margin-top: 4px">件数が多い場合は時間がかかります</div>
      </div>
    </div>

    <!-- form dialog -->
    <div v-if="dialog" class="overlay" @click="dialog = false">
      <div class="modal" @click.stop>
        <div style="font-size: 16px; font-weight: 700; margin-bottom: 16px">{{ editingId ? '単語を編集' : '単語を追加' }}</div>
        <div class="grid2">
          <label class="fld" style="grid-column: span 2" v-if="!editingId"><span>セクション</span>
            <select v-model.number="form.sectionId"><option v-for="s in sections" :key="s.id" :value="s.id">{{ s.name }}</option></select>
          </label>
          <label class="fld"><span>語 *</span><input v-model="form.word" /></label>
          <label class="fld"><span>意味 *</span><input v-model="form.meaning" /></label>
          <label class="fld" style="grid-column: span 2"><span>意味の補足</span><input v-model="form.meaningSupplement" placeholder="意味の補足説明" /></label>
          <label class="fld"><span>品詞</span><input v-model="form.partOfSpeech" placeholder="名/動/形/副" /></label>
          <label class="fld"><span>重要度</span><select v-model.number="form.importance"><option :value="0">無印</option><option :value="1">★</option><option :value="2">★★</option></select></label>
          <label class="fld"><span>ラベル</span><select v-model="form.label"><option value="easy">易</option><option value="normal">普</option><option value="hard">難</option></select></label>
          <label class="fld"><span>習熟度</span><select v-model="form.proficiency"><option value="high">高</option><option value="medium">中</option><option value="low">低</option></select></label>
          <label class="fld" style="grid-column: span 2"><span>例文</span><input v-model="form.exampleSentence" /></label>
          <label class="fld" style="grid-column: span 2"><span>例文和訳</span><input v-model="form.exampleTranslation" /></label>
          <label class="fld" style="grid-column: span 2"><span>例文説明</span><input v-model="form.exampleExplanation" /></label>
          <label class="fld" style="grid-column: span 2"><span>メモ</span><textarea v-model="form.memo" rows="2"></textarea></label>
          <div class="fld" style="grid-column: span 2">
            <span>画像</span>
            <div style="display: flex; align-items: center; gap: 12px">
              <img v-if="imageUrl && imageUrl.startsWith('blob:')" :src="imageUrl" style="width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid #e3e6ea" />
              <AuthImage v-else-if="imageUrl" :src="imageUrl" style="width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid #e3e6ea" />
              <button class="btn-out" type="button" @click="pickImage">{{ imageUrl ? '差し替え' : 'アップロード' }}</button>
              <button v-if="imageUrl" class="btn-out" type="button" style="color: #cf5563" @click="removeImage">削除</button>
              <span v-if="!editingId" style="font-size: 11px; color: #9aa1ab">保存時に一緒に登録されます</span>
              <input ref="fileInput" type="file" accept="image/*" style="display: none" @change="onImageSelected" />
            </div>
          </div>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
          <button class="btn-ghost" @click="dialog = false">キャンセル</button>
          <button class="btn-dark" @click="save">保存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.link-btn {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12px;
  cursor: pointer;
  font-weight: 600;
}
.search {
  width: 100%;
  padding: 9px 12px 9px 36px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  outline: none;
}
.filter {
  padding: 9px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  background: #fff;
  cursor: pointer;
  outline: none;
}
.btn-dark {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 15px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-out {
  padding: 9px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-ghost {
  padding: 9px 18px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  min-width: 980px;
}
.tbl thead tr {
  background: #f8f9fb;
  color: var(--faint);
  font-size: 11.5px;
  text-align: left;
}
.tbl th {
  padding: 11px 10px;
  font-weight: 600;
}
.tbl td {
  padding: 9px 10px;
  border-top: 1px solid #f0f1f3;
}
.icon-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: #9aa1ab;
  padding: 5px;
}
.icon-btn.danger {
  color: #d99;
}
.pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 16px;
  border-top: 1px solid #f0f1f3;
  font-size: 12.5px;
  color: var(--mut);
  flex-wrap: wrap;
  gap: 8px;
}
.pg-btn {
  padding: 6px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
  font-size: 12.5px;
  color: #4b5563;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 20px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 24px;
  width: 100%;
  max-width: 560px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.import-modal {
  background: #fff;
  border-radius: 16px;
  padding: 28px 36px;
  text-align: center;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.spinner {
  display: block;
  width: 30px;
  height: 30px;
  margin: 0 auto 14px;
  border: 3px solid #e3e6ea;
  border-top-color: #1c2024;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
.grid2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input,
.fld select,
.fld textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
  font-family: inherit;
}

/* ===== スマホ: テーブルをカード表示に切り替え ===== */
@media (max-width: 720px) {
  .tbl {
    min-width: 0;
  }
  .tbl thead {
    display: none;
  }
  .tbl,
  .tbl tbody {
    display: block;
    width: 100%;
  }
  .tbl tbody tr.v-row {
    display: flex;
    flex-direction: column;
    padding: 12px 14px;
    border-top: 6px solid #f3f4f6;
  }
  .tbl tbody tr.v-row td {
    border-top: none;
    padding: 3px 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    text-align: right;
    min-width: 0;
  }
  .tbl tbody tr.v-row td::before {
    content: attr(data-label);
    color: var(--faint);
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
    margin-right: auto;
    text-align: left;
  }
  /* 単語は見出しとして目立たせ、カードの先頭に配置 */
  .tbl tbody tr.v-row td.c-word {
    order: -1;
    justify-content: flex-start;
    text-align: left;
    padding-bottom: 6px;
  }
  .tbl tbody tr.v-row td.c-word::before {
    display: none;
  }
  .tbl tbody tr.v-row td.c-word .dm {
    font-size: 16px !important;
  }
  /* 操作ボタンはカード末尾に全幅で */
  .tbl tbody tr.v-row td.c-actions {
    order: 10;
    justify-content: flex-end;
    gap: 4px;
    margin-top: 6px;
    border-top: 1px solid #eceef1;
    padding-top: 8px;
  }
  .tbl tbody tr.v-row td.c-actions .icon-btn {
    padding: 8px;
  }
  /* 展開した詳細行 */
  .tbl tbody tr.v-detail {
    display: block;
  }
  .tbl tbody tr.v-detail td.c-detail-pad {
    display: none;
  }
  .tbl tbody tr.v-detail td.c-detail {
    display: block;
    padding: 12px 14px;
  }
}
</style>
