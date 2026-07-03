// デザイン共通ユーティリティ

export function hexA(hex: string, a: number): string {
  const h = (hex || '#475569').replace('#', '')
  return `rgba(${parseInt(h.slice(0, 2), 16)},${parseInt(h.slice(2, 4), 16)},${parseInt(h.slice(4, 6), 16)},${a})`
}

export function pct(a: number, b: number): number {
  return b ? Math.round((a / b) * 1000) / 10 : 0
}

export function iso(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function fmtMd(d: Date): string {
  return `${d.getMonth() + 1}/${d.getDate()}`
}

export function parseDate(s: string): Date {
  return new Date(s + 'T00:00:00')
}

export function daysBetween(from: Date, to: Date): number {
  return Math.round((to.getTime() - from.getTime()) / 86400000)
}

// ナビ項目アイコン（SVG path 群）
export const ICONS: Record<string, string[]> = {
  home: ['M3 10.5 12 4l9 6.5', 'M5 9.5V20h14V9.5', 'M9.5 20v-5h5v5'],
  data: ['M4 5.5h16v14H4z', 'M4 10h16', 'M9.5 10v9.5'],
  record: ['M4 6h16v14H4z', 'M4 10h16', 'M8 4v3.5', 'M16 4v3.5', 'M8.5 15l2.2 2.2L15 13'],
  goal: [
    'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
    'M12 16.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9z',
    'M12 13.2a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4z',
  ],
  settings: ['M4 7h9', 'M17 7h3', 'M14 5v4', 'M4 12h3', 'M11 12h9', 'M8 10v4', 'M4 17h11', 'M19 17h1', 'M16 15v4'],
  quiz: ['M4 5.5h13a1.5 1.5 0 0 1 1.5 1.5v12.5H5.5A1.5 1.5 0 0 1 4 18z', 'M18.5 7v12.5', 'M8 9.5h7', 'M8 13h5'],
  resource: ['M4 5h7v15H4z', 'M13 5h7v15h-7z', 'M7 9h1.5', 'M16 9h1.5', 'M7 13h1.5', 'M16 13h1.5'],
}

export interface NavDef {
  key: string
  route: string
  label: string
  short: string
}

export const NAV: NavDef[] = [
  { key: 'home', route: 'home', label: 'トップページ', short: 'トップ' },
  { key: 'data', route: 'data', label: '学習項目データ', short: '項目' },
  { key: 'resource', route: 'resource', label: '個別学習一覧データ', short: '一覧' },
  { key: 'record', route: 'record', label: '学習記録', short: '記録' },
  { key: 'quiz', route: 'quiz', label: '英単語クイズ', short: '単語' },
  { key: 'goal', route: 'goals', label: '目標設定', short: '目標' },
  { key: 'settings', route: 'settings', label: 'システム設定', short: '設定' },
]

// 種別バッジの配色
export const TYPE_BADGE = {
  講義: { bg: '#eef1f6', fg: '#5b6b8c' },
  問題集: { bg: '#fdeef4', fg: '#b85188' },
  教科書: { bg: '#eef7f0', fg: '#3a8a5c' },
} as const

// TTS（女性音声優先）
export function speak(text: string, lang = 'en-US') {
  try {
    if (typeof window !== 'undefined' && window.speechSynthesis) {
      const u = new SpeechSynthesisUtterance(text)
      u.lang = lang
      u.rate = 0.95
      const vs = window.speechSynthesis.getVoices()
      const f = vs.find((v) => /female|samantha|google us english|zira/i.test(v.name))
      if (f) u.voice = f
      window.speechSynthesis.cancel()
      window.speechSynthesis.speak(u)
    }
  } catch {
    // ignore
  }
}

export function shuffle<T>(arr: T[]): T[] {
  const a = arr.slice()
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[a[i], a[j]] = [a[j], a[i]]
  }
  return a
}
