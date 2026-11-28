<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import HelpTip from '@/components/HelpTip.vue'
import { quizApi, rateColor } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { QuizDetail } from '@/types'

/**
 * 回答用紙の撮影・提出（生徒）。ブラウザから直接カメラを起動し、出題ページ数分を順番に撮影して提出する。
 * カメラが使えない環境ではファイル選択（スマホでは端末のカメラアプリ）にフォールバックする。
 * 全ページがそろっていなくても、撮影済みのページだけ先に出す「分割提出」ができる（小テストは「分割提出中」になり、
 * 先生は提出済みのページから先に採点・添削できる。全ページがそろうと提出済みになる。自己採点は全ページ提出時のみ）。
 */
const props = defineProps<{ quiz: QuizDetail }>()
const emit = defineEmits<{ close: []; submitted: [] }>()

const ui = useUiStore()
const pages = computed(() => props.quiz.pages)

const video = ref<HTMLVideoElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
let stream: MediaStream | null = null
const camReady = ref(false)
const videoLive = ref(false) // <video> が実際に映像を出し始めたか（撮影ボタンの有効化に使う）
const camError = ref('')
const facing = ref<'environment' | 'user'>('environment')
const canSwitch = ref(false)

// 分割提出の続きは最初の未撮影ページから始める
const idx = ref(Math.max(0, props.quiz.pages.findIndex((p) => !p.hasAnswer)))
// 全ページ提出済み（再提出・自己採点の修正）のときは撮影を飛ばして確認画面から始める
const phase = ref<'shoot' | 'review' | 'done' | 'uploading'>(props.quiz.pages.length && props.quiz.pages.every((p) => p.hasAnswer) ? 'done' : 'shoot')
const shots = ref<Map<number, { blob: Blob; url: string }>>(new Map())
const progress = ref({ done: 0, total: 0 })

const current = computed(() => pages.value[idx.value] ?? null)
const currentShot = computed(() => (current.value ? shots.value.get(current.value.id) ?? null : null))
const hasAll = computed(() => pages.value.every((p) => shots.value.has(p.id) || p.hasAnswer))
const newCount = computed(() => shots.value.size)
/** 分割提出で提出されるページ数（新しく撮影したページ＋撮影済みのページ） */
const readyCount = computed(() => pages.value.filter((p) => shots.value.has(p.id) || p.hasAnswer).length)
/** 分割提出できるか: 未撮影のページが残っていて、まだ提出していないページ（新規撮影・撮影済みで未提出）がある */
const canPartial = computed(() => !hasAll.value && pages.value.some((p) => shots.value.has(p.id) || (p.hasAnswer && !p.submitted)))
const isPartialQuiz = computed(() => props.quiz.status === 'partial')

// 自己採点: チェックして得点・満点を入力すると、先生の採点を待たずに採点済みとして登録される
const selfGrade = reactive<{ on: boolean; score: number | null; maxScore: number | null }>({
  on: props.quiz.selfGraded,
  score: props.quiz.selfGraded ? props.quiz.enteredScore : null,
  maxScore: props.quiz.enteredMaxScore ?? props.quiz.defaultMaxScore ?? null,
})
const selfGradeValid = computed(
  () => selfGrade.score !== null && selfGrade.maxScore !== null && selfGrade.maxScore > 0 && selfGrade.score >= 0 && selfGrade.score <= selfGrade.maxScore,
)
const selfRate = computed(() => (selfGradeValid.value ? Math.round((selfGrade.score! / selfGrade.maxScore!) * 100) : null))
// 先生への一言（任意。LINE 通知に含まれる）。再提出時は前回の内容を初期値にする
const submitNote = ref(props.quiz.submitNote ?? '')
// 各問題（ページ）の回答にかかった時間（分）。再提出時は前回の値を初期値にする
const minutes = reactive<Record<number, number | null>>(Object.fromEntries(props.quiz.pages.map((p) => [p.id, p.answerMinutes])))
const timesPayload = computed(() => {
  const out: Record<number, number> = {}
  for (const p of pages.value) {
    const m = minutes[p.id]
    if (typeof m === 'number' && Number.isFinite(m) && m >= 0) out[p.id] = Math.round(m)
  }
  return out
})

/** ページの状態: new=今回撮影 / old=提出済みの写真あり / kept=撮影済みだが未提出（分割提出前にアップロードだけ済んだもの） / none=未撮影 */
type PageState = 'new' | 'old' | 'kept' | 'none'
function stateOf(p: { id: number; hasAnswer: boolean; submitted: boolean }): PageState {
  if (shots.value.has(p.id)) return 'new'
  if (p.hasAnswer) return p.submitted ? 'old' : 'kept'
  return 'none'
}
const STATE_CHIP: Record<PageState, string> = { new: '撮影済', old: '提出済', kept: '未提出', none: '未撮影' }
const STATE_DESC: Record<PageState, string> = { new: '新しく撮影', old: '提出済みの写真を使用', kept: '撮影済みの写真を使用（未提出）', none: '未撮影（タップして撮影）' }

async function startCamera() {
  stopCamera()
  camReady.value = false
  camError.value = ''
  if (!navigator.mediaDevices?.getUserMedia) {
    camError.value = 'このブラウザではカメラを直接起動できません。下の「写真を選ぶ／撮影」から提出してください。'
    return
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: facing.value }, width: { ideal: 2560 }, height: { ideal: 1920 } },
      audio: false,
    })
    const v = video.value
    if (!v) return
    v.srcObject = stream
    v.setAttribute('playsinline', '')
    v.muted = true
    await v.play()
    camReady.value = true
    try {
      const devices = await navigator.mediaDevices.enumerateDevices()
      canSwitch.value = devices.filter((d) => d.kind === 'videoinput').length > 1
    } catch {
      canSwitch.value = false
    }
  } catch (e: unknown) {
    const name = (e as { name?: string })?.name
    camError.value =
      name === 'NotAllowedError'
        ? 'カメラの使用が許可されていません。ブラウザの設定でカメラを許可するか、下の「写真を選ぶ／撮影」から提出してください。'
        : 'カメラを起動できませんでした。下の「写真を選ぶ／撮影」から提出してください。'
  }
}

function stopCamera() {
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
  camReady.value = false
}

/** 撮影画面に戻ったとき（<video> が作り直される）にストリームを再接続する */
async function attachVideo() {
  await nextTick()
  const v = video.value
  if (!v) return
  if (!stream) {
    await startCamera()
    return
  }
  if (v.srcObject !== stream) {
    v.srcObject = stream
    v.setAttribute('playsinline', '')
    v.muted = true
    try {
      await v.play()
    } catch {
      // 自動再生がブロックされた場合は次の操作で再生される
    }
  }
}
watch(phase, (ph) => {
  if (ph === 'shoot') {
    videoLive.value = false
    attachVideo()
  }
})

async function switchCamera() {
  facing.value = facing.value === 'environment' ? 'user' : 'environment'
  await startCamera()
}

/** 長辺 2400px 以内の JPEG にする */
function toJpeg(source: HTMLVideoElement | HTMLImageElement, sw: number, sh: number): Promise<Blob> {
  const long = Math.max(sw, sh)
  const s = long > 2400 ? 2400 / long : 1
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(sw * s)
  canvas.height = Math.round(sh * s)
  const ctx = canvas.getContext('2d')!
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height)
  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('capture failed'))), 'image/jpeg', 0.9))
}

function setShot(pageId: number, blob: Blob) {
  const prev = shots.value.get(pageId)
  if (prev) URL.revokeObjectURL(prev.url)
  const next = new Map(shots.value)
  next.set(pageId, { blob, url: URL.createObjectURL(blob) })
  shots.value = next
  phase.value = 'review'
}

async function capture() {
  const v = video.value
  if (!v || !camReady.value || !current.value) return
  if (!v.videoWidth || !v.videoHeight) {
    ui.notify('カメラの準備中です。少し待ってからもう一度押してください')
    attachVideo()
    return
  }
  try {
    const blob = await toJpeg(v, v.videoWidth, v.videoHeight)
    setShot(current.value.id, blob)
  } catch {
    ui.notify('撮影に失敗しました')
  }
}

function pickFile() {
  fileInput.value?.click()
}
async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  input.value = ''
  if (!f || !current.value) return
  // 端末で撮った写真は大きいので縮小してから保持する
  try {
    const img = new Image()
    const url = URL.createObjectURL(f)
    await new Promise<void>((res, rej) => {
      img.onload = () => res()
      img.onerror = () => rej(new Error('load'))
      img.src = url
    })
    const blob = await toJpeg(img, img.naturalWidth, img.naturalHeight)
    URL.revokeObjectURL(url)
    setShot(current.value.id, blob)
  } catch {
    setShot(current.value.id, f)
  }
}

function retake() {
  phase.value = 'shoot'
}
function next() {
  if (idx.value < pages.value.length - 1) {
    idx.value++
    phase.value = 'shoot'
  } else {
    phase.value = 'done'
  }
}
function goto(i: number) {
  idx.value = i
  phase.value = shots.value.has(pages.value[i]!.id) ? 'review' : 'shoot'
}

/** 提出。partial=true は撮影済みのページだけ先に出す分割提出 */
async function submit(partial = false) {
  if (partial) {
    if (!canPartial.value) {
      ui.notify('先に提出できる撮影済みのページがありません')
      return
    }
  } else if (!hasAll.value) {
    ui.notify('すべてのページを撮影してください')
    return
  }
  const withSelfGrade = selfGrade.on && !partial
  if (withSelfGrade && !selfGradeValid.value) {
    ui.notify(selfGrade.score !== null && selfGrade.maxScore !== null && selfGrade.score > selfGrade.maxScore ? '得点が満点を超えています' : '自己採点の得点と満点を入力してください')
    return
  }
  phase.value = 'uploading'
  progress.value = { done: 0, total: newCount.value }
  try {
    for (const p of pages.value) {
      const s = shots.value.get(p.id)
      if (!s) continue
      await quizApi.uploadAnswer(props.quiz.id, p.id, s.blob)
      progress.value.done++
    }
    const res = await quizApi.submit(props.quiz.id, withSelfGrade ? { score: selfGrade.score!, maxScore: selfGrade.maxScore! } : null, submitNote.value.trim() || null, timesPayload.value, partial)
    if (res.status === 'partial') ui.notify(`${res.submittedPageCount} / ${res.pageCount} ページを先に提出しました（残りは後から提出できます）`)
    else ui.notify(withSelfGrade ? '自己採点つきで提出しました' : '回答を提出しました')
    emit('submitted')
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '提出に失敗しました。通信状況を確認してもう一度お試しください')
    phase.value = 'done'
  }
}

onMounted(() => {
  if (phase.value === 'shoot') startCamera()
})
onBeforeUnmount(() => {
  stopCamera()
  shots.value.forEach((s) => URL.revokeObjectURL(s.url))
})
</script>

<template>
  <div class="cam-overlay ui-fullscreen">
    <div class="cam-top">
      <div style="min-width: 0">
        <div style="font-size: 13.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ quiz.title }}</div>
        <div style="font-size: 11px; color: #b7bcc6">
          回答用紙を撮影して提出（{{ pages.length }}ページ）<template v-if="isPartialQuiz">・分割提出中 {{ quiz.submittedPageCount }}/{{ pages.length }} ページ提出済み</template>
        </div>
      </div>
      <!-- 途中でも確認・提出画面へ（撮影済みのページだけ先に出す分割提出のため） -->
      <button v-if="phase !== 'done' && phase !== 'uploading' && readyCount > 0" class="top-btn" @click="phase = 'done'">確認・提出へ</button>
      <button class="x" @click="emit('close')">×</button>
    </div>

    <!-- ページ一覧 -->
    <div class="strip">
      <button
        v-for="(p, i) in pages"
        :key="p.id"
        class="chip"
        :class="[stateOf(p), { cur: i === idx && phase !== 'done' }]"
        @click="goto(i)"
      >
        <span class="chip-n">{{ i + 1 }}</span>
        <span class="chip-l">{{ p.label }}</span>
        <span class="chip-s">{{ STATE_CHIP[stateOf(p)] }}</span>
      </button>
    </div>

    <!-- 撮影 -->
    <div v-if="phase === 'shoot'" class="stage">
      <div class="view">
        <video ref="video" class="video" autoplay playsinline muted @playing="videoLive = true" @loadeddata="videoLive = true"></video>
        <div v-if="!camReady && !camError" class="msg">カメラを起動しています…</div>
        <div v-if="camError" class="msg err">{{ camError }}</div>
        <div class="guide">
          <div class="guide-cap">ページ {{ idx + 1 }} / {{ pages.length }}<span v-if="current">・{{ current.label }}</span></div>
        </div>
      </div>
      <div class="controls">
        <button class="sub-btn" @click="pickFile">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="10" r="2" /><path d="M21 16l-5-5-8 8" /></svg>
          <span>写真を選ぶ／撮影</span>
        </button>
        <button class="shutter" :disabled="!camReady || !videoLive" title="撮影" @click="capture"><span></span></button>
        <button class="sub-btn" :disabled="!canSwitch" @click="switchCamera">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l2-2h6l2 2h3v12H4z" /><path d="M8.5 13a3.5 3.5 0 0 0 6.5 1.8" /><path d="M15.5 13a3.5 3.5 0 0 0-6.5-1.8" /><path d="M15 15v-2h2" /><path d="M9 11v2H7" /></svg>
          <span>カメラ切替</span>
        </button>
      </div>
      <input ref="fileInput" type="file" accept="image/*" capture="environment" style="display: none" @change="onFile" />
      <div v-if="current?.hasAnswer && !currentShot" class="note">{{ current.submitted ? 'このページは提出済みの写真があります。撮影すると差し替えられます。' : 'このページは撮影済み（未提出）の写真があります。撮影すると差し替えられます。' }}</div>
    </div>

    <!-- 確認 -->
    <div v-else-if="phase === 'review'" class="stage">
      <div class="view">
        <img v-if="currentShot" :src="currentShot.url" class="photo" alt="" />
        <div class="guide">
          <div class="guide-cap">ページ {{ idx + 1 }} / {{ pages.length }}<span v-if="current">・{{ current.label }}</span></div>
        </div>
      </div>
      <div class="controls">
        <button class="btn ghost" @click="retake">撮り直す</button>
        <button class="btn primary" @click="next">{{ idx < pages.length - 1 ? '次のページへ' : '確認へ' }}</button>
      </div>
    </div>

    <!-- 提出前確認 -->
    <div v-else-if="phase === 'done'" class="stage">
      <div class="done-list">
        <div v-for="(p, i) in pages" :key="p.id" class="done-row" @click="goto(i)">
          <div class="done-thumb">
            <img v-if="shots.get(p.id)" :src="shots.get(p.id)!.url" alt="" />
            <AuthImage v-else-if="p.hasAnswer" :src="quizApi.answerImageUrl(quiz.id, p.id, p.answerVersion)" />
            <div v-else class="none">未撮影</div>
          </div>
          <div style="flex: 1; min-width: 0">
            <div style="font-size: 12.5px; font-weight: 600">{{ i + 1 }}. {{ p.label }}</div>
            <div style="font-size: 11px" :style="{ color: stateOf(p) === 'none' ? '#ff9f9f' : '#b7bcc6' }">{{ STATE_DESC[stateOf(p)] }}</div>
            <!-- 回答にかかった時間（分）。目安時間があれば並べて表示 -->
            <label class="time-row" @click.stop>
              <span class="time-lab">かかった時間</span>
              <input v-model.number="minutes[p.id]" type="number" min="0" max="600" inputmode="numeric" placeholder="－" class="time-input" />
              <span class="time-unit">分</span>
              <span v-if="p.guideMinutes" class="time-guide" :class="{ over: (minutes[p.id] ?? 0) > p.guideMinutes }">目安 {{ p.guideMinutes }}分</span>
            </label>
          </div>
          <span style="font-size: 11px; color: #b7bcc6; flex-shrink: 0">撮り直す ›</span>
        </div>
      </div>
      <!-- 先生への一言（任意） -->
      <div class="msg-row">
        <input v-model="submitNote" type="text" maxlength="500" placeholder="先生への一言（任意）" class="msg-input" />
      </div>
      <!-- 自己採点（任意。全ページそろっているときだけ。分割提出では使えない） -->
      <div v-if="hasAll" class="sg">
        <label class="sg-toggle">
          <input v-model="selfGrade.on" type="checkbox" />
          <span>自己採点済み</span>
          <HelpTip text="自分で丸つけした場合はチェックして得点と満点を入力します。&#10;先生の採点・添削を待たずに結果・分析へ反映されます。&#10;先生があとから採点・添削をやり直すこともできます。" />
        </label>
        <div v-if="selfGrade.on" class="sg-inputs">
          <input v-model.number="selfGrade.score" type="number" min="0" inputmode="numeric" placeholder="得点" />
          <span class="sg-slash">/</span>
          <input v-model.number="selfGrade.maxScore" type="number" min="1" inputmode="numeric" placeholder="満点" />
          <span class="sg-unit">点</span>
          <span v-if="selfRate !== null" class="sg-rate" :style="{ background: rateColor(selfRate) }">{{ selfRate }}%</span>
        </div>
      </div>
      <div class="controls">
        <button class="btn ghost" @click="goto(0)">最初から撮り直す</button>
        <button v-if="hasAll" class="btn primary" @click="submit(false)">{{ selfGrade.on ? '自己採点して提出' : '提出する' }}</button>
        <!-- 全ページそろっていないときは、撮影済みのページだけ先に出す分割提出 -->
        <button v-else class="btn primary" :disabled="!canPartial" @click="submit(true)">{{ readyCount }} / {{ pages.length }} ページを先に提出</button>
      </div>
      <div v-if="!hasAll" class="note">
        撮影済みのページだけ先に提出できます（分割提出）。先生は提出済みのページから先に採点・添削できます。残りの {{ pages.length - readyCount }} ページは後から「続きを撮影して提出」で提出し、全ページがそろうと提出済みになります。
      </div>
      <div v-else-if="!selfGrade.on" class="note">{{ quiz.selfCreated ? '提出後、先生が採点・添削するか、一覧の「自分で採点・添削」から自分で採点・添削できます。' : '提出後、先生が採点・添削します。採点・添削が始まる前なら撮り直して再提出できます。' }}</div>
    </div>

    <!-- 送信中 -->
    <div v-else class="stage center">
      <div class="spinner"></div>
      <div style="font-size: 13px">アップロード中… {{ progress.done }} / {{ progress.total }}</div>
    </div>
  </div>
</template>

<style scoped>
/* 先生への一言（提出前確認） */
.msg-row {
  margin: 0 14px 8px;
}
/* 回答にかかった時間 */
.time-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  cursor: default;
}
.time-lab {
  font-size: 11px;
  color: #b7bcc6;
}
.time-input {
  width: 60px;
  height: 32px;
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.3);
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  text-align: center;
  outline: none;
}
.time-input:focus {
  border-color: #5b7cff;
}
.time-unit {
  font-size: 11px;
  color: #b7bcc6;
}
.time-guide {
  margin-left: 4px;
  padding: 1px 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  font-size: 10.5px;
  font-weight: 700;
  color: #e6e8ec;
}
.time-guide.over {
  background: rgba(224, 83, 61, 0.35);
  color: #ffb4a8;
}
.msg-input {
  width: 100%;
  height: 38px;
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.07);
  color: #fff;
  font-size: 13px;
  padding: 0 12px;
  outline: none;
}
.msg-input::placeholder {
  color: #8e95a2;
}
.msg-input:focus {
  border-color: #5b7cff;
}
/* 自己採点（提出前確認） */
.sg {
  margin: 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.07);
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
}
.sg-toggle {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.sg-toggle input {
  width: 17px;
  height: 17px;
  accent-color: #5b7cff;
}
.sg-inputs {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.sg-inputs input {
  width: 64px;
  height: 34px;
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.3);
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  text-align: center;
  outline: none;
}
.sg-inputs input:focus {
  border-color: #5b7cff;
}
.sg-slash {
  font-size: 15px;
  color: #b7bcc6;
}
.sg-unit {
  font-size: 12px;
  color: #b7bcc6;
}
.sg-rate {
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  padding: 3px 8px;
  border-radius: 999px;
  margin-left: 4px;
}
.cam-overlay {
  position: fixed;
  inset: 0;
  background: #14171c;
  color: #fff;
  z-index: 80;
  display: flex;
  flex-direction: column;
  /* ノッチ・ホームバーに×ボタンやシャッターが重ならないようにする */
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
  overscroll-behavior: none;
}
.cam-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px 8px;
}
.top-btn {
  border: none;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  height: 34px;
  padding: 0 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  flex-shrink: 0;
  white-space: nowrap;
}
.x {
  border: none;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  font-size: 20px;
  cursor: pointer;
  flex-shrink: 0;
}
.strip {
  display: flex;
  gap: 6px;
  padding: 4px 14px 8px;
  overflow-x: auto;
  scrollbar-width: none;
  flex-shrink: 0;
}
.strip::-webkit-scrollbar {
  display: none;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  background: rgba(255, 255, 255, 0.06);
  color: #d5d9e0;
  font-size: 11.5px;
  cursor: pointer;
  white-space: nowrap;
  max-width: 220px;
}
.chip.cur {
  border-color: #9fb4ff;
  background: rgba(120, 140, 255, 0.2);
  color: #fff;
}
.chip-n {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  font-size: 10.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chip-l {
  overflow: hidden;
  text-overflow: ellipsis;
}
.chip-s {
  font-size: 10px;
  color: #9aa1ab;
}
.chip.new .chip-s {
  color: #8ee0a8;
}
.chip.old .chip-s {
  color: #9fb4ff;
}
.chip.kept .chip-s {
  color: #f3c175;
}
.stage {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 0 14px 14px;
  gap: 10px;
}
.stage.center {
  align-items: center;
  justify-content: center;
}
.view {
  position: relative;
  flex: 1;
  min-height: 0;
  background: #000;
  border-radius: 14px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.video,
.photo {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.msg {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
  font-size: 13px;
  color: #d5d9e0;
  line-height: 1.7;
}
.msg.err {
  color: #ffb4b4;
}
.guide {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  padding: 10px;
  pointer-events: none;
}
.guide-cap {
  display: inline-block;
  background: rgba(0, 0, 0, 0.55);
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 12px;
}
.controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 18px;
  flex-shrink: 0;
  padding: 4px 0;
}
.shutter {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  border: 4px solid #fff;
  background: transparent;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.shutter span {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #fff;
}
.shutter:disabled {
  opacity: 0.35;
  cursor: default;
}
.sub-btn {
  width: 92px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  border: none;
  background: transparent;
  color: #d5d9e0;
  font-size: 10.5px;
  cursor: pointer;
}
.sub-btn:disabled {
  opacity: 0.3;
  cursor: default;
}
.btn {
  padding: 12px 22px;
  border-radius: 12px;
  border: none;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.btn.primary {
  background: #fff;
  color: #1c2024;
}
.btn.primary:disabled {
  opacity: 0.35;
  cursor: default;
}
.btn.ghost {
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
}
.note {
  font-size: 11.5px;
  color: #b7bcc6;
  text-align: center;
  flex-shrink: 0;
}
.done-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.done-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: rgba(255, 255, 255, 0.06);
  border-radius: 12px;
  cursor: pointer;
}
.done-thumb {
  width: 56px;
  height: 72px;
  border-radius: 6px;
  overflow: hidden;
  background: #000;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.done-thumb img,
.done-thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.none {
  font-size: 10px;
  color: #ff9f9f;
}
.spinner {
  width: 34px;
  height: 34px;
  border: 3px solid rgba(255, 255, 255, 0.2);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 12px;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
