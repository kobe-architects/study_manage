<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import { quizApi } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { BookPdf, PdfPageMap } from '@/types'

/**
 * 教材（個別学習データ）への PDF 紐づけ管理（生徒・講師共用）。
 * 大容量 PDF は 4MB ずつ分割アップロードし、確定時にサーバーでページ数を数える。
 */
const props = defineProps<{ bookId: number; bookTitle: string }>()
const emit = defineEmits<{ close: []; changed: [] }>()

const ui = useUiStore()
const pdfs = ref<BookPdf[]>([])
const loading = ref(true)

const form = reactive<{ file: File | null; title: string; pageMap: PdfPageMap; pageOffset: number; uploading: boolean; progress: number }>({
  file: null,
  title: '',
  pageMap: 'seq',
  pageOffset: 0,
  uploading: false,
  progress: 0,
})

const edit = reactive<{ id: number | null; title: string; pageMap: PdfPageMap; pageOffset: number; saving: boolean }>({
  id: null,
  title: '',
  pageMap: 'seq',
  pageOffset: 0,
  saving: false,
})

async function load() {
  loading.value = true
  try {
    pdfs.value = await quizApi.listPdfs(props.bookId)
  } catch {
    ui.notify('PDF 一覧の取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)

function onFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  form.file = f
  if (f && !form.title.trim()) form.title = f.name.replace(/\.pdf$/i, '')
}

async function upload() {
  if (!form.file) {
    ui.notify('PDF ファイルを選択してください')
    return
  }
  if (!form.title.trim()) {
    ui.notify('表示名を入力してください')
    return
  }
  form.uploading = true
  form.progress = 0
  try {
    const pdf = await quizApi.uploadPdf(
      props.bookId,
      form.file,
      { title: form.title.trim(), pageMap: form.pageMap, pageOffset: Number(form.pageOffset) || 0 },
      (r) => (form.progress = r),
    )
    pdfs.value.push(pdf)
    form.file = null
    form.title = ''
    ui.notify(`PDF を紐づけました（${pdf.pageCount}ページ）`)
    emit('changed')
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || 'アップロードに失敗しました')
  } finally {
    form.uploading = false
  }
}

function startEdit(p: BookPdf) {
  edit.id = p.id
  edit.title = p.title
  edit.pageMap = p.pageMap
  edit.pageOffset = p.pageOffset
}
async function saveEdit() {
  if (edit.id === null) return
  edit.saving = true
  try {
    const updated = await quizApi.updatePdf(edit.id, { title: edit.title.trim(), pageMap: edit.pageMap, pageOffset: Number(edit.pageOffset) || 0 })
    const i = pdfs.value.findIndex((x) => x.id === edit.id)
    if (i >= 0) pdfs.value[i] = updated
    edit.id = null
    ui.notify('更新しました')
    emit('changed')
  } catch {
    ui.notify('更新に失敗しました')
  } finally {
    edit.saving = false
  }
}
async function remove(p: BookPdf) {
  if (!confirm(`PDF「${p.title}」の紐づけを削除しますか？\n（この PDF から出題済みの小テストは残りますが、新規出題には使えなくなります）`)) return
  try {
    await quizApi.deletePdf(p.id)
    pdfs.value = pdfs.value.filter((x) => x.id !== p.id)
    ui.notify('削除しました')
    emit('changed')
  } catch {
    ui.notify('削除に失敗しました')
  }
}

function sizeLabel(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)}MB`
  return `${Math.round(bytes / 1024)}KB`
}
function mapLabel(p: BookPdf): string {
  if (p.pageMap === 'seq') return p.pageOffset === 0 ? '番号 = ページ' : `番号 ${p.pageOffset >= 0 ? '+' : ''}${p.pageOffset} = ページ`
  return '手動でページ選択'
}
</script>

<template>
  <div class="overlay" @click="emit('close')">
    <div class="modal" @click.stop>
      <div class="head">
        <div>
          <div style="font-size: 15px; font-weight: 700">PDF の紐づけ</div>
          <div style="font-size: 12px; color: var(--mut); margin-top: 2px">{{ bookTitle }}</div>
        </div>
        <button class="x" @click="emit('close')">×</button>
      </div>

      <div v-if="loading" style="font-size: 12.5px; color: var(--faint); padding: 10px 0">読み込み中…</div>
      <div v-else-if="!pdfs.length" class="empty">まだ PDF が紐づいていません。下のフォームからアップロードしてください。</div>
      <div v-else class="list">
        <div v-for="p in pdfs" :key="p.id" class="row">
          <template v-if="edit.id === p.id">
            <div class="edit-grid">
              <label class="fld"><span>表示名</span><input v-model="edit.title" /></label>
              <label class="fld"><span>ページ対応</span>
                <select v-model="edit.pageMap">
                  <option value="seq">番号 = ページ（オフセット付き）</option>
                  <option value="none">手動でページ選択</option>
                </select>
              </label>
              <label v-if="edit.pageMap === 'seq'" class="fld"><span>オフセット</span><input v-model.number="edit.pageOffset" type="number" /></label>
              <div style="display: flex; gap: 6px; justify-content: flex-end; align-self: end">
                <button class="mini" @click="edit.id = null">キャンセル</button>
                <button class="mini primary" :disabled="edit.saving" @click="saveEdit">保存</button>
              </div>
            </div>
          </template>
          <template v-else>
            <div class="pdf-ic">PDF</div>
            <div style="flex: 1; min-width: 0">
              <div style="font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ p.title }}</div>
              <div style="font-size: 11px; color: var(--faint); margin-top: 2px">
                {{ p.pageCount }}ページ・{{ sizeLabel(p.sizeBytes) }}・<span :style="{ color: p.pageMap === 'seq' ? '#2f7a4f' : 'var(--mut)' }">{{ mapLabel(p) }}</span>
              </div>
            </div>
            <button class="mini" @click="startEdit(p)">編集</button>
            <button class="mini danger" @click="remove(p)">削除</button>
          </template>
        </div>
      </div>

      <div class="add">
        <div style="font-size: 12.5px; font-weight: 700; margin-bottom: 8px">PDF を追加</div>
        <label class="file-pick" :class="{ has: form.file }">
          <input type="file" accept="application/pdf,.pdf" @change="onFile" />
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4" /><path d="M7 9l5-5 5 5" /><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" /></svg>
          <span v-if="form.file">{{ form.file.name }}（{{ sizeLabel(form.file.size) }}）</span>
          <span v-else>PDF ファイルを選択</span>
        </label>
        <div class="edit-grid" style="margin-top: 10px">
          <label class="fld"><span>表示名</span><input v-model="form.title" placeholder="例: 例題のみ" /></label>
          <label class="fld"><span style="display: inline-flex; align-items: center; gap: 5px">ページ対応
            <HelpTip
              align="right"
              text="「番号 = ページ」は、一覧データの番号（例題 No. など）＋オフセット が PDF のページ番号と一致する場合に選びます。&#10;一致しない PDF は「手動でページ選択」にすると、出題時にサムネイルからページを選べます。"
            /></span>
            <select v-model="form.pageMap">
              <option value="seq">番号 = ページ（オフセット付き）</option>
              <option value="none">手動でページ選択</option>
            </select>
          </label>
          <label v-if="form.pageMap === 'seq'" class="fld"><span>オフセット</span><input v-model.number="form.pageOffset" type="number" /></label>
        </div>
        <div v-if="form.uploading" class="prog"><span :style="{ width: Math.round(form.progress * 100) + '%' }"></span></div>
        <div style="display: flex; justify-content: flex-end; margin-top: 10px">
          <button class="btn-dark" :disabled="form.uploading || !form.file" @click="upload">
            {{ form.uploading ? `アップロード中… ${Math.round(form.progress * 100)}%` : 'アップロードして紐づける' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 20px 22px;
  width: 100%;
  max-width: 560px;
  max-height: 92vh;
  overflow-y: auto;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}
.x {
  border: none;
  background: transparent;
  font-size: 22px;
  color: #9aa1ab;
  cursor: pointer;
  line-height: 1;
}
.empty {
  font-size: 12.5px;
  color: var(--faint);
  padding: 14px;
  border: 1px dashed #d8dce1;
  border-radius: 10px;
  text-align: center;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid #eceef0;
  border-radius: 10px;
}
.pdf-ic {
  width: 34px;
  height: 40px;
  border-radius: 6px;
  background: #fbe9ea;
  color: #c0444f;
  font-size: 10px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.edit-grid {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px 10px;
}
.edit-grid .fld:first-child {
  grid-column: 1 / -1;
}
.fld span {
  font-size: 11px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 4px;
}
.fld input,
.fld select {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
  outline: none;
}
.mini {
  flex-shrink: 0;
  padding: 6px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.mini.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.mini.danger {
  color: #c0444f;
  border-color: #f0b8be;
}
.add {
  margin-top: 16px;
  padding: 14px;
  border: 1px dashed #d8dce1;
  border-radius: 12px;
  background: #fafbfc;
}
.file-pick {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
  color: var(--mut);
  cursor: pointer;
}
.file-pick.has {
  color: var(--ink);
  border-color: #c1c8f0;
}
.file-pick input {
  display: none;
}
.hint {
  font-size: 11px;
  color: var(--faint);
  line-height: 1.6;
  margin-top: 8px;
}
.prog {
  height: 6px;
  border-radius: 99px;
  background: #e8ebf5;
  overflow: hidden;
  margin-top: 10px;
}
.prog span {
  display: block;
  height: 100%;
  background: #3b50cc;
  transition: width 0.2s;
}
.btn-dark {
  padding: 9px 16px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
</style>
