// ブラウザ標準の confirm() / alert() の代わりに使うアプリ内ダイアログ（表示は components/AppDialogHost.vue）。
// 標準のダイアログはホーム画面から起動したときに URL が表示されるなど、アプリらしくないため置き換える。
import { reactive } from 'vue'

export interface DialogOptions {
  /** 太字の見出し（省略時は本文のみ） */
  title?: string
  /** OK ボタンの文言（既定: OK） */
  okText?: string
  /** キャンセルボタンの文言（既定: キャンセル） */
  cancelText?: string
  /** 削除など取り消せない操作のとき true（OK ボタンが赤くなる） */
  danger?: boolean
}

interface DialogRequest extends Required<Omit<DialogOptions, 'title'>> {
  id: number
  kind: 'confirm' | 'alert'
  title: string
  message: string
  resolve: (ok: boolean) => void
}

let seq = 0
const queue: DialogRequest[] = []

export const dialogState = reactive<{ current: DialogRequest | null }>({ current: null })

function open(kind: DialogRequest['kind'], message: string, opts: DialogOptions): Promise<boolean> {
  return new Promise((resolve) => {
    queue.push({
      id: ++seq,
      kind,
      message,
      title: opts.title ?? '',
      okText: opts.okText ?? 'OK',
      cancelText: opts.cancelText ?? 'キャンセル',
      danger: opts.danger ?? false,
      resolve,
    })
    if (!dialogState.current) dialogState.current = queue.shift() ?? null
  })
}

/** 確認ダイアログ。OK なら true */
export function appConfirm(message: string, opts: DialogOptions = {}): Promise<boolean> {
  return open('confirm', message, opts)
}

/** お知らせダイアログ（OK のみ） */
export async function appAlert(message: string, opts: DialogOptions = {}): Promise<void> {
  await open('alert', message, opts)
}

/** AppDialogHost から呼ぶ: 回答して次のダイアログへ */
export function answerDialog(ok: boolean) {
  const cur = dialogState.current
  if (!cur) return
  dialogState.current = null
  cur.resolve(ok)
  if (queue.length) {
    // 閉じるアニメーションの後に次を出す
    window.setTimeout(() => {
      if (!dialogState.current) dialogState.current = queue.shift() ?? null
    }, 220)
  }
}
